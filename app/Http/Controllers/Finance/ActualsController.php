<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsExpenses;
use App\Http\Requests\Finance\ImportActualsRequest;
use App\Models\Finance\Actual;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Contract;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\MatchRule;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\ActualsService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\ReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The actuals ledger, GL import, reconciliation queue, match rules and saved column mappings (FIN-03.05, .15). */
class ActualsController extends Controller
{
    use PresentsExpenses;

    public const TABS = ['posted', 'queue', 'rules', 'mappings'];

    public function __construct(
        private readonly ActualsService $actuals,
        private readonly ReconciliationService $recon,
        private readonly FinanceSettings $settings,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        Gate::authorize('viewAny', Actual::class);
        $orgId = (int) $user->organization_id;
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'posted';
        $fiscalYears = FiscalYear::query()->orderByDesc('starts_on')->get(['id', 'year_label', 'is_current', 'status']);
        $fy = $request->integer('fiscal_year_id') ?: ($fiscalYears->firstWhere('is_current', true)?->id ?? $fiscalYears->first()?->id);
        $filters = [
            'fiscal_year_id' => $fy, 'period_id' => $request->integer('period_id') ?: null, 'cost_centre_id' => $request->integer('cost_centre_id') ?: null,
            'source' => in_array($request->query('source'), Actual::SOURCES, true) ? $request->query('source') : null,
            'match_status' => in_array($request->query('match_status'), Actual::MATCH_STATUSES, true) ? $request->query('match_status') : null,
            'q' => trim((string) $request->query('q', '')) ?: null, 'batch_id' => $request->integer('batch_id') ?: null,
        ];
        $visible = FinancePolicy::visibleCostCentreIds($user);
        $scope = fn () => Actual::query()->when($visible !== null, fn ($q) => $q->whereIn('cost_centre_id', $visible ?: [0]))->when($fy, fn ($q) => $q->where('fiscal_year_id', $fy));
        $rows = $scope()->with(['period', 'costCentre:id,code,name', 'glAccount:id,code,name', 'budgetLine', 'vendor:id,name', 'contract:id,contract_code,title', 'invoice:id,invoice_code'])
            ->when($filters['period_id'], fn ($q, $v) => $q->where('period_id', $v))->when($filters['cost_centre_id'], fn ($q, $v) => $q->where('cost_centre_id', $v))
            ->when($filters['source'], fn ($q, $v) => $q->where('source', $v))->when($filters['match_status'], fn ($q, $v) => $q->where('match_status', $v))
            ->when($filters['batch_id'], fn ($q, $v) => $q->where('import_batch_id', $v))
            ->when($filters['q'], fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw('lower(description) like ?', ['%'.mb_strtolower($v).'%'])->orWhereRaw('lower(source_ref) like ?', ['%'.mb_strtolower($v).'%'])))
            ->orderByDesc('posted_on')->orderByDesc('id')->limit(501)->get();
        $truncated = $rows->count() > 500;
        $rows = $rows->take(500);
        $this->primeLines($rows->pluck('budget_line_id'));
        $this->primeUsers($rows->pluck('matched_by_id'));

        $counted = $scope()->where('match_status', '!=', 'duplicate');
        $unmatched = $scope()->where('match_status', 'unmatched');
        $bySource = (clone $counted)->selectRaw('source, sum(base_amount) as total')->groupBy('source')->pluck('total', 'source')->map(fn ($v) => bcadd((string) $v, '0', 2))->all();
        $queue = $tab === 'queue' || $request->boolean('with_queue')
            ? $this->recon->queue($orgId, ['fiscal_year_id' => $fy, 'period_id' => $filters['period_id'], 'batch_id' => $filters['batch_id']])->when($visible !== null, fn ($q) => $q->whereIn('cost_centre_id', $visible ?: [0]))->limit(500)->get()
            : collect();
        $this->primeLines($queue->pluck('budget_line_id'));
        $this->primeUsers($queue->pluck('matched_by_id'));

        return $this->renderExpenses('Finance/Actuals/Index', [
            'tab' => $tab, 'filters' => $filters, 'actuals' => $rows->map(fn ($a) => $this->actualRow($a))->values(), 'truncated' => $truncated,
            'summary' => [
                'posted_base' => bcadd((string) (clone $counted)->sum('base_amount'), '0', 2), 'unmatched_count' => (clone $unmatched)->count(), 'unmatched_base' => bcadd((string) (clone $unmatched)->sum('base_amount'), '0', 2),
                'duplicates' => $scope()->where('match_status', 'duplicate')->count(), 'unbudgeted_count' => $scope()->where('match_status', 'unbudgeted')->count(), 'by_source' => $bySource,
            ],
            'queue' => $queue->map(fn ($a) => $this->actualRow($a) + ['reason' => $a->match_reason])->values(),
            'rules' => MatchRule::query()->with(['costCentre:id,code,name', 'glAccount:id,code,name', 'vendor:id,name', 'contract:id,contract_code,title', 'budgetLine'])->orderBy('priority')->orderBy('id')->get()
                ->tap(fn ($r) => $this->primeLines($r->pluck('budget_line_id')))->map(fn ($r) => [
                    'id' => $r->id, 'name' => $r->name, 'priority' => $r->priority, 'cost_centre' => $this->ccMini($r->costCentre), 'gl_account' => $this->codeMini($r->glAccount), 'vendor' => $this->vendorMini($r->vendor),
                    'contract' => $this->contractMini($r->contract), 'description_contains' => $r->description_contains, 'budget_line' => $this->lineMini($r->budgetLine), 'is_active' => $r->is_active,
                    'hits' => $r->hits, 'last_hit_at' => $r->last_hit_at?->toIso8601String(),
                ])->values(),
            'mappings' => ImportMapping::query()->orderBy('name')->get()->map(fn ($m) => $m->only(['id', 'name', 'type', 'columns', 'delimiter', 'date_format', 'decimal_comma', 'has_header', 'default_currency', 'is_default']))->values(),
            'batches' => ImportBatch::query()->where('type', 'actuals')->with('importedBy:id,name')->orderByDesc('id')->limit(20)->get()->map(fn ($b) => $this->batchRow($b))->values(),
            'periods' => Period::query()->where('is_adjustment', false)->orderBy('starts_on')->get()->map(fn ($p) => $this->periodMini($p))->values(),
            'fiscalYears' => $fiscalYears->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label, 'status' => $f->status])->values(),
            'costCentres' => $this->costCentreOptions($user), 'glAccounts' => $this->glOptions(), 'vendors' => $this->vendorOptions(),
            'contracts' => Contract::query()->visibleTo($user)->orderBy('contract_code')->get()->map(fn ($c) => $this->contractMini($c))->values(), 'budgetLines' => $this->budgetLineOptions($user),
            'mappingKeys' => ['required' => ImportMapping::REQUIRED, 'optional' => ImportMapping::OPTIONAL], 'dateFormats' => config('finance.actuals.date_formats'),
            'can' => ['import' => Gate::forUser($user)->allows('importActuals', Actual::class), 'match' => $user->can('import it-finance')],
        ]);
    }

    public function candidates(Request $request, Actual $actual)
    {
        Gate::authorize('view', $actual);
        Gate::authorize('importActuals', Actual::class);

        return response()->json(['candidates' => $this->recon->candidates($actual)]);
    }

    public function import(ImportActualsRequest $request)
    {
        Gate::authorize('importActuals', Actual::class);
        $user = $request->user();
        $d = $request->validated();
        $mapping = $this->resolveMapping($request);
        if (empty($d['mapping_id']) && ! empty($d['save_mapping_as'])) {
            $mapping = $this->storeMapping(['name' => $d['save_mapping_as']] + $this->mappingInput($request), $user);
        }
        if ($d['mode'] === 'preview') {
            $report = $this->actuals->previewGl($request->file('file'), $mapping, (int) $user->organization_id);

            return back()->with('success', 'Preview ready: nothing was posted.')->with('import_report', $report);
        }
        $batch = $this->actuals->importGl($request->file('file'), $mapping, $user, $request->boolean('skip_invalid'), $request->boolean('force'));
        $report = $batch->report ?? [];
        if ($batch->status === 'failed') {
            return back()->with('error', 'The file was rejected and nothing was posted. Fix the listed rows or import with "skip invalid rows".')->with('import_report', $report);
        }

        return redirect()->route('finance.imports.show', $batch)->with('success', "Import complete: {$batch->accepted_count} accepted, {$batch->rejected_count} rejected, {$batch->unmatched_count} to reconcile.")->with('import_report', $report);
    }

    public function autoMatch(Request $request)
    {
        Gate::authorize('importActuals', Actual::class);
        $n = $this->recon->runQueue((int) $request->user()->organization_id, $request->user());

        return back()->with('success', "{$n} unmatched actuals matched to a budget line.");
    }

    public function match(Request $request, Actual $actual)
    {
        Gate::authorize('view', $actual);
        Gate::authorize('importActuals', Actual::class);
        $d = $request->validate(['budget_line_id' => ['required', 'integer'], 'remember' => ['nullable', 'boolean']]);
        $line = BudgetLine::query()->findOrFail($d['budget_line_id']);
        $this->actuals->match($actual, $line, $request->user(), $request->boolean('remember'));

        return back()->with('success', 'Actual matched to the budget line.');
    }

    public function unmatch(Request $request, Actual $actual)
    {
        Gate::authorize('view', $actual);
        Gate::authorize('importActuals', Actual::class);
        $this->actuals->unmatch($actual, $request->user());

        return back()->with('success', 'Actual returned to the unmatched queue.');
    }

    public function unbudgeted(Request $request, Actual $actual)
    {
        Gate::authorize('view', $actual);
        Gate::authorize('importActuals', Actual::class);
        $this->actuals->acknowledgeUnbudgeted($actual, $request->user(), (string) $request->validate(['note' => ['required', 'string', 'max:2000']])['note']);

        return back()->with('success', 'Acknowledged as unbudgeted spend.');
    }

    public function mappingStore(Request $request)
    {
        Gate::authorize('importActuals', Actual::class);
        $this->storeMapping($request->validate(['name' => ['required', 'string', 'max:120']]) + $this->mappingInput($request), $request->user());

        return back()->with('success', 'Mapping saved.');
    }

    public function mappingDestroy(Request $request, ImportMapping $mapping)
    {
        Gate::authorize('importActuals', Actual::class);
        $mapping->delete();

        return back()->with('success', 'Mapping deleted.');
    }

    public function ruleStore(Request $request)
    {
        Gate::authorize('importActuals', Actual::class);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'priority' => ['nullable', 'integer', 'between:1,9999'], 'cost_centre_id' => ['nullable', 'integer'], 'gl_account_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'integer'], 'description_contains' => ['nullable', 'string', 'max:120'], 'budget_line_id' => ['required', 'integer'], 'is_active' => ['nullable', 'boolean'],
        ]);
        $this->recon->saveRule($d, $request->user(), $request->filled('id') ? MatchRule::query()->findOrFail($request->integer('id')) : null);

        return back()->with('success', 'Rule saved.');
    }

    public function ruleDestroy(Request $request, MatchRule $rule)
    {
        Gate::authorize('importActuals', Actual::class);
        $this->recon->deleteRule($rule, $request->user());

        return back()->with('success', 'Rule deleted.');
    }

    /** @return ImportMapping|array<string,mixed> */
    private function resolveMapping(ImportActualsRequest $request): ImportMapping|array
    {
        if ($request->filled('mapping_id')) {
            return ImportMapping::query()->findOrFail($request->integer('mapping_id'));
        }

        return $this->mappingInput($request);
    }

    /** @return array<string,mixed> */
    private function mappingInput(Request $request): array
    {
        return [
            'columns' => array_filter((array) $request->input('columns', [])), 'delimiter' => $request->input('delimiter', ','), 'date_format' => $request->input('date_format', 'Y-m-d'),
            'decimal_comma' => $request->boolean('decimal_comma'), 'has_header' => $request->has('has_header') ? $request->boolean('has_header') : true, 'default_currency' => $request->input('default_currency'),
        ];
    }

    /** @param array<string,mixed> $d */
    private function storeMapping(array $d, User $by): ImportMapping
    {
        foreach (ImportMapping::REQUIRED as $k) {
            empty($d['columns'][$k]) && throw ValidationException::withMessages(['columns' => "Map the \"{$k}\" column."]);
        }
        in_array($d['date_format'] ?? 'Y-m-d', config('finance.actuals.date_formats'), true) || throw ValidationException::withMessages(['date_format' => 'Unsupported date format.']);
        $orgId = (int) $by->organization_id;
        ImportMapping::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'actuals')->where('name', $d['name'])->exists()
            && throw ValidationException::withMessages(['name' => 'A mapping with that name already exists.']);

        return ImportMapping::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'name' => $d['name'], 'type' => 'actuals', 'columns' => $d['columns'], 'delimiter' => $d['delimiter'] ?? ',',
            'date_format' => $d['date_format'] ?? 'Y-m-d', 'decimal_comma' => (bool) ($d['decimal_comma'] ?? false), 'has_header' => (bool) ($d['has_header'] ?? true),
            'default_currency' => $d['default_currency'] ?? null,
        ]);
    }
}
