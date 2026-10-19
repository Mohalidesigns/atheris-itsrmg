<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Finance\Concerns\PresentsFinOps;
use App\Http\Requests\Finance\ClassifyLinesRequest;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Allocation;
use App\Services\Finance\ApplicationTcoService;
use App\Services\Finance\BudgetLineClassifier;
use App\Services\Finance\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Application total cost of ownership from the finance ledger (FIN-05.10) and the tagging of budget lines to applications and services. */
class TcoController extends Controller
{
    use PresentsFinOps;

    public function __construct(private readonly ApplicationTcoService $tco, private readonly BudgetLineClassifier $classifier, private readonly MonitoringService $monitoring) {}

    public function index(Request $request)
    {
        Gate::authorize('viewAny', Allocation::class);
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $ccIds = $this->visibleCcIds($user);
        $v = $request->validate([
            'fiscal_year_id' => ['nullable', 'integer'], 'subject' => ['nullable', 'in:ea_application,business_service'], 'basis' => ['nullable', 'in:actual,budget'], 'search' => ['nullable', 'string', 'max:100'],
        ]);
        $fy = $this->monitoring->resolveFiscalYear($orgId, ! empty($v['fiscal_year_id']) ? (int) $v['fiscal_year_id'] : null);
        $available = [
            'ea_application' => EaApplication::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->exists(),
            'business_service' => BusinessService::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->exists(),
        ];
        $subject = $v['subject'] ?? ($available['ea_application'] ? 'ea_application' : 'business_service');
        $basis = $v['basis'] ?? 'actual';

        $p = $fy ? $this->tco->portfolio($orgId, $fy->id, $subject, $basis, $ccIds, $user, $v['search'] ?? null)
            : ['rows' => [], 'totals' => ['tco_total' => '0.00', 'it_total' => '0.00', 'coverage_pct' => '0.00', 'unattributed' => '0.00'], 'subjectAvailable' => $available];
        $model = $subject === 'ea_application' ? EaApplication::class : BusinessService::class;
        $subjects = $model::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($m) => ['id' => (int) $m->id, 'label' => trim(($m->code ? $m->code.' ' : '').$m->name)])->values();
        $attributing = $request->boolean('unattributed') || $request->boolean('filters.unattributed');

        return $this->renderFinOps('Finance/Monitor/Tco', [
            'filters' => ['fiscal_year_id' => $fy?->id, 'subject' => $subject, 'basis' => $basis, 'search' => $v['search'] ?? ''], 'subjectAvailable' => $p['subjectAvailable'] + $available,
            'subjects' => $subjects, 'rows' => $p['rows'], 'totals' => $p['totals'],
            'rationalisation' => $fy && $subject === 'ea_application' ? $this->tco->rationalisation($orgId, $fy->id, $ccIds) : [],
            'unattributed' => $fy && $attributing ? $this->classifier->unattributed($orgId, $fy->id, $subject, $ccIds, $v['search'] ?? null) : [],
            'fiscalYears' => collect($this->finFiscalYears($orgId))->map(fn ($f) => ['id' => $f['id'], 'year_label' => $f['year_label']])->values(),
            'can' => ['classify' => $user->can('edit it-finance')],
        ]);
    }

    /** JSON for the detail drawer; amounts only. */
    public function detail(Request $request)
    {
        Gate::authorize('viewAny', Allocation::class);
        $user = $request->user();
        abort_unless($user->can('view it-finance-amounts'), 403);
        $v = $request->validate(['subject' => ['required', 'in:ea_application,business_service'], 'id' => ['required', 'integer'], 'fiscal_year_id' => ['nullable', 'integer'], 'basis' => ['nullable', 'in:actual,budget']]);
        $orgId = (int) $user->organization_id;
        $fy = $this->monitoring->resolveFiscalYear($orgId, ! empty($v['fiscal_year_id']) ? (int) $v['fiscal_year_id'] : null);
        abort_unless($fy, 404);
        $d = $this->tco->detail($orgId, $fy->id, $v['subject'], (int) $v['id'], $v['basis'] ?? 'actual', $this->visibleCcIds($user));

        return response()->json(['subject' => $v['subject'], 'id' => (int) $v['id'], 'label' => $d['label'], 'components' => $d['components']]);
    }

    public function classify(ClassifyLinesRequest $request)
    {
        $d = $request->validated();
        $n = $this->classifier->classify($d['line_ids'], array_intersect_key($d, array_flip(['ea_application_id', 'business_service_id'])), $request->user());

        return back()->with('success', $n === 1 ? '1 budget line classified.' : "{$n} budget lines classified.");
    }
}
