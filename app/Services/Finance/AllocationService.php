<?php

namespace App\Services\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Actual;
use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationResult;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Facades\DB;

/**
 * Allocation of IT cost onto business units, services, applications and legal entities (FIN-02.12; plan P6-5 to P6-8).
 * Rules share a source's spend by percentage, driver quantity or directly; spend already tagged to a target is attributed directly and never
 * pooled. Rounding is largest-remainder, so the results of a group sum to its pool exactly. Results of a period freeze when it closes.
 */
class AllocationService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    private const SOURCE_RANK = ['budget_line' => 0, 'contract' => 1, 'category' => 2, 'cost_centre' => 3];

    /** @var array<string,mixed> request-lifetime memo of the ledger per fiscal year */
    private array $memo = [];

    public function __construct(private readonly MonitoringService $monitoring, private readonly FinanceSettings $settings) {}

    private function sameBank(object $record, User $by): void
    {
        (int) $record->organization_id === (int) $by->organization_id || $this->fail('That record belongs to another bank.');
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /* ---------------------------------------------------------------- rules */

    /** @param array<string,mixed> $data */
    public function store(array $data, User $by): Allocation
    {
        $orgId = (int) $by->organization_id;
        $clean = $this->clean($data, $orgId, null);
        $rule = Allocation::withoutGlobalScopes()->create($clean + [
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'allocation_code' => Allocation::generateNextCode($orgId),
        ]);
        $this->audit($rule, $by, 'created', "Allocation rule {$rule->allocation_code} {$rule->name} created");

        return $rule->refresh();
    }

    /** @param array<string,mixed> $data */
    public function update(Allocation $a, array $data, User $by): Allocation
    {
        $this->sameBank($a, $by);
        $hasResults = $a->results()->exists();
        if ($hasResults) {
            foreach (['source_type', 'source_id', 'target_type', 'target_id', 'target_ref', 'method'] as $k) {
                if (array_key_exists($k, $data) && (string) ($data[$k] ?? '') !== (string) ($a->{$k} ?? '')) {
                    $this->fail('This rule has computed results: end it and create a new rule instead of changing its source, target or method.', $k);
                }
            }
        }
        $merged = $data + $a->only(['name', 'source_type', 'source_id', 'target_type', 'target_id', 'target_ref', 'method', 'percentage', 'driver_id', 'driver_quantity', 'journal_cost_centre_code', 'notes', 'is_active'])
            + ['effective_from' => $a->effective_from->toDateString(), 'effective_to' => $a->effective_to?->toDateString()];
        $clean = $this->clean($merged, (int) $a->organization_id, $a);
        $a->fill($clean)->save();
        $this->audit($a, $by, 'updated', "Allocation rule {$a->allocation_code} updated");

        return $a->refresh();
    }

    public function end(Allocation $a, string $effectiveTo, User $by): Allocation
    {
        $this->sameBank($a, $by);
        $to = $this->date($effectiveTo, 'effective_to')->toDateString();
        $to >= $a->effective_from->toDateString() || $this->fail('A rule cannot end before it starts.', 'effective_to');
        $this->assertNotInFrozenPast((int) $a->organization_id, null, $to);
        $a->forceFill(['effective_to' => $to])->save();
        $this->audit($a, $by, 'ended', "Allocation rule {$a->allocation_code} ends on {$to}");

        return $a->refresh();
    }

    public function destroy(Allocation $a, User $by): void
    {
        $this->sameBank($a, $by);
        $a->results()->exists() && $this->fail('This rule has computed results; end it instead of deleting it.');
        $this->audit($a, $by, 'deleted', "Allocation rule {$a->allocation_code} deleted");
        $a->delete();
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function clean(array $d, int $orgId, ?Allocation $self): array
    {
        $name = trim((string) ($d['name'] ?? ''));
        ($name !== '' && mb_strlen($name) <= 160) || $this->fail('Give the rule a name (max 160 characters).', 'name');
        $sourceType = (string) ($d['source_type'] ?? '');
        in_array($sourceType, Allocation::SOURCE_TYPES, true) || $this->fail('Pick what is being allocated (cost centre, category, budget line or contract).', 'source_type');
        $sourceId = (int) ($d['source_id'] ?? 0);
        $this->assertSource($orgId, $sourceType, $sourceId);
        $targetType = (string) ($d['target_type'] ?? '');
        in_array($targetType, Allocation::TARGET_TYPES, true) || $this->fail('Pick who the cost is allocated to.', 'target_type');
        $targetId = null;
        $targetRef = null;
        if ($targetType === 'business_unit') {
            $targetRef = strtoupper(trim((string) ($d['target_ref'] ?? '')));
            collect($this->settings->showbackUnits($orgId))->contains(fn ($u) => ($u['code'] ?? null) === $targetRef) || $this->fail('Pick one of the bank\'s showback business units (Settings, FinOps & showback).', 'target_ref');
        } else {
            $targetId = (int) ($d['target_id'] ?? 0);
            $this->assertTarget($orgId, $targetType, $targetId);
        }
        $method = (string) ($d['method'] ?? '');
        in_array($method, Allocation::METHODS, true) || $this->fail('Pick an allocation method.', 'method');
        $pct = null;
        $driverId = null;
        $qty = null;
        if ($method === 'direct') {
            $pct = '100.0000';
        } elseif ($method === 'percentage') {
            $v = is_scalar($d['percentage'] ?? null) ? trim((string) $d['percentage']) : '';
            (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $v) && bccomp($v, '0', 4) > 0 && bccomp($v, '100', 4) <= 0) || $this->fail('The percentage must be above 0 and at most 100.', 'percentage');
            $pct = bcadd($v, '0', 4);
        } else {
            Driver::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey((int) ($d['driver_id'] ?? 0))->exists() || $this->fail('Pick a cost driver of this bank.', 'driver_id');
            $driverId = (int) $d['driver_id'];
            $qty = $this->quantity($d['driver_quantity'] ?? null, 'driver_quantity');
        }
        $from = $this->date($d['effective_from'] ?? null, 'effective_from')->toDateString();
        $to = ! empty($d['effective_to']) ? $this->date($d['effective_to'], 'effective_to')->toDateString() : null;
        ($to === null || $to >= $from) || $this->fail('The rule cannot end before it starts.', 'effective_to');
        // Only dates that change are checked, so a rule that started before the open floor can still be edited (P6-M6).
        $this->assertNotInFrozenPast($orgId, $self && $self->effective_from->toDateString() === $from ? null : $from, $self && $self->effective_to?->toDateString() === $to ? null : $to);
        $journal = isset($d['journal_cost_centre_code']) ? trim((string) $d['journal_cost_centre_code']) : '';
        mb_strlen($journal) <= 32 || $this->fail('The journal cost-centre code is too long (max 32 characters).', 'journal_cost_centre_code');
        $active = array_key_exists('is_active', $d) ? (bool) $d['is_active'] : true;

        $rule = [
            'name' => $name, 'source_type' => $sourceType, 'source_id' => $sourceId, 'target_type' => $targetType, 'target_id' => $targetId, 'target_ref' => $targetRef,
            'method' => $method, 'percentage' => $pct, 'driver_id' => $driverId, 'driver_quantity' => $qty, 'effective_from' => $from, 'effective_to' => $to, 'is_active' => $active,
            'journal_cost_centre_code' => $journal === '' ? null : $journal, 'notes' => isset($d['notes']) ? (trim((string) $d['notes']) ?: null) : null,
        ];
        $active && $this->assertGroup($orgId, $rule, $self);

        return $rule;
    }

    /** One method per group, 100 % at most across overlapping windows, a single rule for direct (plan P6-5). */
    private function assertGroup(int $orgId, array $rule, ?Allocation $self): void
    {
        $others = Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->where('source_type', $rule['source_type'])->where('source_id', $rule['source_id'])
            ->where('target_type', $rule['target_type'])->where('is_active', true)->whereNull('deleted_at')->when($self, fn ($q) => $q->where('id', '!=', $self->id))->get()
            ->filter(fn (Allocation $o) => $this->overlap($rule['effective_from'], $rule['effective_to'], $o->effective_from->toDateString(), $o->effective_to?->toDateString()));
        $others->contains(fn ($o) => $o->method !== $rule['method']) && $this->fail('This source already has rules with another method for the same target type; one method per source and target type.', 'method');
        if ($rule['method'] === 'direct') {
            $others->isNotEmpty() && $this->fail('A direct rule is the only rule of its source and target type in its window.', 'method');

            return;
        }
        if ($rule['method'] !== 'percentage') {
            return;
        }
        $dates = collect([$rule['effective_from']])->merge($others->map(fn ($o) => $o->effective_from->toDateString())->filter(
            fn ($d) => $d >= $rule['effective_from'] && ($rule['effective_to'] === null || $d <= $rule['effective_to'])
        ));
        foreach ($dates as $day) {
            $sum = $others->filter(fn ($o) => $o->effective_from->toDateString() <= $day && ($o->effective_to === null || $o->effective_to->toDateString() >= $day))
                ->reduce(fn ($c, $o) => bcadd($c, (string) $o->percentage, 4), (string) $rule['percentage']);
            bccomp($sum, '100', 4) > 0 && $this->fail("The percentages of this source would add up to {$sum} % on {$day}; they may not exceed 100 %.", 'percentage');
        }
    }

    private function overlap(string $aFrom, ?string $aTo, string $bFrom, ?string $bTo): bool
    {
        return $aFrom <= ($bTo ?? '9999-12-31') && ($aTo ?? '9999-12-31') >= $bFrom;
    }

    /** Rules cannot rewrite history: no date earlier than the first day of the earliest open period (plan P6-8). */
    private function assertNotInFrozenPast(int $orgId, ?string $from, ?string $to): void
    {
        $floor = $this->openFloor($orgId);
        if ($floor === null) {
            return;
        }
        foreach (['effective_from' => $from, 'effective_to' => $to] as $k => $d) {
            $d !== null && $d < $floor && $this->fail("A rule date cannot be earlier than {$floor}, the first day of the earliest open period.", $k);
        }
    }

    /** First day of the earliest open period, else the day after the last period; null when the bank has no periods. */
    public function openFloor(int $orgId): ?string
    {
        $q = fn () => Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false);
        $open = $q()->where('status', 'open')->min('starts_on');
        if ($open !== null) {
            return substr((string) $open, 0, 10);
        }
        $last = $q()->max('ends_on');

        return $last === null ? null : date('Y-m-d', strtotime(substr((string) $last, 0, 10).' +1 day'));
    }

    private function assertSource(int $orgId, string $type, int $id): void
    {
        $ok = match ($type) {
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereKey($id)->exists(),
            'category' => Category::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereKey($id)->exists(),
            'budget_line' => BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($id)->exists(),
            'contract' => Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($id)->exists(),
            default => false,
        };
        $ok || $this->fail('That source does not belong to this bank.', 'source_id');
    }

    private function assertTarget(int $orgId, string $type, int $id): void
    {
        $ea = fn (string $class) => $class::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->whereKey($id)->exists();
        $ok = match ($type) {
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereKey($id)->exists(),
            'business_service' => $ea(BusinessService::class),
            'ea_application' => $ea(EaApplication::class),
            'investment' => Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($id)->exists(),
            'legal_entity' => LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($id)->exists(),
            default => false,
        };
        $ok || $this->fail('That target does not belong to this bank.', 'target_id');
    }

    /* ---------------------------------------------------------------- shares */

    /**
     * Share (percent, scale 6) of each rule effective in the period, by group "source_type:source_id".
     *
     * @return array<string,array<int,string>>
     */
    public function shares(int $orgId, string $targetType, Period $p): array
    {
        $rules = $this->rulesFor($orgId, $targetType, $p);
        $out = [];
        foreach ($rules->groupBy(fn ($r) => $r->source_type.':'.$r->source_id) as $key => $group) {
            $qty = $group->reduce(fn ($c, $r) => bcadd($c, $r->method === 'driver' ? (string) $r->driver_quantity : '0', 4), '0');
            foreach ($group as $r) {
                $out[$key][(int) $r->id] = match ($r->method) {
                    'direct' => '100.000000',
                    'percentage' => bcadd((string) $r->percentage, '0', 6),
                    default => bccomp($qty, '0', 4) > 0 ? bcadd(bcmul(bcdiv((string) $r->driver_quantity, $qty, 10), '100', 10), '0', 6) : '0.000000',
                };
            }
        }

        return $out;
    }

    /** Rules that apply to a period: effective on its first day. @return \Illuminate\Support\Collection<int,Allocation> */
    private function rulesFor(int $orgId, string $targetType, Period $p)
    {
        $day = $p->starts_on->toDateString();

        return RequestMemo::remember("rulesFor:{$orgId}:{$targetType}:{$day}", fn () => Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->where('target_type', $targetType)->where('is_active', true)->whereNull('deleted_at')
            ->whereDate('effective_from', '<=', $day)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))->orderBy('id')->get());
    }

    /* ---------------------------------------------------------------- ledger and pools */

    /**
     * What a lens allocates in a period (plan P6-6): the pool of each source by cost pool, the spend already tagged to a target (direct), and the IT total.
     *
     * @param  list<int>|null  $ccIds
     * @return array{sources:list<array<string,mixed>>,direct:list<array<string,mixed>>,it_total:string,by_pool_total:array<string,string>}
     */
    public function pools(int $orgId, string $targetType, Period $p, string $amountType, ?array $ccIds = null): array
    {
        $items = $this->items($orgId, $p, $amountType, $ccIds);
        $rules = $this->rulesFor($orgId, $targetType, $p);
        $groups = $rules->unique(fn ($r) => $r->source_type.':'.$r->source_id)->map(fn ($r) => ['type' => $r->source_type, 'id' => (int) $r->source_id])->values()->all();
        $anc = $this->ancestry($orgId);
        $sources = [];
        $direct = [];
        $itTotal = '0.00';
        $byPoolTotal = [];
        foreach ($items as $it) {
            $itTotal = bcadd($itTotal, $it['amount'], 2);
            $poolKey = (string) ($it['pool'] ?? 0);
            $byPoolTotal[$poolKey] = bcadd($byPoolTotal[$poolKey] ?? '0.00', $it['amount'], 2);
            $tag = match ($targetType) {
                'ea_application' => $it['app'] ? 'ea_application:'.$it['app'] : null,
                'business_service' => $it['svc'] ? 'business_service:'.$it['svc'] : null,
                'investment' => $it['inv'] ? 'investment:'.$it['inv'] : null,
                'legal_entity' => $it['le'] ? 'legal_entity:'.$it['le'] : null,
                default => null,
            };
            if ($tag !== null) {
                $direct[$tag]['by_pool'][$poolKey] = bcadd($direct[$tag]['by_pool'][$poolKey] ?? '0.00', $it['amount'], 2);

                continue;
            }
            $best = null;
            foreach ($groups as $g) {
                $depth = $this->matchDepth($g['type'], $g['id'], $it, $anc);
                if ($depth === null) {
                    continue;
                }
                $rank = [self::SOURCE_RANK[$g['type']], -$depth, $g['id']];
                if ($best === null || $rank < $best['rank']) {
                    $best = ['rank' => $rank, 'g' => $g];
                }
            }
            if ($best) {
                $k = $best['g']['type'].':'.$best['g']['id'];
                $sources[$k]['source_type'] = $best['g']['type'];
                $sources[$k]['source_id'] = $best['g']['id'];
                $sources[$k]['by_pool'][$poolKey] = bcadd($sources[$k]['by_pool'][$poolKey] ?? '0.00', $it['amount'], 2);
                $cc = (string) ($it['cc'] ?? 0);
                $sources[$k]['by_pool_cc'][$poolKey][$cc] = bcadd($sources[$k]['by_pool_cc'][$poolKey][$cc] ?? '0.00', $it['amount'], 2);
            }
        }

        return [
            'sources' => array_values($sources),
            'direct' => collect($direct)->map(fn ($v, $k) => ['target_key' => $k, 'by_pool' => $v['by_pool']])->values()->all(),
            'it_total' => $itTotal, 'by_pool_total' => $byPoolTotal,
        ];
    }

    /** Depth of the match (larger is more specific), or null when the item is not in the source. */
    private function matchDepth(string $type, int $id, array $it, array $anc): ?int
    {
        return match ($type) {
            'budget_line' => in_array($id, $it['lineage'], true) ? 0 : null,
            'contract' => $it['contract'] === $id ? 0 : null,
            'category' => in_array($id, $it['cats'], true) ? count($anc['cat'][$id] ?? [$id]) : null,
            'cost_centre' => in_array($id, $it['ccs'], true) ? count($anc['cc'][$id] ?? [$id]) : null,
            default => null,
        };
    }

    /**
     * Every counted ledger item of a fiscal year (budget or actual) with its period, for the TCO views.
     *
     * @param  list<int>|null  $ccIds
     * @return array<int,list<array<string,mixed>>> period id => items
     */
    public function fyItems(int $orgId, int $fiscalYearId, string $amountType, ?array $ccIds = null): array
    {
        $key = "items:{$orgId}:{$fiscalYearId}:{$amountType}:".($ccIds === null ? '*' : md5(json_encode($ccIds)));

        return $this->memo[$key] ??= $this->buildItems($orgId, $fiscalYearId, $amountType, $ccIds);
    }

    /** @return array{cat:array<int,list<int>>,cc:array<int,list<int>>,root:array<int,?int>} ancestors including self */
    private function ancestry(int $orgId): array
    {
        return $this->memo["anc:{$orgId}"] ??= (function () use ($orgId) {
            $chain = function ($rows) {
                $parent = $rows->pluck('parent_id', 'id');
                $out = [];
                foreach ($parent as $id => $_) {
                    $c = [(int) $id];
                    $p = $parent[$id];
                    for ($h = 0; $p && $h < 10 && ! in_array((int) $p, $c, true); $h++) {
                        $c[] = (int) $p;
                        $p = $parent[$p] ?? null;
                    }
                    $out[(int) $id] = $c;
                }

                return $out;
            };

            return [
                'cat' => $chain(Category::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'parent_id'])),
                'cc' => $chain(CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'parent_id'])),
                'root' => CostPools::map($orgId)['root'],
            ];
        })();
    }

    /**
     * The counted ledger items of a period for one amount type (budget = baseline period amounts; actual = counted actuals).
     *
     * @param  list<int>|null  $ccIds
     * @return list<array<string,mixed>>
     */
    private function items(int $orgId, Period $p, string $amountType, ?array $ccIds): array
    {
        $key = "items:{$orgId}:{$p->fiscal_year_id}:{$amountType}:".($ccIds === null ? '*' : md5(json_encode($ccIds)));
        $byPeriod = $this->memo[$key] ??= $this->buildItems($orgId, (int) $p->fiscal_year_id, $amountType, $ccIds);

        return $byPeriod[(int) $p->id] ?? [];
    }

    /** @return array<int,list<array<string,mixed>>> */
    private function buildItems(int $orgId, int $fyId, string $amountType, ?array $ccIds): array
    {
        $anc = $this->ancestry($orgId);
        $lines = $this->monitoring->baselineLines($orgId, $fyId, null)->keyBy('id');
        $lineage = $this->monitoring->lineage($orgId, $lines);
        $owner = [];
        foreach ($lineage as $lineId => $ids) {
            foreach ($ids as $id) {
                $owner[$id] ??= $lineId;
            }
        }
        $expand = function (array $ids, string $kind) use ($anc): array {
            $out = [];
            foreach (array_filter($ids) as $id) {
                $out = array_merge($out, $anc[$kind][(int) $id] ?? [(int) $id]);
            }

            return array_values(array_unique($out));
        };
        $fromLine = function (BudgetLine $l, string $amount, ?Actual $a = null, ?int $cc = null, ?int $category = null, ?int $contract = null, ?int $inv = null, ?int $app = null) use ($expand, $lineage): array {
            return [
                'cc' => $cc ?? (int) $l->cost_centre_id, 'ccs' => $expand([$cc ?? $l->cost_centre_id], 'cc'),
                'cats' => $expand([$l->cost_pool_id, $l->tower_id, $l->category_id, $category], 'cat'), 'lineage' => $lineage[(int) $l->id] ?? [(int) $l->id],
                'contract' => $contract ?? ($l->contract_id ? (int) $l->contract_id : null), 'app' => $app ?? ($l->ea_application_id ? (int) $l->ea_application_id : null),
                'svc' => $l->business_service_id ? (int) $l->business_service_id : null, 'inv' => $inv ?? ($l->investment_id ? (int) $l->investment_id : null),
                'le' => $l->legal_entity_id ? (int) $l->legal_entity_id : null, 'pool' => $l->cost_pool_id ? (int) $l->cost_pool_id : null, 'amount' => $amount,
            ];
        };

        $out = [];
        if ($amountType === 'budget') {
            $fig = $this->monitoring->lineFigures($lines, $fyId);
            foreach ($lines as $l) {
                if ($ccIds !== null && ! in_array((int) $l->cost_centre_id, $ccIds, true)) {
                    continue;
                }
                foreach ($fig[$l->id]['by_period'] ?? [] as $pid => $cell) {
                    if (bccomp($cell['budget'], '0', 2) !== 0) {
                        $out[(int) $pid][] = $fromLine($l, $cell['budget']);
                    }
                }
            }

            return $out;
        }

        $rows = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fyId)->where('match_status', '!=', 'duplicate')
            ->when($ccIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $ccIds ?: [0]))
            ->selectRaw('period_id, cost_centre_id, category_id, contract_id, budget_line_id, investment_id, ea_application_id, SUM(base_amount) as t')
            ->groupBy('period_id', 'cost_centre_id', 'category_id', 'contract_id', 'budget_line_id', 'investment_id', 'ea_application_id')->get();
        foreach ($rows as $r) {
            $own = $r->budget_line_id ? ($owner[(int) $r->budget_line_id] ?? null) : null;
            $line = $own ? $lines[$own] : null;
            $amount = bcadd((string) $r->t, '0', 2);
            if ($line) {
                $it = $fromLine($line, $amount, null, (int) $r->cost_centre_id, $r->category_id ? (int) $r->category_id : null, $r->contract_id ? (int) $r->contract_id : null,
                    $r->investment_id ? (int) $r->investment_id : null, $r->ea_application_id ? (int) $r->ea_application_id : null);
            } else {
                $cat = $r->category_id ? (int) $r->category_id : null;
                $it = [
                    'cc' => (int) $r->cost_centre_id, 'ccs' => $expand([$r->cost_centre_id], 'cc'), 'cats' => $expand([$cat], 'cat'), 'lineage' => $r->budget_line_id ? [(int) $r->budget_line_id] : [],
                    'contract' => $r->contract_id ? (int) $r->contract_id : null, 'app' => $r->ea_application_id ? (int) $r->ea_application_id : null, 'svc' => null,
                    'inv' => $r->investment_id ? (int) $r->investment_id : null, 'le' => null, 'pool' => $cat ? ($anc['root'][$cat] ?? null) : null, 'amount' => $amount,
                ];
            }
            $out[(int) $r->period_id][] = $it;
        }

        return $out;
    }

    /* ---------------------------------------------------------------- computation */

    /**
     * Computes the results of one lens, period and amount type without storing them.
     *
     * @param  list<int>|null  $ccIds
     * @return array{rows:list<array<string,mixed>>,pool_total:string,allocated_total:string,direct_total:string,it_total:string,pools:array<string,mixed>}
     */
    public function evaluate(int $orgId, string $targetType, Period $p, string $amountType, ?array $ccIds = null): array
    {
        $pools = $this->pools($orgId, $targetType, $p, $amountType, $ccIds);
        $shares = $this->shares($orgId, $targetType, $p);
        $weights = $this->rulesFor($orgId, $targetType, $p)->where('method', 'driver')->mapWithKeys(fn ($r) => [(int) $r->id => (string) $r->driver_quantity])->all();
        $rows = [];
        $poolTotal = '0.00';
        $allocated = '0.00';
        foreach ($pools['sources'] as $src) {
            $gShares = $shares[$src['source_type'].':'.$src['source_id']] ?? [];
            if (! $gShares) {
                continue;
            }
            foreach ($src['by_pool'] as $poolKey => $amount) {
                $poolTotal = bcadd($poolTotal, $amount, 2);
                $driverGroup = $weights && ! array_diff_key($gShares, $weights);
                $split = $driverGroup ? $this->splitByWeights($amount, array_intersect_key($weights, $gShares)) : $this->largestRemainder($amount, $gShares);
                foreach ($split as $allocationId => $cents) {
                    $rows[] = [
                        'allocation_id' => $allocationId, 'cost_pool_id' => (int) $poolKey ?: null, 'pool_base_amount' => $amount, 'share_pct' => $gShares[$allocationId],
                        'base_amount' => $cents, 'source_type' => $src['source_type'], 'source_id' => $src['source_id'], 'by_cc' => $src['by_pool_cc'][$poolKey] ?? [],
                    ];
                    $allocated = bcadd($allocated, $cents, 2);
                }
            }
        }
        $direct = '0.00';
        foreach ($pools['direct'] as $d) {
            foreach ($d['by_pool'] as $v) {
                $direct = bcadd($direct, $v, 2);
            }
        }

        return ['rows' => $rows, 'pool_total' => $poolTotal, 'allocated_total' => $allocated, 'direct_total' => $direct, 'it_total' => $pools['it_total'], 'pools' => $pools];
    }

    /**
     * Splits round(pool x sum of shares) across rules by shares in whole cents; the remaining cents go to the largest remainders.
     *
     * @param  array<int,string>  $shares  allocation id => percent (scale 6)
     * @return array<int,string> allocation id => amount
     */
    public function largestRemainder(string $pool, array $shares): array
    {
        if (bccomp($pool, '0', 2) < 0) { // a net credit pool: split its magnitude, then restore the sign
            return array_map(fn ($v) => bccomp($v, '0', 2) === 0 ? '0.00' : '-'.$v, $this->largestRemainder(ltrim($pool, '-'), $shares));
        }
        $cents = bcmul($pool, '100', 0);
        $sum = array_reduce($shares, fn ($c, $s) => bcadd($c, $s, 6), '0');
        $total = $this->roundHalfUp(bcdiv(bcmul($cents, $sum, 10), '100', 10));
        bccomp($total, $cents, 0) > 0 && $total = $cents;
        $floors = [];
        $frac = [];
        $floorSum = '0';
        foreach ($shares as $id => $s) {
            $exact = bcdiv(bcmul($cents, $s, 12), '100', 8);
            $floors[$id] = bcadd($exact, '0', 0);
            $frac[$id] = bcsub($exact, $floors[$id], 8);
            $floorSum = bcadd($floorSum, $floors[$id], 0);
        }
        $left = (int) bcsub($total, $floorSum, 0);
        $order = array_keys($shares);
        usort($order, fn ($a, $b) => [bccomp($frac[$b], $frac[$a], 8), $a] <=> [0, $b]);
        foreach ($order as $id) {
            if ($left <= 0) {
                break;
            }
            $floors[$id] = bcadd($floors[$id], '1', 0);
            $left--;
        }

        return array_map(fn ($c) => bcdiv($c, '100', 2), $floors);
    }

    /**
     * Splits an amount over weights in whole cents so the parts add up to it exactly; leftover cents go to the largest remainders (ties to the lower key).
     *
     * @param  array<int|string,string>  $weights
     * @return array<int|string,string>
     */
    public function splitByWeights(string $amount, array $weights): array
    {
        $neg = bccomp($amount, '0', 2) < 0;
        $cents = bcmul(ltrim($amount, '-'), '100', 0);
        $sum = array_reduce($weights, fn ($c, $w) => bcadd($c, $w, 4), '0');
        if (bccomp($sum, '0', 4) <= 0) {
            return array_map(fn () => '0.00', $weights);
        }
        $floors = [];
        $frac = [];
        $floorSum = '0';
        foreach ($weights as $k => $w) {
            $exact = bcdiv(bcmul($cents, $w, 8), $sum, 8);
            $floors[$k] = bcadd($exact, '0', 0);
            $frac[$k] = bcsub($exact, $floors[$k], 8);
            $floorSum = bcadd($floorSum, $floors[$k], 0);
        }
        $left = (int) bcsub($cents, $floorSum, 0);
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => [bccomp($frac[$b], $frac[$a], 8), $a] <=> [0, $b]);
        foreach ($order as $k) {
            if ($left-- <= 0) {
                break;
            }
            $floors[$k] = bcadd($floors[$k], '1', 0);
        }

        return array_map(fn ($c) => ($neg && bccomp($c, '0', 0) !== 0 ? '-' : '').bcdiv($c, '100', 2), $floors);
    }

    private function roundHalfUp(string $v): string
    {
        return bcadd($v, '0.5', 0);
    }

    /**
     * Computes and stores the results of the periods of a fiscal year (plan P6-8). Open periods are recomputed; closed and locked ones only when
     * they have no results yet, otherwise they are skipped as frozen.
     *
     * @param  list<int>|null  $periodIds
     * @param  list<string>  $amountTypes
     * @return list<array{period_id:int,amount_type:string,target_type:string,status:string,results:int,allocated_total:string}>
     */
    public function run(int $orgId, int $fiscalYearId, ?array $periodIds, array $amountTypes, User $by): array
    {
        FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->find($fiscalYearId) || $this->fail('Pick a fiscal year of this bank.', 'fiscal_year_id');
        $amountTypes = array_values(array_intersect($amountTypes ?: Allocation::AMOUNT_TYPES, Allocation::AMOUNT_TYPES));
        $amountTypes || $this->fail('Pick budget, actual or both.', 'amount_types');
        $periods = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->where('is_adjustment', false)
            ->when($periodIds, fn ($q) => $q->whereIn('id', $periodIds))->when(! $periodIds, fn ($q) => $q->whereDate('starts_on', '<=', FinanceClock::today()->toDateString()))->orderBy('sequence')->get();
        $this->flush();
        $out = [];
        foreach ($periods as $p) {
            foreach ($amountTypes as $type) {
                foreach (Allocation::TARGET_TYPES as $lens) {
                    $out[] = $this->computePeriod($orgId, $lens, $p, $type, $by, false);
                }
            }
        }
        $this->flush();
        $out = array_values(array_filter($out, fn ($r) => $r['status'] !== 'none'));
        $computed = collect($out)->where('status', 'computed')->count();
        $computed && activity('it-finance')->causedBy($by)->event('allocation_run')->withProperties(['fiscal_year_id' => $fiscalYearId, 'runs' => $computed])->log("Allocations computed for {$computed} period/lens/basis combinations");

        return $out;
    }

    /** Final computation of a period just before it closes, then the results are frozen with it (plan P6-8). */
    public function freeze(Period $p, User $by): void
    {
        $orgId = (int) $p->organization_id;
        $this->flush();
        foreach (Allocation::AMOUNT_TYPES as $type) {
            foreach (Allocation::TARGET_TYPES as $lens) {
                $r = $this->computePeriod($orgId, $lens, $p, $type, $by, true);
                $r['status'] === 'none' || AllocationRun::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)
                    ->where('amount_type', $type)->where('target_type', $lens)->update(['is_frozen' => true]);
            }
        }
        $this->flush();
    }

    /** @return array{period_id:int,amount_type:string,target_type:string,status:string,results:int,allocated_total:string} */
    private function computePeriod(int $orgId, string $lens, Period $p, string $type, User $by, bool $force): array
    {
        $base = ['period_id' => (int) $p->id, 'amount_type' => $type, 'target_type' => $lens, 'results' => 0, 'allocated_total' => '0.00'];
        if ($this->rulesFor($orgId, $lens, $p)->isEmpty()) {
            return $base + ['status' => 'none'];
        }
        $existing = AllocationRun::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->where('amount_type', $type)->where('target_type', $lens)->first();
        if (! $force && $p->status !== 'open' && $existing) {
            return ['results' => (int) $existing->results_count, 'allocated_total' => (string) $existing->allocated_total] + $base + ['status' => 'frozen'];
        }
        $e = $this->evaluate($orgId, $lens, $p, $type, null);
        DB::transaction(function () use ($orgId, $lens, $p, $type, $by, $e, $existing, &$base) {
            $existing?->results()->delete();
            $now = now();
            $run = $existing ?? new AllocationRun(['organization_id' => $orgId, 'created_by_id' => $by->id]);
            $run->forceFill([
                'organization_id' => $orgId, 'fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id, 'amount_type' => $type, 'target_type' => $lens,
                'pool_total' => $e['pool_total'], 'allocated_total' => $e['allocated_total'], 'direct_total' => $e['direct_total'], 'it_total' => $e['it_total'],
                'results_count' => count($e['rows']), 'computed_at' => $now, 'computed_by_id' => $by->id, 'is_frozen' => false,
            ])->save();
            $insert = array_map(fn ($r) => [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'allocation_id' => $r['allocation_id'], 'run_id' => $run->id, 'period_id' => $p->id, 'amount_type' => $type,
                'cost_pool_id' => $r['cost_pool_id'], 'pool_base_amount' => $r['pool_base_amount'], 'share_pct' => $r['share_pct'], 'base_amount' => $r['base_amount'],
                'computed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ], $e['rows']);
            foreach (array_chunk($insert, 500) as $chunk) {
                DB::table('fin_allocation_results')->insert($chunk);
            }
            activity('it-finance')->performedOn($run)->causedBy($by)->event('computed')->withProperties(['results' => count($insert), 'allocated' => $e['allocated_total']])
                ->log("Allocation results computed: {$lens} {$type} {$p->name}");
            $base['results'] = count($insert);
            $base['allocated_total'] = $e['allocated_total'];
        });

        return $base + ['status' => 'computed'];
    }

    /**
     * The allocated figures to report for a lens, period and basis: the stored results when the period has been computed (and the viewer sees every
     * cost centre), else computed on the fly.
     *
     * @param  list<int>|null  $ccIds
     * @return array{rows:list<array<string,mixed>>,pools:array<string,mixed>,it_total:string,stored:bool}
     */
    public function primeOutcomes(int $orgId, string $targetType, iterable $periodIds, array $amountTypes): void
    {
        $periodIds = collect($periodIds)->map(fn ($i) => (int) $i)->unique()->values();
        if ($periodIds->isEmpty()) {
            return;
        }
        $runs = AllocationRun::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('period_id', $periodIds)->whereIn('amount_type', $amountTypes)->where('target_type', $targetType)->get();
        $results = $runs->isEmpty() ? collect() : AllocationResult::withoutGlobalScopes()->whereIn('run_id', $runs->pluck('id'))->get()->groupBy('run_id');
        $byKey = $runs->keyBy(fn ($r) => "{$r->period_id}:{$r->amount_type}");
        foreach ($periodIds as $pid) {
            foreach ($amountTypes as $type) {
                $run = $byKey["{$pid}:{$type}"] ?? null;
                RequestMemo::remember("run:{$orgId}:{$pid}:{$type}:{$targetType}", fn () => $run);
                $run && RequestMemo::remember("results:{$run->id}", fn () => $results->get($run->id, collect()));
            }
        }
    }

    public function outcome(int $orgId, string $targetType, Period $p, string $amountType, ?array $ccIds): array
    {
        return RequestMemo::remember('outcome:'.md5(json_encode([$orgId, $targetType, $p->id, $amountType, $ccIds])), fn () => $this->resolveOutcome($orgId, $targetType, $p, $amountType, $ccIds));
    }

    /** @return array{rows:list<array<string,mixed>>,pools:array<string,mixed>,it_total:string,stored:bool} */
    private function resolveOutcome(int $orgId, string $targetType, Period $p, string $amountType, ?array $ccIds): array
    {
        $e = null;
        $run = $ccIds === null ? RequestMemo::remember("run:{$orgId}:{$p->id}:{$amountType}:{$targetType}", fn () => AllocationRun::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->where('amount_type', $amountType)->where('target_type', $targetType)->first()) : null;
        $pools = $this->pools($orgId, $targetType, $p, $amountType, $ccIds);
        if ($run) {
            $rows = RequestMemo::remember("results:{$run->id}", fn () => AllocationResult::withoutGlobalScopes()->where('run_id', $run->id)->get())->map(fn ($r) => [
                'allocation_id' => (int) $r->allocation_id, 'cost_pool_id' => $r->cost_pool_id ? (int) $r->cost_pool_id : null, 'pool_base_amount' => (string) $r->pool_base_amount,
                'share_pct' => (string) $r->share_pct, 'base_amount' => (string) $r->base_amount,
            ])->all();

            return ['rows' => $rows, 'pools' => $pools, 'it_total' => $pools['it_total'], 'stored' => true];
        }
        $e = $this->evaluate($orgId, $targetType, $p, $amountType, $ccIds);

        return ['rows' => $e['rows'], 'pools' => $e['pools'], 'it_total' => $e['it_total'], 'stored' => false];
    }
}
