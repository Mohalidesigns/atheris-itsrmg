<?php

namespace App\Services\Finance;

use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetComment;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\BudgetScenario;
use App\Models\Finance\BudgetTarget;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\Driver;
use App\Models\Finance\DriverValue;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\LegalEntity;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Budget versions, lines, phasing, scenarios and the draft - submit - approve - baseline lifecycle
 * (ATH-FIN-001 §3.2, §5.1). Approval runs through Workflow Studio via FinanceApprovals; segregation
 * of duties is enforced here as well as in the engine.
 */
class BudgetService
{
    use LogsFinanceEvents;

    public const TRANSITIONS = [
        'submit' => ['from' => ['draft', 'rejected'], 'to' => 'submitted'],
        'withdraw' => ['from' => ['submitted', 'under_review'], 'to' => 'draft'],
        'approve' => ['from' => ['submitted', 'under_review'], 'to' => 'approved'],
        'return' => ['from' => ['submitted', 'under_review'], 'to' => 'draft'],
        'reject' => ['from' => ['submitted', 'under_review'], 'to' => 'rejected'],
        'baseline' => ['from' => ['approved'], 'to' => 'baseline'],
        'revise' => ['from' => ['approved', 'baseline'], 'to' => 'draft'],
    ];

    private const PRIORITY_ORDER = ['mandatory' => 0, 'regulatory' => 1, 'committed' => 2, 'discretionary' => 3];

    private const LINE_FIELDS = [
        'description', 'cost_pool_id', 'tower_id', 'category_id', 'gl_account_id', 'legal_entity_id', 'is_capex', 'is_recurring', 'priority', 'rgt',
        'vendor_id', 'contract_id', 'asset_id', 'ea_application_id', 'business_service_id', 'investment_id', 'risk_id', 'driver_id', 'driver_rate',
        'quantity', 'unit_cost', 'currency', 'annual_amount', 'phasing_method', 'justification', 'variance_amber_pct', 'variance_red_pct', 'sort_order',
    ];

    public function __construct(
        private readonly CurrencyService $fx,
        private readonly BudgetPhasing $phasing,
        private readonly FinanceApprovals $approvals,
        private readonly FinanceSettings $settings,
        private readonly BudgetLedger $ledger,
        private readonly BudgetCycleService $cycles,
    ) {}

    /* ------------------------------------------------------------------ versions */

    public function createVersion(BudgetCycle $cycle, CostCentre $cc, string $mode, User $by, array $attrs = []): Budget
    {
        $orgId = (int) $cycle->organization_id;
        if ($cycle->status !== 'open') {
            $this->fail('Budgets can only be started in an open cycle.', 'cycle_id');
        }
        if ((int) $cc->organization_id !== $orgId) {
            $this->fail('Unknown cost centre.', 'cost_centre_id');
        }
        if (! in_array($mode, Budget::MODES, true)) {
            $this->fail('Unknown budgeting mode.', 'mode');
        }
        $open = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('cycle_id', $cycle->id)->where('cost_centre_id', $cc->id)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'approved'])->exists();
        if ($open) {
            $this->fail('This cost centre already has an open budget version in the cycle.', 'cost_centre_id');
        }
        $owner = $attrs['owner_id'] ?? $cc->owner_id;
        if ($owner && ! User::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($owner)->exists()) {
            $this->fail('Pick an owner from this bank.', 'owner_id');
        }
        $fy = FiscalYear::withoutGlobalScopes()->findOrFail($cycle->fiscal_year_id);

        $source = null;
        $pct = null;
        if ($mode === 'from_actuals') { // C26: the prior year's baseline lines re-budgeted at their posted actuals
            $source = isset($attrs['source_budget_id'])
                ? Budget::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($attrs['source_budget_id'])->first()
                : $this->priorBaseline($cc->id, $fy, $orgId);
            if (! $source || $source->status !== 'baseline' || (int) $source->cost_centre_id !== (int) $cc->id || (int) $source->fiscal_year_id === (int) $fy->id) {
                $this->fail('Budgeting from actuals needs the prior year\'s baseline for this cost centre.', 'source_budget_id');
            }
            $actuals = app(ActualsService::class);
            $has = $source->lines()->get()->contains(fn (BudgetLine $old) => $actuals->priorYearActual($old) !== null);
            $has || $this->fail('The prior fiscal year has no posted actuals for this cost centre to budget from.', 'mode');
        }
        if ($mode === 'incremental') {
            $pct = $attrs['increment_pct'] ?? null;
            if ($pct === null || ! is_numeric($pct) || $pct < -100 || $pct > 100) {
                $this->fail('Give an increment between -100 and +100 per cent.', 'increment_pct');
            }
            $source = isset($attrs['source_budget_id'])
                ? Budget::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($attrs['source_budget_id'])->first()
                : $this->priorBaseline($cc->id, $fy, $orgId);
            if (! $source || $source->status !== 'baseline' || (int) $source->cost_centre_id !== (int) $cc->id) {
                $this->fail('Incremental budgeting needs the prior year\'s baseline for this cost centre.', 'source_budget_id');
            }
            if ((int) $source->fiscal_year_id === (int) $fy->id) {
                $this->fail('The source baseline must belong to an earlier fiscal year.', 'source_budget_id');
            }
        }

        return DB::transaction(function () use ($cycle, $cc, $mode, $by, $attrs, $orgId, $fy, $owner, $source, $pct) {
            $budget = Budget::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'budget_code' => Budget::generateNextCode($orgId),
                'cycle_id' => $cycle->id, 'fiscal_year_id' => $fy->id, 'cost_centre_id' => $cc->id,
                'legal_entity_id' => $attrs['legal_entity_id'] ?? $cc->legal_entity_id, 'version_no' => 1,
                'source_budget_id' => $source?->id, 'increment_pct' => $pct,
                'title' => $attrs['title'] ?? "{$fy->year_label} IT budget: {$cc->name}", 'mode' => $mode, 'status' => 'draft', 'owner_id' => $owner,
                'executive_summary' => $attrs['executive_summary'] ?? null,
            ]);
            if ($source && $mode === 'from_actuals') {
                $this->cloneFromActuals($budget, $source, $fy, $by);
            } elseif ($source) {
                $this->cloneIncremental($budget, $source, (string) $pct, $fy, $by);
            }
            $this->recalculate($budget);
            $this->audit($budget, $by, 'created', "Budget {$budget->budget_code} v1 created ({$mode})");

            return $budget->refresh();
        });
    }

    public function priorBaseline(int $ccId, FiscalYear $fy, int $orgId): ?Budget
    {
        $prior = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('ends_on', '<', $fy->starts_on->toDateString())
            ->orderByDesc('ends_on')->first();

        return $prior ? Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('baseline_slot', "{$prior->id}:{$ccId}")->first() : null;
    }

    private function cloneIncremental(Budget $budget, Budget $source, string $pct, FiscalYear $fy, User $by): void
    {
        $factor = bcadd('1', bcdiv($pct, '100', 8), 8);
        $periods = $this->phasing->periodsFor($fy);
        $n = $periods->count();
        foreach ($source->lines()->get() as $old) {
            $oldAmounts = BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $old->id)->orderBy('period_id')->get();
            $weights = array_slice(array_pad($oldAmounts->pluck('amount')->map(fn ($a) => (string) $a)->all(), $n, '0'), 0, $n);
            $annual = $this->phasing->round(bcmul(bcdiv((string) $old->current_base_amount, (string) ($old->fx_rate ?: 1), 12), $factor, 12), 2);
            $attrs = Arr::only($old->getAttributes(), self::LINE_FIELDS);
            $attrs['annual_amount'] = $annual;
            $attrs['unit_cost'] = null;
            $attrs['driver_id'] = null;
            $attrs['driver_rate'] = null;
            $attrs['phasing_method'] = $old->phasing_method === 'custom' ? 'custom' : $old->phasing_method;
            $line = $this->saveLine($budget, null, $attrs + ['source_line_id' => $old->id, 'amounts' => $old->phasing_method === 'custom' ? $this->scaleAmounts($periods, $weights, $annual) : null], $by, false);
            $line->forceFill(['py_budget' => $old->current_base_amount, 'source_line_id' => $old->id, 'origin' => 'planned'])->save();
        }
    }

    /** C26: one line per prior-year line that has posted actuals, budgeted at the (annualised) actual and phased like the prior line. */
    private function cloneFromActuals(Budget $budget, Budget $source, FiscalYear $fy, User $by): void
    {
        $actuals = app(ActualsService::class);
        $periods = $this->phasing->periodsFor($fy);
        $n = $periods->count();
        foreach ($source->lines()->get() as $old) {
            $actual = $actuals->priorYearActual($old);
            if ($actual === null) {
                continue;
            }
            $oldAmounts = BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $old->id)->orderBy('period_id')->get();
            $weights = array_slice(array_pad($oldAmounts->pluck('amount')->map(fn ($a) => (string) $a)->all(), $n, '0'), 0, $n);
            $annual = $this->phasing->round(bcdiv($actual, (string) ($old->fx_rate ?: 1), 12), 2);
            $attrs = Arr::only($old->getAttributes(), self::LINE_FIELDS);
            $attrs['annual_amount'] = $annual;
            $attrs['unit_cost'] = null;
            $attrs['driver_id'] = null;
            $attrs['driver_rate'] = null;
            $attrs['phasing_method'] = 'custom';
            $line = $this->saveLine($budget, null, $attrs + ['source_line_id' => $old->id, 'amounts' => $this->scaleAmounts($periods, $weights, $annual)], $by, false);
            $line->forceFill(['py_budget' => $old->current_base_amount, 'py_actual' => $actual, 'source_line_id' => $old->id, 'origin' => 'planned'])->save();
        }
    }

    /** @return array<int, string> period_id => amount, scaled so the amounts sum to $annual */
    private function scaleAmounts(Collection $periods, array $weights, string $annual): array
    {
        $spread = $this->phasing->spread($annual, $weights, 2);

        return $periods->values()->mapWithKeys(fn ($p, $i) => [$p->id => $spread[$i]])->all();
    }

    public function revise(Budget $b, User $by): Budget
    {
        $this->assertTransition($b, 'revise');
        $open = Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('budget_code', $b->budget_code)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'rejected'])->exists();
        if ($open) {
            $this->fail('A newer version of this budget is already being prepared or approved.');
        }

        return DB::transaction(function () use ($b, $by) {
            $next = (int) Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('budget_code', $b->budget_code)->max('version_no') + 1;
            $new = Budget::withoutGlobalScopes()->create([
                'organization_id' => $b->organization_id, 'created_by_id' => $by->id, 'budget_code' => $b->budget_code, 'version_no' => $next,
                'previous_version_id' => $b->id, 'cycle_id' => $b->cycle_id, 'fiscal_year_id' => $b->fiscal_year_id, 'cost_centre_id' => $b->cost_centre_id,
                'legal_entity_id' => $b->legal_entity_id, 'source_budget_id' => $b->source_budget_id, 'increment_pct' => $b->increment_pct,
                'title' => $b->title, 'mode' => $b->mode, 'status' => 'draft', 'owner_id' => $b->owner_id,
                'executive_summary' => $b->executive_summary, 'assumptions' => $b->assumptions, 'risks_summary' => $b->risks_summary,
            ]);
            foreach (BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->orderBy('sort_order')->orderBy('line_no')->get() as $old) {
                $this->cloneLine($old, $new);
            }
            $this->recalculate($new);
            $this->audit($new, $by, 'revised', "Budget {$new->budget_code} revised to v{$next}", ['from_version' => $b->version_no, 'to_version' => $next]);

            return $new->refresh();
        });
    }

    /** Copies a line and its phasing; approved transfers / top-ups are folded into the new version's amounts. */
    private function cloneLine(BudgetLine $old, Budget $new): BudgetLine
    {
        $attrs = $old->getAttributes();
        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
        $line = BudgetLine::withoutGlobalScopes()->create(array_merge($attrs, [
            'budget_id' => $new->id, 'source_line_id' => $old->id, 'created_by_id' => $new->created_by_id,
        ]));
        $current = $this->ledger->currentByPeriod($old);
        $currentLine = $this->ledger->currentByPeriodLine($old);
        $fold = BudgetAdjustment::withoutGlobalScopes()->where('budget_line_id', $old->id)->exists();
        $annual = '0';
        $base = '0';
        foreach (BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $old->id)->get() as $p) {
            $pb = $current[$p->period_id] ?? (string) $p->base_amount;
            $pa = $fold ? $currentLine[$p->period_id] ?? (string) $p->amount : (string) $p->amount;
            $annual = bcadd($annual, $pa, 2);
            $base = bcadd($base, $pb, 2);
            BudgetLinePeriod::withoutGlobalScopes()->create(['organization_id' => $new->organization_id, 'created_by_id' => $new->created_by_id,
                'budget_line_id' => $line->id, 'period_id' => $p->period_id, 'amount' => $pa, 'base_amount' => $pb]);
        }
        if ($fold) {
            $line->forceFill(['annual_amount' => $annual, 'base_annual_amount' => $base, 'phasing_method' => 'custom', 'unit_cost' => null, 'driver_id' => null, 'driver_rate' => null])->save();
        }

        return $line;
    }

    /**
     * Applies an edit to a locked version by working on its successor: reuses the open draft of the
     * budget when there is one, else creates it. The callback receives the editable draft.
     */
    public function editLocked(Budget $b, User $by, callable $edit): Budget
    {
        $draft = Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('budget_code', $b->budget_code)
            ->whereIn('status', Budget::EDITABLE)->orderByDesc('version_no')->first() ?? $this->revise($b, $by);
        $edit($draft);

        return $draft->refresh();
    }

    /** The line of the current baseline budget that is, or descends from, $line; null when the lineage has no baseline. */
    public function resolveBaselineLine(BudgetLine $line): ?BudgetLine
    {
        for ($hops = 0; $hops < 25; $hops++) {
            $budget = Budget::withoutGlobalScopes()->find($line->budget_id);
            if (! $budget) {
                return null;
            }
            if ($budget->status === 'baseline') {
                return $line;
            }
            if (! $budget->superseded_by_id) {
                return null;
            }
            $next = BudgetLine::withoutGlobalScopes()->where('budget_id', $budget->superseded_by_id)->where('source_line_id', $line->id)->first();
            if (! $next) {
                return null;
            }
            $line = $next;
        }

        return null;
    }

    /** The line of $draft that descends from $old (or $old itself when it already belongs to $draft). */
    public function matchingLine(Budget $draft, BudgetLine $old): BudgetLine
    {
        if ((int) $old->budget_id === (int) $draft->id) {
            return $old;
        }

        return BudgetLine::withoutGlobalScopes()->where('budget_id', $draft->id)->where('source_line_id', $old->id)->firstOrFail();
    }

    /* ------------------------------------------------------------------ lines */

    public function addLine(Budget $b, array $data, User $by): BudgetLine
    {
        $this->assertEditable($b);

        return DB::transaction(function () use ($b, $data, $by) {
            $line = $this->saveLine($b, null, $data, $by);
            $this->recalculate($b);

            return $line->refresh();
        });
    }

    public function updateLine(BudgetLine $line, array $data, User $by): BudgetLine
    {
        $b = Budget::withoutGlobalScopes()->findOrFail($line->budget_id);
        $this->assertEditable($b);

        return DB::transaction(function () use ($b, $line, $data, $by) {
            $line = $this->saveLine($b, $line, $data, $by);
            $this->recalculate($b);

            return $line->refresh();
        });
    }

    public function deleteLine(BudgetLine $line): void
    {
        $b = Budget::withoutGlobalScopes()->findOrFail($line->budget_id);
        $this->assertEditable($b);
        DB::transaction(function () use ($b, $line) {
            BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $line->id)->get()->each->delete();
            $line->delete();
            $this->recalculate($b);
        });
    }

    /** @param array<int> $ids */
    public function reorder(Budget $b, array $ids): void
    {
        $this->assertEditable($b);
        foreach (array_values($ids) as $i => $id) {
            BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->whereKey($id)->update(['sort_order' => ($i + 1) * 10]);
        }
    }

    /**
     * Create or update one line; computes annual, FX, base and phasing. $recalc is false while
     * cloning (the caller recalculates once).
     */
    public function saveLine(Budget $b, ?BudgetLine $line, array $data, User $by, bool $recalc = true): BudgetLine
    {
        $orgId = (int) $b->organization_id;
        $fy = FiscalYear::withoutGlobalScopes()->findOrFail($b->fiscal_year_id);
        $cur = $line ? $line->getAttributes() : [];
        $get = fn (string $k, $default = null) => array_key_exists($k, $data) ? $data[$k] : ($cur[$k] ?? $default);

        $description = trim((string) $get('description', ''));
        if ($description === '' || mb_strlen($description) > 255) {
            $this->fail('A line needs a description (max 255 characters).', 'description');
        }

        [$pool, $tower, $cat] = $this->tbm($orgId, $get('cost_pool_id'), $get('tower_id'), $get('category_id'));
        $dims = $this->dimensions($orgId, $get);

        $glId = $get('gl_account_id');
        $glId ??= $cat?->default_gl_account_id ?? $pool->default_gl_account_id;
        if ($glId && ! GlAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($glId)->exists()) {
            $this->fail('Unknown GL account.', 'gl_account_id');
        }
        $isCapex = array_key_exists('is_capex', $data) ? (bool) $data['is_capex'] : ($line ? (bool) $line->is_capex : (bool) $pool->default_capex);

        $priority = $get('priority', 'discretionary');
        $rgt = $get('rgt', 'run');
        in_array($priority, BudgetLine::PRIORITIES, true) || $this->fail('Unknown priority.', 'priority');
        in_array($rgt, BudgetLine::RGT, true) || $this->fail('Unknown run / grow / transform value.', 'rgt');
        $method = $get('phasing_method', 'equal');
        in_array($method, BudgetLine::PHASING, true) || $this->fail('Unknown phasing method.', 'phasing_method');
        if (! empty($data['investment_id'])) {
            $this->fail('Investments can be linked from a later phase.', 'investment_id');
        }
        $contractId = null;
        if (! empty($data['contract_id'])) {
            Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($data['contract_id'])->exists()
                || $this->fail('Unknown contract for this bank.', 'contract_id');
            $contractId = (int) $data['contract_id'];
        }

        $baseCode = $this->settings->baseCurrency($orgId);
        $currency = strtoupper((string) $get('currency', $baseCode));
        Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('code', $currency)->where('is_active', true)->exists()
            || $this->fail('Unknown or inactive currency.', 'currency');

        $quantity = $get('quantity', '1');
        (! is_numeric($quantity) || bccomp((string) $quantity, '0', 4) <= 0) && $this->fail('Quantity must be greater than 0.', 'quantity');
        foreach (['variance_amber_pct', 'variance_red_pct'] as $k) {
            $v = $get($k);
            ($v !== null && $v !== '' && (! is_numeric($v) || $v < 0 || $v > 1000)) && $this->fail('Enter a percentage between 0 and 1000.', $k);
        }

        $periods = $this->phasing->periodsFor($fy);
        $explicitAnnual = array_key_exists('annual_amount', $data) && $data['annual_amount'] !== null && $data['annual_amount'] !== '';
        $unit = $get('unit_cost');
        if ($explicitAnnual && ! array_key_exists('unit_cost', $data) && ! array_key_exists('quantity', $data)) {
            $unit = null;
        }
        $driverId = $get('driver_id');
        $driverRate = $get('driver_rate');
        $amounts = isset($data['amounts']) && is_array($data['amounts']) ? $data['amounts'] : null;

        if ($driverId && $driverRate !== null && $driverRate !== '') {
            $values = DriverValue::withoutGlobalScopes()->where('organization_id', $orgId)->where('driver_id', $driverId)->pluck('value', 'period_id');
            $amounts = [];
            foreach ($periods as $p) {
                isset($values[$p->id]) || $this->fail("The driver has no value for {$p->name}.", 'driver_id');
                $amounts[$p->id] = $this->phasing->round(bcmul((string) $values[$p->id], (string) $driverRate, 8), 2);
            }
            $method = 'custom';
            $annual = array_reduce($amounts, fn ($c, $a) => bcadd($c, $a, 2), '0');
            $unit = null;
        } elseif ($unit !== null && $unit !== '') {
            is_numeric($unit) || $this->fail('Unit cost must be a number.', 'unit_cost');
            $annual = $this->phasing->round(bcmul((string) $quantity, (string) $unit, 8), 2);
        } elseif ($explicitAnnual) {
            $this->decimal($data['annual_amount'], 'annual_amount');
            $annual = bcadd((string) $data['annual_amount'], '0', 2);
        } else {
            $annual = $line ? bcadd((string) $line->annual_amount, '0', 2) : '0.00';
        }
        bccomp($annual, '0', 2) < 0 && $this->fail('The annual amount cannot be negative.', 'annual_amount');

        $fxFields = null;
        if ($method === 'custom') {
            if ($amounts === null && $line) {
                $amounts = BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $line->id)->pluck('amount', 'period_id')->map(fn ($a) => (string) $a)->all();
            }
            if ($amounts === null) {
                $this->fail('Custom phasing needs an amount for every period.', 'amounts');
            }
            $amounts = $this->normaliseAmounts($periods, $amounts);
            $sum = array_reduce($amounts, fn ($c, $a) => bcadd($c, $a, 2), '0');
            if (! $explicitAnnual && ! ($unit !== null && $unit !== '') && ! ($driverId && $driverRate !== null && $driverRate !== '')) {
                $annual = $sum;
            }
            $this->phasing->assertSums($annual, $amounts, 2);
        }

        // FX: a new line (or a currency change) converts at the planning rate on the fiscal-year start date.
        if (! $line || $line->currency !== $currency || empty($cur['fx_rate'])) {
            $conv = $this->fx->convert($orgId, $annual, $currency, $fy->starts_on, config('finance.fx.planning_rate_type'));
            $fxFields = ['fx_rate' => $conv['fx_rate'], 'fx_rate_type' => $conv['rate_type'], 'fx_rate_date' => $conv['rate_date']];
            $baseAnnual = $conv['base_amount'];
        } else {
            $fxFields = ['fx_rate' => $cur['fx_rate'], 'fx_rate_type' => $cur['fx_rate_type'], 'fx_rate_date' => $cur['fx_rate_date']];
            $baseAnnual = $this->phasing->round(bcmul($annual, (string) $cur['fx_rate'], 12), 2);
        }

        $attrs = [
            'description' => $description, 'cost_pool_id' => $pool->id, 'tower_id' => $tower->id, 'category_id' => $cat?->id, 'gl_account_id' => $glId ?: null,
            'cost_centre_id' => $b->cost_centre_id, 'legal_entity_id' => $dims['legal_entity_id'] ?? $b->legal_entity_id,
            'is_capex' => $isCapex, 'is_recurring' => (bool) $get('is_recurring', false), 'priority' => $priority, 'rgt' => $rgt,
            'contract_id' => array_key_exists('contract_id', $data) ? $contractId : ($cur['contract_id'] ?? null),
            'vendor_id' => $dims['vendor_id'], 'asset_id' => $dims['asset_id'], 'risk_id' => $dims['risk_id'], 'driver_id' => $driverId ?: null,
            'ea_application_id' => $dims['ea_application_id'], 'business_service_id' => $dims['business_service_id'],
            'driver_rate' => $driverRate === '' ? null : $driverRate, 'quantity' => $quantity, 'unit_cost' => $unit === '' ? null : $unit,
            'currency' => $currency, 'annual_amount' => $annual, 'base_annual_amount' => $baseAnnual, 'phasing_method' => $method,
            'justification' => $get('justification'), 'variance_amber_pct' => $get('variance_amber_pct') === '' ? null : $get('variance_amber_pct'),
            'variance_red_pct' => $get('variance_red_pct') === '' ? null : $get('variance_red_pct'),
        ] + $fxFields;
        if (array_key_exists('source_line_id', $data)) {
            $attrs['source_line_id'] = $data['source_line_id'];
        }
        if (array_key_exists('origin', $data)) {
            $attrs['origin'] = $data['origin'];
        }

        if ($line) {
            if (array_key_exists('sort_order', $data)) {
                $attrs['sort_order'] = (int) $data['sort_order'];
            }
            $line->fill($attrs)->save();
        } else {
            $lineNo = isset($data['line_no']) ? (int) $data['line_no'] : (int) BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->max('line_no') + 1;
            if (BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->where('line_no', $lineNo)->exists()) {
                $this->fail("Line {$lineNo} already exists.", 'line_no');
            }
            $line = BudgetLine::withoutGlobalScopes()->create($attrs + [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'budget_id' => $b->id, 'line_no' => $lineNo,
                'sort_order' => (int) ($data['sort_order'] ?? $lineNo * 10),
            ]);
        }
        $this->writePeriods($line, $periods, $method, $annual, $baseAnnual, $amounts);

        return $line->refresh();
    }

    /**
     * Re-phase a line with a method (and period amounts for custom).
     *
     * @param  array<int|string, string>|null  $amounts  period_id => amount in line currency
     */
    public function phase(BudgetLine $line, string $method, ?array $amounts = null): BudgetLine
    {
        $b = Budget::withoutGlobalScopes()->findOrFail($line->budget_id);
        $this->assertEditable($b);
        in_array($method, BudgetLine::PHASING, true) || $this->fail('Unknown phasing method.', 'phasing_method');
        $fy = FiscalYear::withoutGlobalScopes()->findOrFail($b->fiscal_year_id);
        $periods = $this->phasing->periodsFor($fy);
        if ($method === 'custom') {
            $amounts === null && $this->fail('Custom phasing needs an amount for every period.', 'amounts');
            $amounts = $this->normaliseAmounts($periods, $amounts);
            $this->phasing->assertSums((string) $line->annual_amount, $amounts, 2);
        }

        return DB::transaction(function () use ($line, $method, $amounts, $periods) {
            $line->forceFill(['phasing_method' => $method])->save();
            $this->writePeriods($line, $periods, $method, (string) $line->annual_amount, (string) $line->base_annual_amount, $amounts);

            return $line->refresh();
        });
    }

    private function writePeriods(BudgetLine $line, Collection $periods, string $method, string $annual, string $baseAnnual, ?array $amounts): void
    {
        $n = $periods->count();
        $weights = $method === 'custom'
            ? $periods->map(fn ($p) => (string) ($amounts[$p->id] ?? '0'))->values()->all()
            : $this->phasing->weights($method, $n);
        $shares = $method === 'custom' ? $periods->map(fn ($p) => (string) ($amounts[$p->id] ?? '0'))->values()->all() : $this->phasing->spread($annual, $weights, 2);
        $bases = $this->phasing->spread($baseAnnual, $weights, 2);
        foreach ($periods->values() as $i => $p) {
            $row = BudgetLinePeriod::withoutGlobalScopes()->firstOrNew(['budget_line_id' => $line->id, 'period_id' => $p->id]);
            if (! $row->exists) {
                $row->organization_id = $line->organization_id;
                $row->created_by_id = $line->created_by_id;
            }
            $row->fill(['amount' => $shares[$i], 'base_amount' => $bases[$i]])->save();
        }
    }

    /** Whole workbook grid in one transaction: all or nothing, errors keyed lines.{i}.{field}. */
    public function bulkSave(Budget $b, array $lines, array $deletedIds, User $by): Budget
    {
        if ($b->is_locked) {
            return $this->editLocked($b, $by, function (Budget $draft) use ($lines, $deletedIds, $by) {
                $map = BudgetLine::withoutGlobalScopes()->where('budget_id', $draft->id)->pluck('id', 'source_line_id');
                $lines = array_map(fn ($l) => ! empty($l['id']) ? array_merge($l, ['id' => $map[$l['id']] ?? $l['id']]) : $l, $lines);
                $this->bulkSave($draft, $lines, array_map(fn ($id) => $map[$id] ?? $id, $deletedIds), $by);
            });
        }
        $this->assertEditable($b);
        DB::transaction(function () use ($b, $lines, $deletedIds, $by) {
            $errors = [];
            foreach ($deletedIds as $id) {
                $l = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->whereKey($id)->first();
                if ($l) {
                    BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $l->id)->get()->each->delete();
                    $l->delete();
                }
            }
            foreach (array_values($lines) as $i => $row) {
                try {
                    $line = ! empty($row['id']) ? BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->whereKey($row['id'])->first() : null;
                    if (! empty($row['id']) && ! $line) {
                        $this->fail('This line does not belong to the budget.', 'id');
                    }
                    $this->saveLine($b, $line, $row, $by);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $errors["lines.{$i}.{$field}"] = $messages;
                    }
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $this->recalculate($b);
        });

        return $b->refresh();
    }

    public function recalculate(Budget $b): Budget
    {
        $capex = '0';
        $opex = '0';
        foreach (BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get(['is_capex', 'base_annual_amount']) as $l) {
            $l->is_capex ? $capex = bcadd($capex, (string) $l->base_annual_amount, 2) : $opex = bcadd($opex, (string) $l->base_annual_amount, 2);
        }
        Budget::withoutEvents(function () use ($b, $capex, $opex) {
            $b->forceFill(['total_capex' => $capex, 'total_opex' => $opex, 'total_base_amount' => bcadd($capex, $opex, 2)])->save();
        });

        return $b;
    }

    /** @return array{targets: array<int, array<string,mixed>>, over: bool, over_line_ids: array<int>} */
    public function checkCeiling(Budget $b): array
    {
        $orgId = (int) $b->organization_id;
        $cycle = BudgetCycle::withoutGlobalScopes()->find($b->cycle_id);
        if (! $cycle) {
            return ['targets' => [], 'over' => false, 'over_line_ids' => []];
        }
        $ancestors = $this->cycles->ancestorsOrSelf($orgId, (int) $b->cost_centre_id);
        $all = $this->cycles->cycleLines($cycle);
        $mine = $all->where('budget_id', $b->id);
        if ($mine->isEmpty()) {
            $mine = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get()->each(fn ($l) => $l->setAttribute('budget_legal_entity_id', $b->legal_entity_id));
            $all = $all->concat($mine);
        }

        $rows = [];
        $overIds = [];
        $targets = BudgetTarget::withoutGlobalScopes()->where('organization_id', $orgId)->where('cycle_id', $cycle->id)
            ->with(['costCentre:id,code', 'category:id,code,kind', 'legalEntity:id,code'])->orderBy('id')->get();
        foreach ($targets as $t) {
            if (($t->cost_centre_id && ! in_array((int) $t->cost_centre_id, $ancestors, true))
                || ($t->legal_entity_id && (int) $t->legal_entity_id !== (int) $b->legal_entity_id)) {
                continue;
            }
            $row = $this->cycles->usageRow($t, $all);
            $rows[] = $row;
            foreach (['capex' => true, 'opex' => false] as $kind => $isCapex) {
                if (! $row["{$kind}_over"]) {
                    continue;
                }
                $inScope = $this->cycles->inScope($t, $mine)->where('is_capex', $isCapex);
                $others = bcsub($row["{$kind}_requested"], $inScope->reduce(fn ($c, $l) => bcadd($c, (string) $l->base_annual_amount, 2), '0'), 2);
                $running = $others;
                foreach ($inScope->sortBy([fn ($l) => self::PRIORITY_ORDER[$l->priority] ?? 9, fn ($l) => $l->sort_order, fn ($l) => $l->line_no]) as $l) {
                    $running = bcadd($running, (string) $l->base_annual_amount, 2);
                    if (bccomp($running, (string) $row["{$kind}_ceiling"], 2) > 0) {
                        $overIds[] = $l->id;
                    }
                }
            }
        }

        return [
            'targets' => array_map(fn ($r) => array_diff_key($r, ['capex_pct' => 1, 'opex_pct' => 1, 'over' => 1]), $rows),
            'over' => collect($rows)->contains('over', true), 'over_line_ids' => array_values(array_unique($overIds)),
        ];
    }

    /* ------------------------------------------------------------------ lifecycle */

    public function submit(Budget $b, User $by): Budget
    {
        $this->assertTransition($b, 'submit');
        $cycle = BudgetCycle::withoutGlobalScopes()->find($b->cycle_id);
        if (! $cycle || ! in_array($cycle->status, ['open', 'in_review'], true)) {
            $this->fail('The cycle is not accepting submissions.');
        }
        $lines = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get();
        if ($lines->isEmpty()) {
            $this->fail('Add at least one line before submitting.');
        }
        foreach ($lines as $l) {
            $sums = BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $l->id)->get();
            $amount = $sums->reduce(fn ($c, $p) => bcadd($c, (string) $p->amount, 2), '0');
            $base = $sums->reduce(fn ($c, $p) => bcadd($c, (string) $p->base_amount, 2), '0');
            if (bccomp($amount, (string) $l->annual_amount, 2) !== 0 || bccomp($base, (string) $l->base_annual_amount, 2) !== 0) {
                $this->fail("Line {$l->line_no} is not fully phased: periods total {$amount} against {$l->annual_amount}.");
            }
        }

        return DB::transaction(function () use ($b, $by) {
            $this->recalculate($b);
            $b->forceFill(['status' => 'submitted', 'submitted_by_id' => $by->id, 'submitted_at' => now(), 'rejection_reason' => null])->save();
            $this->approvals->start('finance.budget_approval', $b, $by);
            $this->audit($b, $by, 'submitted', "Budget {$b->budget_code} {$b->version_label} submitted", ['total_base_amount' => (string) $b->total_base_amount]);

            return $b->refresh();
        });
    }

    public function withdraw(Budget $b, User $by): Budget
    {
        $this->assertTransition($b, 'withdraw');
        if ((int) $b->submitted_by_id !== (int) $by->id && (int) $b->owner_id !== (int) $by->id) {
            $this->fail('Only the person who submitted the budget, or its owner, can withdraw it.');
        }
        DB::transaction(function () use ($b, $by) {
            $b->forceFill(['status' => 'draft'])->save();
            $this->approvals->withdraw('finance.budget_approval', $b, $by);
            $this->audit($b, $by, 'withdrawn', "Budget {$b->budget_code} {$b->version_label} withdrawn");
        });

        return $b->refresh();
    }

    /** Returns a message: either the next stage awaited or the final approval. */
    public function approve(Budget $b, User $by, ?string $note = null): string
    {
        $this->assertTransition($b, 'approve');
        $this->assertApprover($b, $by);

        return DB::transaction(function () use ($b, $by, $note) {
            $configured = $this->approvals->configured('finance.budget_approval', (int) $b->organization_id);
            $configured && $this->approvals->assertZeroStepSoD('finance.budget_approval', $b, $by, 'submitted_by_id', 'owner_id');
            if (! $configured) {
                $this->approvals->assertDefaultApprover($b, $by, 'submitted_by_id', 'owner_id');
            } elseif ($message = $this->approvals->approve('finance.budget_approval', $b, $by, $note)) {
                $b->forceFill(['status' => 'under_review'])->save();
                $this->comment($b, $by, $note ?: $message, null, 'approve');
                $this->audit($b, $by, 'stage_approved', $message);

                return $message;
            }
            $b->forceFill(['status' => 'approved', 'approved_by_id' => $by->id, 'approved_at' => now()])->save();
            $this->approvals->finalised('finance.budget_approval', $b, $by, $note);
            $note && $this->comment($b, $by, $note, null, 'approve');
            $this->audit($b, $by, 'approved', "Budget {$b->budget_code} {$b->version_label} approved");

            return "Budget {$b->budget_code} {$b->version_label} approved.";
        });
    }

    /** @param array<int, array{line_id?:int|null, body:string}> $lineComments */
    public function returnToDraft(Budget $b, User $by, string $note, array $lineComments = []): Budget
    {
        return $this->decline($b, $by, $note, $lineComments, 'return', 'returned');
    }

    public function reject(Budget $b, User $by, string $note, array $lineComments = []): Budget
    {
        return $this->decline($b, $by, $note, $lineComments, 'reject', 'rejected');
    }

    private function decline(Budget $b, User $by, string $note, array $lineComments, string $action, string $outcome): Budget
    {
        $this->assertTransition($b, $action);
        blank($note) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($b, $by);
        if (! $this->approvals->configured('finance.budget_approval', (int) $b->organization_id)) {
            $this->approvals->assertDefaultApprover($b, $by, 'submitted_by_id', 'owner_id');
        }

        return DB::transaction(function () use ($b, $by, $note, $lineComments, $action, $outcome) {
            $this->approvals->decline('finance.budget_approval', $b, $by, $note, $outcome);
            $b->forceFill(['status' => self::TRANSITIONS[$action]['to'], 'rejection_reason' => $action === 'reject' ? $note : null])->save();
            $this->comment($b, $by, $note, null, $action);
            foreach ($lineComments as $c) {
                if (! empty($c['body'])) {
                    $lineId = ! empty($c['line_id']) && BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->whereKey($c['line_id'])->exists() ? (int) $c['line_id'] : null;
                    $this->comment($b, $by, (string) $c['body'], $lineId, $action);
                }
            }
            $this->audit($b, $by, $outcome, "Budget {$b->budget_code} {$b->version_label} {$outcome}", ['note' => $note]);

            return $b->refresh();
        });
    }

    public function baseline(Budget $b, User $by): Budget
    {
        $this->assertTransition($b, 'baseline');
        $by->can('approve it-finance') || $this->fail('You need the "approve it-finance" permission to baseline a budget.');

        return DB::transaction(function () use ($b, $by) {
            $b = Budget::withoutGlobalScopes()->lockForUpdate()->findOrFail($b->id);
            $this->assertTransition($b, 'baseline');
            $slot = "{$b->fiscal_year_id}:{$b->cost_centre_id}";
            $prior = Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('baseline_slot', $slot)->where('id', '!=', $b->id)->lockForUpdate()->first();
            if ($prior) {
                $prior->forceFill(['status' => 'revised', 'superseded_by_id' => $b->id, 'baseline_slot' => null])->save();
                $this->carryForwardAdjustments($prior, $b);
            }
            Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('budget_code', $b->budget_code)
                ->where('id', '!=', $b->id)->where('status', 'approved')->get()
                ->each(fn (Budget $o) => $o->forceFill(['status' => 'revised', 'superseded_by_id' => $b->id])->save());
            $b->forceFill(['status' => 'baseline', 'baseline_slot' => $slot, 'baselined_by_id' => $by->id, 'baselined_at' => now()])->save();
            $this->audit($b, $by, 'baselined', "Budget {$b->budget_code} {$b->version_label} baselined", ['superseded' => $prior?->id]);

            Budget::withoutGlobalScopes()->where('organization_id', $b->organization_id)->where('cost_centre_id', $b->cost_centre_id)
                ->whereIn('status', Budget::EDITABLE)->where('fiscal_year_id', '!=', $b->fiscal_year_id)->get()
                ->each(fn (Budget $d) => $this->refreshPriorYear($d));

            return $b->refresh();
        });
    }

    /**
     * revise() folds the adjustments that exist at that moment into the new version's amounts. Adjustments applied to
     * the superseded baseline after that are mirrored onto the matching lines, so current = baseline + adjustments (C22).
     */
    private function carryForwardAdjustments(Budget $prior, Budget $new): void
    {
        $map = BudgetLine::withoutGlobalScopes()->where('budget_id', $new->id)->whereNotNull('source_line_id')->pluck('id', 'source_line_id');
        $late = BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $map->keys()->all())->where('created_at', '>', $new->created_at)->get();
        foreach ($late as $a) {
            $attrs = $a->getAttributes();
            unset($attrs['id'], $attrs['created_at'], $attrs['updated_at']);
            BudgetAdjustment::withoutGlobalScopes()->create(array_merge($attrs, ['budget_line_id' => $map[$a->budget_line_id]]));
        }
    }

    /** py_budget from the prior fiscal year's baseline (lineage first, then pool + tower + description). */
    public function refreshPriorYear(Budget $draft): void
    {
        $fy = FiscalYear::withoutGlobalScopes()->find($draft->fiscal_year_id);
        $prior = $fy ? $this->priorBaseline((int) $draft->cost_centre_id, $fy, (int) $draft->organization_id) : null;
        $priorLines = $prior ? BudgetLine::withoutGlobalScopes()->where('budget_id', $prior->id)->get() : collect();
        foreach (BudgetLine::withoutGlobalScopes()->where('budget_id', $draft->id)->get() as $line) {
            $match = $priorLines->firstWhere('id', $line->source_line_id)
                ?? $priorLines->first(fn ($p) => (int) $p->tower_id === (int) $line->tower_id && (int) $p->cost_pool_id === (int) $line->cost_pool_id && $p->description === $line->description);
            $py = $match ? $match->current_base_amount : null;
            $pyActual = $match ? app(ActualsService::class)->priorYearActual($match) : null;
            if ($py !== ($line->py_budget === null ? null : (string) $line->py_budget) || $pyActual !== ($line->py_actual === null ? null : (string) $line->py_actual)) {
                BudgetLine::withoutEvents(fn () => $line->forceFill(['py_budget' => $py, 'py_actual' => $pyActual])->save());
            }
        }
    }

    /** Narrative header edit on an editable version. */
    public function updateHeader(Budget $b, array $data): Budget
    {
        $this->assertEditable($b);
        if (isset($data['owner_id']) && $data['owner_id'] && ! User::withoutGlobalScopes()->where('organization_id', $b->organization_id)->whereKey($data['owner_id'])->exists()) {
            $this->fail('Pick an owner from this bank.', 'owner_id');
        }
        $b->fill(array_intersect_key($data, array_flip(['title', 'executive_summary', 'assumptions', 'risks_summary', 'owner_id'])))->save();

        return $b->refresh();
    }

    public function comment(Budget $b, User $by, string $body, ?int $lineId = null, string $kind = 'comment'): BudgetComment
    {
        if ($lineId && ! BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->whereKey($lineId)->exists()) {
            $this->fail('That line is not on this budget.', 'budget_line_id');
        }

        return BudgetComment::withoutGlobalScopes()->create([
            'organization_id' => $b->organization_id, 'created_by_id' => $by->id, 'budget_id' => $b->id, 'budget_line_id' => $lineId,
            'user_id' => $by->id, 'kind' => $kind, 'body' => $body,
        ]);
    }

    /* ------------------------------------------------------------------ scenarios */

    public function scenarioSave(Budget $b, array $data, ?BudgetScenario $scenario = null): BudgetScenario
    {
        $this->assertEditable($b);
        $pct = $data['adjustment_pct'] ?? $scenario?->adjustment_pct ?? 0;
        (! is_numeric($pct) || $pct < -100 || $pct > 500) && $this->fail('The adjustment must be between -100 and +500 per cent.', 'adjustment_pct');
        $overrides = [];
        $lineIds = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->pluck('id')->all();
        foreach ((array) ($data['line_adjustments'] ?? $scenario?->line_adjustments ?? []) as $lineId => $p) {
            in_array((int) $lineId, $lineIds, true) || $this->fail("Line {$lineId} is not on this budget.", 'line_adjustments');
            (! is_numeric($p) || $p < -100 || $p > 500) && $this->fail('Line adjustments must be between -100 and +500 per cent.', 'line_adjustments');
            $overrides[(int) $lineId] = (string) $p;
        }
        $name = trim((string) ($data['name'] ?? $scenario?->name ?? ''));
        ($name === '' || mb_strlen($name) > 120) && $this->fail('Name the scenario (max 120 characters).', 'name');

        $attrs = ['name' => $name, 'adjustment_pct' => $pct, 'line_adjustments' => $overrides ?: null];
        if ($scenario) {
            $scenario->is_promoted && $this->fail('A promoted scenario cannot be edited.');
            $scenario->fill($attrs)->save();

            return $scenario->refresh();
        }

        return BudgetScenario::withoutGlobalScopes()->create($attrs + ['organization_id' => $b->organization_id, 'budget_id' => $b->id, 'created_by_id' => auth()->id()]);
    }

    public function scenarioDestroy(BudgetScenario $s): void
    {
        $b = Budget::withoutGlobalScopes()->findOrFail($s->budget_id);
        $this->assertEditable($b);
        $s->delete();
    }

    /** Scenario total in base currency without touching the lines. */
    public function scenarioTotal(Budget $b, BudgetScenario $s): string
    {
        $total = '0';
        foreach (BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get() as $l) {
            $pct = (string) (($s->line_adjustments[$l->id] ?? null) ?? $s->adjustment_pct);
            $total = bcadd($total, $this->phasing->round(bcmul((string) $l->base_annual_amount, bcadd('1', bcdiv($pct, '100', 8), 8), 12), 2), 2);
        }

        return $total;
    }

    public function promoteScenario(BudgetScenario $s, User $by): BudgetScenario
    {
        $b = Budget::withoutGlobalScopes()->findOrFail($s->budget_id);
        $this->assertEditable($b);
        $s->is_promoted && $this->fail('This scenario is already promoted.');
        $fy = FiscalYear::withoutGlobalScopes()->findOrFail($b->fiscal_year_id);
        $periods = $this->phasing->periodsFor($fy);

        DB::transaction(function () use ($s, $b, $by, $periods) {
            foreach (BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get() as $l) {
                $pct = (string) (($s->line_adjustments[$l->id] ?? null) ?? $s->adjustment_pct);
                $factor = bcadd('1', bcdiv($pct, '100', 8), 8);
                $annual = $this->phasing->round(bcmul((string) $l->annual_amount, $factor, 12), 2);
                $base = $this->phasing->round(bcmul((string) $l->base_annual_amount, $factor, 12), 2);
                $existing = BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $l->id)->pluck('amount', 'period_id');
                $weights = $periods->map(fn ($p) => (string) ($existing[$p->id] ?? '0'))->values()->all();
                $l->forceFill([
                    'annual_amount' => $annual, 'base_annual_amount' => $base,
                    'unit_cost' => $l->unit_cost === null ? null : $this->phasing->round(bcmul((string) $l->unit_cost, $factor, 12), 2),
                    'driver_rate' => $l->driver_rate === null ? null : $this->phasing->round(bcmul((string) $l->driver_rate, $factor, 12), 4),
                ])->save();
                $shares = $this->phasing->spread($annual, $weights, 2);
                $bases = $this->phasing->spread($base, $weights, 2);
                foreach ($periods->values() as $i => $p) {
                    BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $l->id)->where('period_id', $p->id)
                        ->get()->each(fn ($row) => $row->fill(['amount' => $shares[$i], 'base_amount' => $bases[$i]])->save());
                }
            }
            BudgetScenario::withoutGlobalScopes()->where('budget_id', $b->id)->where('is_promoted', true)->get()->each(fn ($o) => $o->forceFill(['is_promoted' => false])->save());
            $s->forceFill(['is_promoted' => true, 'promoted_at' => now(), 'promoted_by_id' => $by->id])->save();
            $this->recalculate($b);
            $this->audit($b, $by, 'scenario_promoted', "Scenario \"{$s->name}\" promoted on {$b->budget_code}", ['scenario_id' => $s->id]);
        });

        return $s->refresh();
    }

    /* ------------------------------------------------------------------ diff */

    /** @return array<string, mixed> */
    public function diff(Budget $a, Budget $b): array
    {
        if ($a->budget_code !== $b->budget_code || (int) $a->organization_id !== (int) $b->organization_id) {
            $this->fail('Only versions of the same budget can be compared.', 'against');
        }
        if ($a->version_no > $b->version_no) {
            [$a, $b] = [$b, $a];
        }
        $periods = $this->phasing->periodsFor(FiscalYear::withoutGlobalScopes()->findOrFail($b->fiscal_year_id));
        $aLines = BudgetLine::withoutGlobalScopes()->where('budget_id', $a->id)->get();
        $bLines = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get();

        // Lineage: every line of the versions between a and b, so v1 vs v3 follows source_line_id through v2 (M3a).
        $between = Budget::withoutGlobalScopes()->where('organization_id', $a->organization_id)->where('budget_code', $a->budget_code)
            ->whereBetween('version_no', [$a->version_no, $b->version_no])->pluck('id');
        $chain = BudgetLine::withoutGlobalScopes()->whereIn('budget_id', $between)->get(['id', 'budget_id', 'source_line_id'])
            ->mapWithKeys(fn ($l) => [$l->id => ['budget_id' => (int) $l->budget_id, 'source' => $l->source_line_id]])->all();
        $ancestor = function (BudgetLine $bl) use ($chain, $a, $between) {
            $id = $bl->source_line_id;
            for ($hops = 0; $id && $hops <= $between->count(); $hops++) {
                $node = $chain[$id] ?? null;
                if (! $node) {
                    return null;
                }
                if ($node['budget_id'] === (int) $a->id) {
                    return $id;
                }
                $id = $node['source'];
            }

            return null;
        };

        $adjSum = BudgetAdjustment::withoutGlobalScopes()->whereIn('budget_line_id', $aLines->pluck('id')->merge($bLines->pluck('id')))
            ->selectRaw('budget_line_id, sum(amount) as amount, sum(base_amount) as base')->groupBy('budget_line_id')->get()->keyBy('budget_line_id');
        $adjBase = fn (BudgetLine $l) => bcadd((string) ($adjSum[$l->id]->base ?? '0'), '0', 2);
        $adjLine = fn (BudgetLine $l) => bcadd((string) ($adjSum[$l->id]->amount ?? '0'), '0', 2);

        $matched = [];
        $rows = [];
        $mini = fn (BudgetLine $l) => [
            'id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'annual_amount' => (string) $l->annual_amount, 'base_annual_amount' => (string) $l->base_annual_amount,
            'original_base' => bcadd((string) $l->base_annual_amount, '0', 2), 'adjustments_base' => $adjBase($l),
            'current_base' => bcadd((string) $l->base_annual_amount, $adjBase($l), 2),
        ];
        $origPeriods = fn (BudgetLine $l) => BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $l->id)->pluck('base_amount', 'period_id')->map(fn ($v) => (string) $v)->all();
        $watch = ['description', 'cost_pool_id', 'tower_id', 'category_id', 'is_capex', 'priority', 'rgt', 'currency', 'phasing_method', 'vendor_id'];
        $capexCurrent = ['a' => '0', 'b' => '0'];
        $opexCurrent = ['a' => '0', 'b' => '0'];
        $revisionTotal = '0';
        $tally = function (string $side, BudgetLine $l, array $mini) use (&$capexCurrent, &$opexCurrent) {
            if ($l->is_capex) {
                $capexCurrent[$side] = bcadd($capexCurrent[$side], $mini['current_base'], 2);
            } else {
                $opexCurrent[$side] = bcadd($opexCurrent[$side], $mini['current_base'], 2);
            }
        };
        foreach ($aLines as $al) {
            $tally('a', $al, $mini($al));
        }

        foreach ($bLines as $bl) {
            $aid = $ancestor($bl);
            $al = $aid ? $aLines->firstWhere('id', $aid) : null;
            $al ??= $aLines->first(fn ($x) => ! isset($matched[$x->id]) && (int) $x->line_no === (int) $bl->line_no && $bl->source_line_id === null);
            if ($al) {
                $matched[$al->id] = true;
            }
            $bm = $mini($bl);
            $tally('b', $bl, $bm);
            $am = $al ? $mini($al) : null;
            $fields = [];
            if ($al) {
                foreach ($watch as $f) {
                    if ($f === 'phasing_method' && $bl->phasing_method === 'custom' && bccomp($adjBase($al), '0', 2) !== 0) {
                        continue; // cloneLine re-phases a line it folded adjustments into; that is not an edit
                    }
                    if ((string) $al->{$f} !== (string) $bl->{$f}) {
                        $fields[$f] = [$al->{$f}, $bl->{$f}];
                    }
                }
                // Amounts compare against what the older line stood at after virements and top-ups, not its original.
                $aAnnual = bcadd((string) $al->annual_amount, $adjLine($al), 2);
                if (bccomp($aAnnual, (string) $bl->annual_amount, 2) !== 0) {
                    $fields['annual_amount'] = [$aAnnual, (string) $bl->annual_amount];
                }
                if (bccomp($am['current_base'], $bm['original_base'], 2) !== 0) {
                    $fields['base_annual_amount'] = [$am['current_base'], $bm['original_base']];
                }
            }
            $aCur = $al ? $this->ledger->currentByPeriod($al) : [];
            $pb = $origPeriods($bl);
            $pd = [];
            foreach ($periods as $p) {
                $d = bcsub($pb[$p->id] ?? '0', $aCur[$p->id] ?? '0', 2);
                if (bccomp($d, '0', 2) !== 0) {
                    $pd[$p->id] = $d;
                }
            }
            $revision = bcsub($bm['original_base'], $am['current_base'] ?? '0', 2);
            $revisionTotal = bcadd($revisionTotal, $revision, 2);
            $rows[] = [
                'key' => $bl->id, 'change' => ! $al ? 'added' : ($fields || bccomp($revision, '0', 2) !== 0 || $pd ? 'changed' : 'unchanged'), 'a' => $am, 'b' => $bm, 'fields' => $fields,
                'revision_delta' => $revision, 'current_delta' => bcsub($bm['current_base'], $am['current_base'] ?? '0', 2),
                'base_delta' => $revision, 'periods' => $pd,
            ];
        }
        foreach ($aLines as $al) {
            if (! isset($matched[$al->id])) {
                $am = $mini($al);
                $revision = bcsub('0', $am['current_base'], 2);
                $revisionTotal = bcadd($revisionTotal, $revision, 2);
                $rows[] = ['key' => 'a'.$al->id, 'change' => 'removed', 'a' => $am, 'b' => null, 'fields' => [],
                    'revision_delta' => $revision, 'current_delta' => $revision, 'base_delta' => $revision,
                    'periods' => collect($this->ledger->currentByPeriod($al))->map(fn ($v) => bcsub('0', $v, 2))->filter(fn ($v) => bccomp($v, '0', 2) !== 0)->all()];
            }
        }
        $head = fn (Budget $x) => ['id' => $x->id, 'version_no' => $x->version_no, 'status' => $x->status, 'total_base_amount' => (string) $x->total_base_amount];
        $adjTotal = fn ($lines) => $lines->reduce(fn ($c, $l) => bcadd($c, $adjBase($l), 2), '0');
        $adjA = $adjTotal($aLines);
        $adjB = $adjTotal($bLines);
        $curA = bcadd((string) $a->total_base_amount, $adjA, 2);
        $curB = bcadd((string) $b->total_base_amount, $adjB, 2);

        return [
            'a' => $head($a), 'b' => $head($b),
            'totals' => [
                'original_delta' => bcsub((string) $b->total_base_amount, (string) $a->total_base_amount, 2),
                'adjustments' => ['a' => $adjA, 'b' => $adjB],
                'revision_delta' => $revisionTotal, 'base_delta' => $revisionTotal,
                'current_delta' => bcsub($curB, $curA, 2),
                'capex_delta' => bcsub($capexCurrent['b'], $capexCurrent['a'], 2),
                'opex_delta' => bcsub($opexCurrent['b'], $opexCurrent['a'], 2),
            ],
            'lines' => $rows,
        ];
    }

    /* ------------------------------------------------------------------ helpers */

    private function assertTransition(Budget $b, string $action): void
    {
        $rule = self::TRANSITIONS[$action];
        if (! in_array($b->status, $rule['from'], true)) {
            $this->fail("A {$b->status} budget version cannot be {$this->past($action)}.");
        }
    }

    private function past(string $action): string
    {
        return ['submit' => 'submitted', 'withdraw' => 'withdrawn', 'approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected', 'baseline' => 'baselined', 'revise' => 'revised'][$action];
    }

    private function assertEditable(Budget $b): void
    {
        if (! in_array($b->status, Budget::EDITABLE, true)) {
            $this->fail("Version {$b->version_no} is {$b->status} and cannot be edited; create a revision.");
        }
    }

    private function assertApprover(Budget $b, User $by): void
    {
        if ((int) $by->organization_id !== (int) $b->organization_id) {
            $this->fail('This budget belongs to another bank.');
        }
        $by->can('approve it-finance') || $this->fail('You need the "approve it-finance" permission to decide a budget.', 'approval');
    }

    /** @return array{0: Category, 1: Category, 2: ?Category} */
    private function tbm(int $orgId, $poolId, $towerId, $catId): array
    {
        $find = fn ($id) => $id ? Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($id)->first() : null;
        $pool = $find($poolId);
        ($pool && $pool->kind === 'cost_pool') || $this->fail('Pick a TBM cost pool.', 'cost_pool_id');
        $tower = $find($towerId);
        ($tower && $tower->kind === 'tower') || $this->fail('Pick a TBM IT tower.', 'tower_id');
        $cat = $find($catId);
        if ($catId) {
            $cat || $this->fail('Unknown category.', 'category_id');
            $root = $cat;
            for ($i = 0; $i < 5 && $root->parent_id; $i++) {
                $root = Category::withoutGlobalScopes()->find($root->parent_id) ?? $root;
            }
            in_array((int) $root->id, [(int) $pool->id, (int) $tower->id], true) || $this->fail('The category must sit under the chosen cost pool or tower.', 'category_id');
        }

        return [$pool, $tower, $cat];
    }

    /** @return array<string, int|null> */
    private function dimensions(int $orgId, callable $get): array
    {
        $check = function (string $key, string $class, bool $allowShared = false) use ($orgId, $get) {
            $id = $get($key);
            if (! $id) {
                return null;
            }
            $q = $class::withoutGlobalScopes()->whereKey($id);
            $q->where(fn ($w) => $allowShared ? $w->where('organization_id', $orgId)->orWhereNull('organization_id') : $w->where('organization_id', $orgId));
            $q->exists() || $this->fail('Unknown value for '.str_replace('_', ' ', $key).'.', $key);

            return (int) $id;
        };

        return [
            'vendor_id' => $check('vendor_id', Vendor::class), 'asset_id' => $check('asset_id', Asset::class), 'risk_id' => $check('risk_id', Risk::class),
            'legal_entity_id' => $check('legal_entity_id', LegalEntity::class), 'driver_id' => $check('driver_id', Driver::class),
            'ea_application_id' => $check('ea_application_id', EaApplication::class, true), 'business_service_id' => $check('business_service_id', BusinessService::class, true),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $amounts
     * @return array<int, string> period_id => amount for every period
     */
    private function normaliseAmounts(Collection $periods, array $amounts): array
    {
        $valid = $periods->pluck('id')->all();
        foreach (array_keys($amounts) as $pid) {
            in_array((int) $pid, $valid, true) || $this->fail('An amount refers to a period outside the fiscal year.', 'amounts');
        }
        $out = [];
        foreach ($periods as $p) {
            $v = $amounts[$p->id] ?? $amounts[(string) $p->id] ?? '0';
            ($v === null || $v === '') && $v = '0';
            (! is_numeric($v) || ! preg_match('/^-?\d{1,16}(\.\d{1,8})?$/', (string) $v)) && $this->fail('Period amounts must be plain numbers.', 'amounts');
            $out[$p->id] = $this->phasing->round((string) $v, 2);
        }

        return $out;
    }

    private function decimal($v, string $field): void
    {
        (! is_numeric($v) || ! preg_match('/^-?\d{1,16}(\.\d{1,8})?$/', (string) $v)) && $this->fail('Enter a plain number.', $field);
    }
}
