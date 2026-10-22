<?php

namespace App\Services\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Allocation;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Showback statements (plan P6-9): per lens and period range, what each target consumed directly or through allocation, by cost pool,
 * with the unallocated IT cost retained, so that the statement reconciles to the IT total to the cent.
 */
class ShowbackService
{
    public function __construct(private readonly AllocationService $allocations, private readonly FinanceSettings $settings) {}

    /** @return list<array{code:string,name:string,erp_cost_centre_code:?string,label:string}> */
    public function units(int $orgId): array
    {
        return array_map(fn ($u) => $u + ['label' => $u['code'].' '.$u['name']], $this->settings->showbackUnits($orgId));
    }

    /**
     * @param  list<int>|null  $ccIds
     * @return array{target_type:string,pools:list<array<string,mixed>>,rows:list<array<string,mixed>>,totals:array<string,mixed>}
     */
    public function statement(int $orgId, string $targetType, int $fiscalYearId, int $fromPeriodId, int $toPeriodId, string $basis, ?array $ccIds, User $viewer): array
    {
        $basis = $basis === 'budget' ? 'budget' : 'actual';
        $periods = $this->periods($orgId, $fiscalYearId, $fromPeriodId, $toPeriodId);
        $bases = array_unique([$basis, 'budget']);
        $cells = []; // basis => target_key => ['direct' => [pool => amt], 'allocated' => [pool => amt]]
        $itTotal = ['budget' => '0.00', 'actual' => '0.00'];
        $ruleTarget = [];
        $this->allocationsOf($orgId, $targetType)->each(function (Allocation $a) use (&$ruleTarget) {
            $ruleTarget[(int) $a->id] = $a->target_type === 'business_unit' ? 'business_unit:'.$a->target_ref : $a->target_type.':'.$a->target_id;
        });
        foreach ($periods as $p) {
            foreach ($bases as $b) {
                $o = $this->allocations->outcome($orgId, $targetType, $p, $b, $ccIds);
                $itTotal[$b] = bcadd($itTotal[$b], $o['it_total'], 2);
                foreach ($o['pools']['direct'] as $d) {
                    foreach ($d['by_pool'] as $pool => $amt) {
                        $cells[$b][$d['target_key']]['direct'][$pool] = bcadd($cells[$b][$d['target_key']]['direct'][$pool] ?? '0.00', $amt, 2);
                    }
                }
                foreach ($o['rows'] as $r) {
                    $key = $ruleTarget[$r['allocation_id']] ?? null;
                    if ($key === null) {
                        continue;
                    }
                    $pool = (string) ($r['cost_pool_id'] ?? 0);
                    $cells[$b][$key]['allocated'][$pool] = bcadd($cells[$b][$key]['allocated'][$pool] ?? '0.00', $r['base_amount'], 2);
                }
            }
        }

        $poolMap = CostPools::map($orgId)['pools'];
        $used = [];
        $keys = collect($bases)->flatMap(fn ($b) => array_keys($cells[$b] ?? []))->unique()->values();
        $labels = $this->labels($orgId, $keys->all(), $targetType);
        $sum = fn (array $m) => array_reduce($m, fn ($c, $v) => bcadd($c, $v, 2), '0.00');
        $rows = [];
        $totals = ['direct' => '0.00', 'allocated' => '0.00', 'total' => '0.00'];
        foreach ($keys as $key) {
            $c = $cells[$basis][$key] ?? [];
            $byPool = [];
            foreach (['direct', 'allocated'] as $kind) {
                foreach ($c[$kind] ?? [] as $pool => $amt) {
                    $byPool[$pool] = bcadd($byPool[$pool] ?? '0.00', $amt, 2);
                    $used[$pool] = true;
                }
            }
            $direct = $sum($c['direct'] ?? []);
            $allocated = $sum($c['allocated'] ?? []);
            $total = bcadd($direct, $allocated, 2);
            $bc = $cells['budget'][$key] ?? [];
            $budgetTotal = bcadd($sum($bc['direct'] ?? []), $sum($bc['allocated'] ?? []), 2);
            $variance = bcsub($total, $budgetTotal, 2);
            if (bccomp($total, '0', 2) === 0 && bccomp($budgetTotal, '0', 2) === 0) {
                continue;
            }
            $codes = [];
            foreach ($byPool as $pool => $amt) {
                $codes[$pool == 0 ? 'unclassified' : ($poolMap[(int) $pool]['code'] ?? 'unclassified')] = bcadd($codes[$pool == 0 ? 'unclassified' : ($poolMap[(int) $pool]['code'] ?? 'unclassified')] ?? '0.00', $amt, 2);
            }
            $rows[] = [
                'target_key' => $key, 'label' => $labels[$key] ?? $key, 'direct' => $direct, 'allocated' => $allocated, 'total' => $total, 'budget_total' => $budgetTotal,
                'variance' => $variance, 'variance_pct' => bccomp($budgetTotal, '0', 2) === 0 ? null : bcadd(bcmul(bcdiv($variance, $budgetTotal, 8), '100', 8), '0', 2), 'by_pool' => $codes,
            ];
            $totals['direct'] = bcadd($totals['direct'], $direct, 2);
            $totals['allocated'] = bcadd($totals['allocated'], $allocated, 2);
            $totals['total'] = bcadd($totals['total'], $total, 2);
        }
        usort($rows, fn ($a, $b) => bccomp($b['total'], $a['total'], 2) ?: strcmp($a['label'], $b['label']));

        $it = $itTotal[$basis];
        $retainedRaw = bcsub($it, $totals['total'], 2);
        $retained = bccomp($retainedRaw, '0', 2) < 0 ? '0.00' : $retainedRaw;
        $difference = bcsub($it, bcadd($totals['total'], $retained, 2), 2);
        $pools = [];
        foreach ($poolMap as $id => $pm) {
            isset($used[$id]) && $pools[] = ['id' => $id, 'code' => $pm['code'], 'name' => $pm['name']];
        }
        isset($used['0']) && $pools[] = ['id' => null, 'code' => 'unclassified', 'name' => 'Unclassified'];

        return [
            'target_type' => $targetType, 'pools' => $pools, 'rows' => $rows,
            'totals' => $totals + ['retained' => $retained, 'it_total' => $it, 'reconciles' => bccomp($difference, '0', 2) === 0, 'difference' => $difference],
        ];
    }

    /**
     * The rules, periods and pools behind one target of a statement.
     *
     * @param  list<int>|null  $ccIds
     * @return array{target_key:string,label:string,items:list<array<string,mixed>>}
     */
    public function detail(int $orgId, string $targetType, string $targetKey, int $fromPeriodId, int $toPeriodId, string $basis, ?array $ccIds): array
    {
        $basis = $basis === 'budget' ? 'budget' : 'actual';
        $from = Period::withoutGlobalScopes()->where('organization_id', $orgId)->find($fromPeriodId);
        $to = Period::withoutGlobalScopes()->where('organization_id', $orgId)->find($toPeriodId);
        $periods = $from && $to ? $this->periods($orgId, (int) $from->fiscal_year_id, $fromPeriodId, $toPeriodId) : collect();
        $rules = $this->allocationsOf($orgId, $targetType)->keyBy('id');
        $poolMap = CostPools::map($orgId)['pools'];
        $items = [];
        foreach ($periods as $p) {
            $o = $this->allocations->outcome($orgId, $targetType, $p, $basis, $ccIds);
            foreach ($o['rows'] as $r) {
                $a = $rules[$r['allocation_id']] ?? null;
                if (! $a || ($a->target_type === 'business_unit' ? 'business_unit:'.$a->target_ref : $a->target_type.':'.$a->target_id) !== $targetKey || bccomp($r['base_amount'], '0', 2) === 0) {
                    continue;
                }
                $items[] = [
                    'kind' => 'rule', 'source_label' => $a->source_label, 'rule_code' => $a->allocation_code, 'method' => $a->method, 'period' => $p->name,
                    'cost_pool' => $r['cost_pool_id'] ? ($poolMap[$r['cost_pool_id']]['name'] ?? 'Pool') : 'Unclassified', 'amount' => $r['base_amount'],
                ];
            }
            foreach ($o['pools']['direct'] as $d) {
                if ($d['target_key'] !== $targetKey) {
                    continue;
                }
                foreach ($d['by_pool'] as $pool => $amt) {
                    $items[] = ['kind' => 'direct', 'source_label' => 'Tagged directly', 'rule_code' => null, 'method' => null, 'period' => $p->name,
                        'cost_pool' => $pool == 0 ? 'Unclassified' : ($poolMap[(int) $pool]['name'] ?? 'Pool'), 'amount' => $amt];
                }
            }
        }
        usort($items, fn ($a, $b) => bccomp($b['amount'], $a['amount'], 2));

        return ['target_key' => $targetKey, 'label' => $this->labels($orgId, [$targetKey], $targetType)[$targetKey] ?? $targetKey, 'items' => array_slice($items, 0, 200)];
    }

    /**
     * FinOps cloud cost by the lenses cloud lines are tagged to; empty for the other lenses.
     *
     * @param  list<int>|null  $ccIds
     * @return list<array{target_key:string,label:string,cost:string}>
     */
    public function cloudMemo(int $orgId, string $targetType, int $fromPeriodId, int $toPeriodId, ?array $ccIds): array
    {
        $column = ['cost_centre' => 'cost_centre_id', 'ea_application' => 'ea_application_id', 'business_service' => 'business_service_id'][$targetType] ?? null;
        $from = Period::withoutGlobalScopes()->where('organization_id', $orgId)->find($fromPeriodId);
        $to = Period::withoutGlobalScopes()->where('organization_id', $orgId)->find($toPeriodId);
        if (! $column || ! $from || ! $to) {
            return [];
        }
        $rows = DB::table('fin_cloud_cost_lines')->where('organization_id', $orgId)->whereDate('usage_date', '>=', $from->starts_on->toDateString())->whereDate('usage_date', '<=', $to->ends_on->toDateString())
            ->whereNotNull($column)->when($ccIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $ccIds ?: [0]))
            ->selectRaw("{$column} as k, SUM(base_cost) as cost")->groupBy($column)->orderByRaw('SUM(base_cost) DESC')->get();
        $labels = $this->labels($orgId, $rows->map(fn ($r) => "{$targetType}:{$r->k}")->all(), $targetType);

        return $rows->map(fn ($r) => [
            'target_key' => "{$targetType}:{$r->k}", 'label' => $labels["{$targetType}:{$r->k}"] ?? "#{$r->k}", 'cost' => bcadd((string) $r->cost, bccomp((string) $r->cost, '0', 10) >= 0 ? '0.005' : '-0.005', 2),
        ])->all();
    }

    /** @return Collection<int,Period> */
    private function periods(int $orgId, int $fiscalYearId, int $fromPeriodId, int $toPeriodId): Collection
    {
        $seq = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->whereIn('id', [$fromPeriodId, $toPeriodId])->pluck('sequence');
        if ($seq->isEmpty()) {
            return collect();
        }

        return Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->whereBetween('sequence', [$seq->min(), $seq->max()])->orderBy('sequence')->get();
    }

    /** @return Collection<int,Allocation> */
    private function allocationsOf(int $orgId, string $targetType): Collection
    {
        return Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->where('target_type', $targetType)->whereNull('deleted_at')->get();
    }

    /**
     * @param  list<string>  $keys  "type:id"
     * @return array<string,string>
     */
    private function labels(int $orgId, array $keys, string $targetType): array
    {
        $ids = [];
        foreach ($keys as $k) {
            [$t, $id] = array_pad(explode(':', $k, 2), 2, null);
            $ids[$t][] = $id;
        }
        $out = [];
        foreach ($ids as $type => $list) {
            $list = array_values(array_filter($list, fn ($v) => $v !== null && $v !== ''));
            if ($type === 'business_unit') {
                $units = collect($this->units($orgId))->keyBy('code');
                foreach ($list as $code) {
                    $out["business_unit:{$code}"] = $units[$code]['label'] ?? $code;
                }

                continue;
            }
            $rows = match ($type) {
                'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $list)->get(['id', 'code', 'name']),
                'ea_application' => EaApplication::withoutGlobalScopes()->whereIn('id', $list)->get(['id', 'code', 'name']),
                'business_service' => BusinessService::withoutGlobalScopes()->whereIn('id', $list)->get(['id', 'code', 'name']),
                'legal_entity' => LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $list)->get(['id', 'code', 'name']),
                'investment' => Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $list)->get(['id', 'investment_code as code', 'title as name']),
                default => collect(),
            };
            foreach ($rows as $r) {
                $out["{$type}:{$r->id}"] = trim("{$r->code} {$r->name}");
            }
        }

        return $out;
    }
}
