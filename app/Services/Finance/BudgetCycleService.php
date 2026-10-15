<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetTarget;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/** Budget cycles, their ceilings (targets) and how much is requested against each (FIN-02.01, FIN-02.03). */
class BudgetCycleService
{
    use LogsFinanceEvents;

    public const TRANSITIONS = [
        'open' => ['from' => ['planned'], 'to' => 'open'],
        'review' => ['from' => ['open'], 'to' => 'in_review'],
        'close' => ['from' => ['in_review'], 'to' => 'closed'],
        'reopen' => ['from' => ['in_review'], 'to' => 'open'],
    ];

    public function create(int $orgId, array $data, User $by): BudgetCycle
    {
        $fy = $this->fiscalYear($orgId, (int) ($data['fiscal_year_id'] ?? 0));
        $this->assertWindow($fy, $data['opens_on'] ?? null, $data['closes_on'] ?? null);
        $this->assertType($data['type'] ?? null);
        $coordinator = $this->coordinator($orgId, $data['coordinator_id'] ?? null);

        $cycle = BudgetCycle::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'cycle_code' => BudgetCycle::generateNextCode($orgId),
            'fiscal_year_id' => $fy->id, 'name' => $data['name'], 'type' => $data['type'], 'opens_on' => $data['opens_on'], 'closes_on' => $data['closes_on'],
            'instructions' => $data['instructions'] ?? null, 'coordinator_id' => $coordinator, 'status' => 'planned',
        ]);
        $this->audit($cycle, $by, 'created', "Budget cycle {$cycle->cycle_code} created");

        return $cycle;
    }

    public function update(BudgetCycle $cycle, array $data): BudgetCycle
    {
        if ($cycle->status === 'closed') {
            $this->fail('A closed cycle cannot be edited.');
        }
        $fy = $this->fiscalYear((int) $cycle->organization_id, (int) ($data['fiscal_year_id'] ?? $cycle->fiscal_year_id));
        if ((int) $fy->id !== (int) $cycle->fiscal_year_id && $cycle->budgets()->exists()) {
            $this->fail('The fiscal year cannot change once the cycle has budgets.');
        }
        $this->assertWindow($fy, $data['opens_on'] ?? $cycle->opens_on->toDateString(), $data['closes_on'] ?? $cycle->closes_on->toDateString());
        $this->assertType($data['type'] ?? $cycle->type);
        $coordinator = array_key_exists('coordinator_id', $data) ? $this->coordinator((int) $cycle->organization_id, $data['coordinator_id']) : $cycle->coordinator_id;

        $cycle->fill([
            'fiscal_year_id' => $fy->id, 'name' => $data['name'] ?? $cycle->name, 'type' => $data['type'] ?? $cycle->type,
            'opens_on' => $data['opens_on'] ?? $cycle->opens_on, 'closes_on' => $data['closes_on'] ?? $cycle->closes_on,
            'instructions' => array_key_exists('instructions', $data) ? $data['instructions'] : $cycle->instructions, 'coordinator_id' => $coordinator,
        ])->save();

        return $cycle->refresh();
    }

    public function transition(BudgetCycle $cycle, string $action, User $by): BudgetCycle
    {
        $rule = self::TRANSITIONS[$action] ?? $this->fail('Unknown cycle action.', 'action');
        if (! in_array($cycle->status, $rule['from'], true)) {
            $this->fail("A {$cycle->status} cycle cannot be ".['open' => 'opened', 'review' => 'sent for review', 'close' => 'closed', 'reopen' => 'reopened'][$action].'.');
        }
        if ($action === 'close' && Budget::withoutGlobalScopes()->where('cycle_id', $cycle->id)->whereIn('status', Budget::IN_APPROVAL)->exists()) {
            $this->fail('Close is refused while a budget of this cycle is still in approval.');
        }
        $cycle->forceFill(['status' => $rule['to']])->save();
        $this->audit($cycle, $by, $action, "Budget cycle {$cycle->cycle_code} {$rule['to']}", ['to' => $rule['to']]);

        return $cycle;
    }

    public function saveTarget(BudgetCycle $cycle, array $data, User $by, ?BudgetTarget $target = null): BudgetTarget
    {
        $orgId = (int) $cycle->organization_id;
        if ($cycle->status === 'closed') {
            $this->fail('A closed cycle cannot take new targets.');
        }
        $cc = $data['cost_centre_id'] ?? null;
        $cat = $data['category_id'] ?? null;
        $le = $data['legal_entity_id'] ?? null;
        $cc && ! CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($cc)->exists() && $this->fail('Unknown cost centre.', 'cost_centre_id');
        $cat && ! Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($cat)->whereIn('kind', ['cost_pool', 'tower'])->exists()
            && $this->fail('Pick a cost pool or tower.', 'category_id');
        $le && ! LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($le)->exists() && $this->fail('Unknown legal entity.', 'legal_entity_id');

        $capex = $this->money($data['capex_ceiling'] ?? null, 'capex_ceiling');
        $opex = $this->money($data['opex_ceiling'] ?? null, 'opex_ceiling');
        if ($capex === null && $opex === null) {
            $this->fail('Give a CapEx ceiling, an OpEx ceiling or both.', 'capex_ceiling');
        }

        $dup = BudgetTarget::withoutGlobalScopes()->where('organization_id', $orgId)->where('cycle_id', $cycle->id)
            ->when($cc, fn ($q) => $q->where('cost_centre_id', $cc), fn ($q) => $q->whereNull('cost_centre_id'))
            ->when($cat, fn ($q) => $q->where('category_id', $cat), fn ($q) => $q->whereNull('category_id'))
            ->when($le, fn ($q) => $q->where('legal_entity_id', $le), fn ($q) => $q->whereNull('legal_entity_id'))
            ->when($target, fn ($q) => $q->where('id', '!=', $target->id))->exists();
        if ($dup) {
            $this->fail('This cycle already has a target for that scope.', 'cost_centre_id');
        }

        $attrs = ['cost_centre_id' => $cc ?: null, 'category_id' => $cat ?: null, 'legal_entity_id' => $le ?: null,
            'capex_ceiling' => $capex, 'opex_ceiling' => $opex, 'notes' => $data['notes'] ?? null, 'set_by_id' => $by->id];
        if ($target) {
            $target->fill($attrs)->save();

            return $target->refresh();
        }

        return BudgetTarget::withoutGlobalScopes()->create($attrs + ['organization_id' => $orgId, 'created_by_id' => $by->id, 'cycle_id' => $cycle->id]);
    }

    public function deleteTarget(BudgetTarget $target): void
    {
        $target->delete();
    }

    /**
     * Requested spend against each target: lines of the latest non-withdrawn, non-rejected version of
     * every budget in the cycle that fall inside the target's scope, in base currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function targetUsage(BudgetCycle $cycle): array
    {
        $lines = $this->cycleLines($cycle);
        $out = [];
        foreach (BudgetTarget::withoutGlobalScopes()->where('organization_id', $cycle->organization_id)->where('cycle_id', $cycle->id)
            ->with(['costCentre:id,code', 'category:id,code,kind', 'legalEntity:id,code'])->orderBy('id')->get() as $t) {
            $out[] = $this->usageRow($t, $lines);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function usageRow(BudgetTarget $t, Collection $lines): array
    {
        $capex = '0';
        $opex = '0';
        foreach ($this->inScope($t, $lines) as $l) {
            $l->is_capex ? $capex = bcadd($capex, (string) $l->base_annual_amount, 2) : $opex = bcadd($opex, (string) $l->base_annual_amount, 2);
        }
        $capexOver = $t->capex_ceiling !== null && bccomp($capex, (string) $t->capex_ceiling, 2) > 0;
        $opexOver = $t->opex_ceiling !== null && bccomp($opex, (string) $t->opex_ceiling, 2) > 0;

        return [
            'id' => $t->id, 'scope_label' => $t->scope_label, 'cost_centre_id' => $t->cost_centre_id, 'category_id' => $t->category_id,
            'legal_entity_id' => $t->legal_entity_id,
            'capex_ceiling' => $t->capex_ceiling === null ? null : (string) $t->capex_ceiling,
            'opex_ceiling' => $t->opex_ceiling === null ? null : (string) $t->opex_ceiling,
            'capex_requested' => $capex, 'opex_requested' => $opex,
            'capex_pct' => $this->pct($capex, $t->capex_ceiling), 'opex_pct' => $this->pct($opex, $t->opex_ceiling),
            'capex_over' => $capexOver, 'opex_over' => $opexOver, 'over' => $capexOver || $opexOver,
        ];
    }

    /** Lines (with their budget's cost centre / legal entity) of the latest valid version of each budget in the cycle. */
    public function cycleLines(BudgetCycle $cycle): Collection
    {
        $latest = Budget::withoutGlobalScopes()->where('organization_id', $cycle->organization_id)->where('cycle_id', $cycle->id)
            ->whereNotIn('status', ['withdrawn', 'rejected'])->orderBy('version_no')->get()->keyBy('budget_code');
        if ($latest->isEmpty()) {
            return collect();
        }
        $le = $latest->mapWithKeys(fn ($b) => [$b->id => $b->legal_entity_id]);

        return BudgetLine::withoutGlobalScopes()->where('organization_id', $cycle->organization_id)->whereIn('budget_id', $latest->pluck('id'))
            ->orderBy('sort_order')->orderBy('line_no')->get()->each(function (BudgetLine $l) use ($le) {
                $l->setAttribute('budget_legal_entity_id', $le[$l->budget_id] ?? null);
            });
    }

    /** Lines inside a target's scope: cost-centre subtree, legal entity, TBM pool / tower / category. */
    public function inScope(BudgetTarget $t, Collection $lines): Collection
    {
        $ccIds = null;
        if ($t->cost_centre_id) {
            $cc = CostCentre::withoutGlobalScopes()->find($t->cost_centre_id);
            $ccIds = $cc ? array_merge([$cc->id], $this->descendants((int) $t->organization_id, $cc->id)) : [];
        }

        return $lines->filter(fn (BudgetLine $l) => ($ccIds === null || in_array($l->cost_centre_id, $ccIds, true))
            && (! $t->legal_entity_id || (int) ($l->legal_entity_id ?? $l->budget_legal_entity_id) === (int) $t->legal_entity_id)
            && (! $t->category_id || in_array((int) $t->category_id, [(int) $l->cost_pool_id, (int) $l->tower_id, (int) $l->category_id], true)))->values();
    }

    /** @return array<int> */
    public function descendants(int $orgId, int $ccId): array
    {
        $byParent = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id');
        $found = [];
        $queue = [$ccId];
        while ($queue) {
            foreach ($byParent->get(array_shift($queue), collect()) as $child) {
                if (! in_array($child->id, $found, true)) {
                    $found[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $found;
    }

    /** @return array<int> the cost centre and every ancestor */
    public function ancestorsOrSelf(int $orgId, int $ccId): array
    {
        $parents = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('parent_id', 'id')->all();
        $out = [];
        $id = $ccId;
        while ($id && ! in_array($id, $out, true)) {
            $out[] = $id;
            $id = $parents[$id] ?? null;
        }

        return $out;
    }

    private function pct(string $requested, $ceiling): ?string
    {
        if ($ceiling === null || bccomp((string) $ceiling, '0', 2) <= 0) {
            return null;
        }

        return bcmul(bcdiv($requested, (string) $ceiling, 6), '100', 1);
    }

    private function money($v, string $field): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! preg_match('/^\d{1,16}(\.\d{1,2})?$/', (string) $v)) {
            $this->fail('Ceilings are plain positive amounts with up to 2 decimals.', $field);
        }

        return bcadd((string) $v, '0', 2);
    }

    private function fiscalYear(int $orgId, int $id): FiscalYear
    {
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->find($id);
        $fy || $this->fail('Pick a fiscal year of this bank.', 'fiscal_year_id');
        $fy->status === 'closed' && $this->fail('A closed fiscal year cannot take a new cycle.', 'fiscal_year_id');

        return $fy;
    }

    private function assertWindow(FiscalYear $fy, $opens, $closes): void
    {
        if (! $opens || ! $closes) {
            $this->fail('Give the opening and closing dates.', 'opens_on');
        }
        if (Carbon::parse($opens)->gt(Carbon::parse($closes))) {
            $this->fail('The cycle must open on or before the day it closes.', 'closes_on');
        }
        if (Carbon::parse($closes)->gt($fy->ends_on)) {
            $this->fail('The cycle must close within the fiscal year.', 'closes_on');
        }
    }

    private function assertType(?string $type): void
    {
        in_array($type, BudgetCycle::TYPES, true) || $this->fail('Unknown cycle type.', 'type');
    }

    private function coordinator(int $orgId, $id): ?int
    {
        if (! $id) {
            return null;
        }
        User::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($id)->exists() || $this->fail('Pick a person in this bank.', 'coordinator_id');

        return (int) $id;
    }
}
