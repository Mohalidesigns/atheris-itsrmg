<?php

namespace App\Services\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Allocation;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\Contract;
use App\Models\Finance\ContractLink;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Services\Ea\CostModelService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Application total cost of ownership from the finance ledger (FIN-05.10; plan P6-15): spend tagged to the application or business service,
 * plus what allocation rules assign to it, plus its FinOps cloud cost (the ledger's cloud pool is then left out so cloud is counted once).
 * Labour is a breakdown, not an addend, and contracts are a memo. All figures are base currency bcmath strings, grouped in SQL.
 */
class ApplicationTcoService
{
    public const SUBJECTS = ['ea_application', 'business_service'];

    private const POOL_KEYS = ['CP-HW' => 'hardware', 'CP-SW' => 'software', 'CP-LAB' => 'labour', 'CP-OS' => 'outside_services', 'CP-FAC' => 'facilities',
        'CP-TEL' => 'telecom', 'CP-CLD' => 'cloud', 'CP-OTH' => 'other'];

    /** @var array<string,array<string,mixed>> */
    private array $memo = [];

    public function __construct(private readonly AllocationService $allocations, private readonly MonitoringService $monitoring, private readonly FinanceSettings $settings) {}

    public function flush(): void
    {
        $this->memo = [];
        $this->allocations->flush();
    }

    /**
     * @param  list<int>|null  $ccIds
     * @return array{rows:list<array<string,mixed>>,totals:array<string,mixed>,subjectAvailable:array<string,bool>}
     */
    public function portfolio(int $orgId, int $fiscalYearId, string $subject, string $basis, ?array $ccIds, User $viewer, ?string $search = null): array
    {
        $subject = in_array($subject, self::SUBJECTS, true) ? $subject : 'business_service';
        $basis = $basis === 'budget' ? 'budget' : 'actual';
        $c = $this->compute($orgId, $fiscalYearId, $subject, $basis, $ccIds, null);
        $subjects = $this->subjects($orgId, $subject);
        $contracts = $this->contractsMemo($orgId, $subject);
        $nGn = $this->settings->baseCurrency($orgId) === 'NGN';
        $cost = $nGn && $subject === 'ea_application' ? app(CostModelService::class) : null;

        $rows = [];
        $sumTotal = '0.00';
        foreach ($subjects as $id => $m) {
            $d = $c['direct'][$id] ?? [];
            $a = $c['allocated'][$id] ?? [];
            $cloud = $c['cloud'][$id] ?? '0.00';
            $memo = $contracts[$id] ?? ['annualised' => '0.00', 'count' => 0];
            $byPool = array_fill_keys([...array_values(self::POOL_KEYS), 'unclassified'], '0.00');
            $directSum = '0.00';
            $allocSum = '0.00';
            foreach ([['d', $d], ['a', $a]] as [$kind, $map]) {
                foreach ($map as $pool => $amt) {
                    $key = $c['pool_key'][(int) $pool] ?? 'unclassified';
                    $byPool[$key] = bcadd($byPool[$key], $amt, 2);
                    $kind === 'd' ? $directSum = bcadd($directSum, $amt, 2) : $allocSum = bcadd($allocSum, $amt, 2);
                }
            }
            if ($basis === 'actual') {
                $byPool['cloud'] = bcadd($byPool['cloud'], $cloud, 2);
            }
            $total = $basis === 'actual' ? bcadd(bcadd($directSum, $allocSum, 2), $cloud, 2) : bcadd($directSum, $allocSum, 2);
            if (bccomp($total, '0', 2) === 0 && bccomp($memo['annualised'], '0', 2) === 0) {
                continue;
            }
            $modelled = null;
            if ($cost && $m instanceof EaApplication) {
                $v = $cost->tco($m)['total_ngn'] ?? null;
                $modelled = $v === null ? null : number_format((float) $v, 2, '.', '');
            }
            $sumTotal = bcadd($sumTotal, $total, 2);
            $rows[] = [
                'id' => (int) $id, 'label' => (string) $m->name, 'code' => $m->code ?? null, 'criticality' => $m->criticality ?? null, 'lifecycle' => $m->lifecycle ?? null,
                'direct' => $directSum, 'allocated' => $allocSum, 'cloud' => $byPool['cloud'], 'total' => $total, 'labour' => $byPool['labour'], 'by_pool' => $byPool,
                'contracts_memo' => $memo['annualised'], 'contracts_count' => $memo['count'], 'ea_modelled' => $modelled,
                'delta_vs_model_pct' => $modelled !== null && bccomp($modelled, '0', 2) > 0 ? bcadd(bcmul(bcdiv(bcsub($total, $modelled, 2), $modelled, 8), '100', 8), '0', 2) : null,
                'share_pct' => '0.00', 'href' => $m instanceof EaApplication ? route('ea.applications.show', $m->id) : null,
            ];
        }
        foreach ($rows as &$r) {
            $r['share_pct'] = bccomp($c['it_total'], '0', 2) === 0 ? '0.00' : bcadd(bcmul(bcdiv($r['total'], $c['it_total'], 8), '100', 8), '0', 2);
        }
        unset($r);
        usort($rows, fn ($a, $b) => bccomp($b['total'], $a['total'], 2) ?: strcmp($a['label'], $b['label']));
        $needle = $search ? mb_strtolower(trim($search)) : null;
        $needle && $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['label'].' '.$r['code']), $needle)));

        return [
            'rows' => $rows,
            'totals' => [
                'tco_total' => $sumTotal, 'it_total' => $c['it_total'],
                // One source for numerator and remainder: tco_total + unattributed == it_total to the cent. Negative only when FinOps cloud exceeds the ledger cloud it replaces.
                'coverage_pct' => bccomp($c['it_total'], '0', 2) <= 0 ? '0.00' : bcadd(bcmul(bcdiv(bccomp($sumTotal, $c['it_total'], 2) > 0 ? $c['it_total'] : $sumTotal, $c['it_total'], 8), '100', 8), '0', 2),
                'unattributed' => bcsub($c['it_total'], $sumTotal, 2),
                'finops_untagged_cloud' => $c['finops_untagged'],
            ],
            'subjectAvailable' => [
                'ea_application' => EaApplication::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->exists(),
                'business_service' => BusinessService::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->exists(),
            ],
        ];
    }

    /**
     * Where one subject's TCO comes from.
     *
     * @param  list<int>|null  $ccIds
     * @return array{components:array<string,list<array<string,mixed>>>,label:string}
     */
    public function detail(int $orgId, int $fiscalYearId, string $subject, int $id, string $basis, ?array $ccIds): array
    {
        $subject = in_array($subject, self::SUBJECTS, true) ? $subject : 'business_service';
        $basis = $basis === 'budget' ? 'budget' : 'actual';
        $m = ($subject === 'ea_application' ? EaApplication::class : BusinessService::class)::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->find($id);

        $c = $this->compute($orgId, $fiscalYearId, $subject, $basis, $ccIds, null);
        $covered = isset($c['cloud'][$id]);
        $codes = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('code', 'id');
        $poolNames = collect(CostPools::map($orgId)['pools'])->pluck('name', 'id');
        $icol = $subject === 'ea_application' ? 'app' : 'svc';
        $skip = fn (int $pool) => $basis === 'actual' && $covered && $pool === $c['cloud_pool'];

        // Direct: the same counted items the headline sums, grouped by pool and cost centre, so the drawer ties to the row.
        $grouped = [];
        foreach ($this->allocations->fyItems($orgId, $fiscalYearId, $basis, $ccIds) as $pid => $items) {
            if (! isset($c['period_ids'][$pid])) {
                continue;
            }
            foreach ($items as $it) {
                if ((int) ($it[$icol] ?? 0) !== $id || $skip((int) ($it['pool'] ?? 0))) {
                    continue;
                }
                $k = ((int) ($it['pool'] ?? 0)).':'.((int) $it['cc']);
                $grouped[$k] = ['pool' => (int) ($it['pool'] ?? 0), 'cc' => (int) $it['cc'], 'amount' => bcadd($grouped[$k]['amount'] ?? '0.00', $it['amount'], 2)];
            }
        }
        $direct = collect($grouped)->filter(fn ($r) => bccomp($r['amount'], '0', 2) !== 0)->map(fn ($r) => [
            'line_id' => null, 'label' => (string) ($poolNames[$r['pool']] ?? 'Unclassified'), 'cost_centre' => $codes[$r['cc']] ?? null, 'amount' => $r['amount'],
        ])->sortByDesc(fn ($r) => (float) $r['amount'])->values()->all();

        $rules = Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->where('target_type', $subject)->where('target_id', $id)->whereNull('deleted_at')->get()->keyBy('id');
        $allocated = [];
        $periods = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->orderBy('sequence')->get();
        if ($rules->isNotEmpty()) {
            $sum = [];
            foreach ($periods as $p) {
                foreach ($this->allocations->outcome($orgId, $subject, $p, $basis, $ccIds)['rows'] as $r) {
                    if (isset($rules[$r['allocation_id']]) && ! $skip((int) ($r['cost_pool_id'] ?? 0))) {
                        $sum[$r['allocation_id']] = bcadd($sum[$r['allocation_id']] ?? '0.00', $r['base_amount'], 2);
                    }
                }
            }
            foreach ($sum as $rid => $amt) {
                bccomp($amt, '0', 2) !== 0 && $allocated[] = ['rule_code' => $rules[$rid]->allocation_code, 'source_label' => $rules[$rid]->source_label, 'amount' => $amt];
            }
        }

        $cloud = [];
        if ($basis === 'actual' && $covered) {
            $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('name', 'id');
            $column = $subject === 'ea_application' ? 'ea_application_id' : 'business_service_id';
            $cloud = $this->finopsQuery($orgId, $periods, $ccIds)->where($column, $id)
                ->selectRaw('service, cloud_account_id, SUM(base_cost) as cost')->groupBy('service', 'cloud_account_id')->orderByRaw('SUM(base_cost) DESC')->get()
                ->map(fn ($r) => ['service' => $r->service, 'account' => $accounts[$r->cloud_account_id] ?? null, 'cost' => $this->cents((string) $r->cost)])->all();
        }

        $contracts = [];
        foreach ($this->contractsFor($orgId, $subject, [$id]) as $row) {
            $contracts[] = ['id' => $row['id'], 'contract_code' => $row['contract_code'], 'title' => $row['title'], 'annualised' => $row['annualised']];
        }

        return ['label' => (string) ($m->name ?? ''), 'components' => ['direct' => $direct, 'allocated' => $allocated, 'cloud' => $cloud, 'contracts' => $contracts]];
    }

    /** Current-year YTD actual TCO of an EA application, annualised (plan P6-16); null without a fiscal year, an elapsed period or any spend. */
    /** @param  list<int>|null  $ccIds */
    public function measuredFor(int $orgId, int $eaApplicationId, ?array $ccIds = null): ?string
    {
        $fy = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_current', true)->first();
        $asOf = $fy ? $this->monitoring->asOfPeriod($orgId, (int) $fy->id) : null;
        if (! $fy || ! $asOf || (int) $asOf->sequence < 1) {
            return null;
        }
        $c = $this->compute($orgId, (int) $fy->id, 'ea_application', 'actual', $ccIds, (int) $asOf->sequence);
        $total = $this->totalOf($c, $eaApplicationId, 'actual');
        if (bccomp($total, '0', 2) <= 0) {
            return null;
        }

        return bcadd(bcmul(bcdiv($total, (string) $asOf->sequence, 10), '12', 10), '0.005', 2);
    }

    /**
     * Capability groups of applications competing for the same capability, re-priced with the measured TCO of the fiscal year (EA rationalisation).
     *
     * @param  list<int>|null  $ccIds
     * @return list<array{capability:string,applications:list<array<string,mixed>>,combined_total:string}>
     */
    public function rationalisation(int $orgId, int $fiscalYearId, ?array $ccIds): array
    {
        if (! EaApplication::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return [];
        }
        $c = $this->compute($orgId, $fiscalYearId, 'ea_application', 'actual', $ccIds, null);
        $out = [];
        foreach (app(CostModelService::class)->rationalisationCandidates() as $cand) {
            $apps = [];
            $combined = '0.00';
            foreach (array_merge([$cand['survivor']], $cand['displaced']->all()) as $a) {
                $total = $this->totalOf($c, (int) $a['id'], 'actual');
                $apps[] = ['id' => (int) $a['id'], 'label' => (string) $a['name'], 'total' => $total];
                $combined = bcadd($combined, $total, 2);
            }
            bccomp($combined, '0', 2) > 0 && $out[] = ['capability' => (string) ($cand['capability']['name'] ?? ''), 'applications' => $apps, 'combined_total' => $combined];
        }
        usort($out, fn ($a, $b) => bccomp($b['combined_total'], $a['combined_total'], 2));

        return array_slice($out, 0, 10);
    }

    /* ---------------------------------------------------------------- internals */

    /** @param array<string,mixed> $c */
    private function totalOf(array $c, int $id, string $basis): string
    {
        $sum = '0.00';
        foreach (['direct', 'allocated'] as $kind) {
            foreach ($c[$kind][$id] ?? [] as $pool => $amt) {
                $sum = bcadd($sum, $amt, 2);
            }
        }

        return $basis === 'actual' ? bcadd($sum, $c['cloud'][$id] ?? '0.00', 2) : $sum;
    }

    /**
     * Direct, allocated and FinOps cloud figures of every subject for a fiscal year (optionally only its first $maxSeq periods).
     *
     * @param  list<int>|null  $ccIds
     * @return array<string,mixed>
     */
    private function compute(int $orgId, int $fiscalYearId, string $subject, string $basis, ?array $ccIds, ?int $maxSeq): array
    {
        $key = implode(':', [$orgId, $fiscalYearId, $subject, $basis, $ccIds === null ? '*' : md5(json_encode($ccIds)), $maxSeq ?? 'all']);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $periods = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->when($maxSeq !== null, fn ($q) => $q->where('sequence', '<=', $maxSeq))->orderBy('sequence')->get();
        $items = $this->allocations->fyItems($orgId, $fiscalYearId, $basis, $ccIds);
        $col = $subject === 'ea_application' ? 'app' : 'svc';
        $map = CostPools::map($orgId);
        $itTotal = '0.00';
        $direct = [];
        $cloudPool = $map['by_code'][CostPools::CLOUD] ?? -1;
        $cloud = $basis === 'actual' ? $this->finopsCloud($orgId, $subject, $periods, $ccIds) : [];
        foreach ($periods as $p) {
            foreach ($items[(int) $p->id] ?? [] as $it) {
                $itTotal = bcadd($itTotal, $it['amount'], 2);
                if ($it[$col]) {
                    $pool = (int) ($it['pool'] ?? 0);
                    if ($pool === $cloudPool && isset($cloud[(int) $it[$col]])) {
                        continue; // FinOps covers this subject: its cloud replaces the ledger cloud, once
                    }
                    $direct[$it[$col]][$pool] = bcadd($direct[$it[$col]][$pool] ?? '0.00', $it['amount'], 2);
                }
            }
        }
        $rules = Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->where('target_type', $subject)->whereNull('deleted_at')->pluck('target_id', 'id');
        $allocated = [];
        if ($rules->isNotEmpty()) {
            foreach ($periods as $p) {
                foreach ($this->allocations->outcome($orgId, $subject, $p, $basis, $ccIds)['rows'] as $r) {
                    $target = $rules[$r['allocation_id']] ?? null;
                    if ($target) {
                        $pool = (int) ($r['cost_pool_id'] ?? 0);
                        if ($pool === $cloudPool && isset($cloud[(int) $target])) {
                            continue;
                        }
                        $allocated[(int) $target][$pool] = bcadd($allocated[(int) $target][$pool] ?? '0.00', $r['base_amount'], 2);
                    }
                }
            }
        }
        $poolKey = [];
        foreach ($map['pools'] as $id => $p) {
            $poolKey[$id] = self::POOL_KEYS[$p['code']] ?? 'other';
        }

        return $this->memo[$key] = [
            'it_total' => $itTotal, 'direct' => $direct, 'allocated' => $allocated, 'cloud' => $cloud, 'cloud_pool' => $cloudPool, 'pool_key' => $poolKey, 'period_ids' => $periods->pluck('id')->flip()->all(),
            'finops_untagged' => $basis === 'actual' ? $this->finopsUntagged($orgId, $subject, $periods, $ccIds) : '0.00',
        ];
    }

    private function cents(string $v): string
    {
        return bcadd($v, bccomp($v, '0', 10) >= 0 ? '0.005' : '-0.005', 2);
    }

    /** @param  Collection<int,Period>  $periods */
    private function finopsQuery(int $orgId, $periods, ?array $ccIds)
    {
        $q = DB::table('fin_cloud_cost_lines')->where('organization_id', $orgId)->when($ccIds !== null, fn ($q) => $q->whereIn('cost_centre_id', $ccIds ?: [0]));
        if ($periods->isEmpty()) {
            return $q->whereRaw('1 = 0');
        }

        return $q->whereDate('usage_date', '>=', $periods->first()->starts_on->toDateString())->whereDate('usage_date', '<=', $periods->last()->ends_on->toDateString());
    }

    /**
     * FinOps cloud cost per subject over the computed periods. A subject present here is "covered": its ledger cloud is left out and this figure stands in.
     *
     * @return array<int,string>
     */
    private function finopsCloud(int $orgId, string $subject, $periods, ?array $ccIds): array
    {
        $column = $subject === 'ea_application' ? 'ea_application_id' : 'business_service_id';
        $out = [];
        foreach ($this->finopsQuery($orgId, $periods, $ccIds)->whereNotNull($column)->selectRaw("{$column} as k, SUM(base_cost) as cost")->groupBy($column)->get() as $r) {
            $out[(int) $r->k] = $this->cents((string) $r->cost);
        }

        return $out;
    }

    private function finopsUntagged(int $orgId, string $subject, $periods, ?array $ccIds): string
    {
        $column = $subject === 'ea_application' ? 'ea_application_id' : 'business_service_id';

        return $this->cents((string) ($this->finopsQuery($orgId, $periods, $ccIds)->whereNull($column)->sum('base_cost') ?: '0'));
    }

    /** @return array<int,object> id => EaApplication|BusinessService */
    private function subjects(int $orgId, string $subject): array
    {
        $class = $subject === 'ea_application' ? EaApplication::class : BusinessService::class;

        return $class::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->orderBy('name')->get()->keyBy('id')->all();
    }

    /** @return array<int,array{annualised:string,count:int}> */
    private function contractsMemo(int $orgId, string $subject): array
    {
        $out = [];
        foreach ($this->contractsFor($orgId, $subject, null) as $row) {
            $out[$row['subject_id']]['annualised'] = bcadd($out[$row['subject_id']]['annualised'] ?? '0.00', $row['annualised'], 2);
            $out[$row['subject_id']]['count'] = ($out[$row['subject_id']]['count'] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * Live contracts linked to the subjects, annualised from the base contract value over the term (memo only).
     *
     * @param  list<int>|null  $ids
     * @return list<array<string,mixed>>
     */
    private function contractsFor(int $orgId, string $subject, ?array $ids): array
    {
        $type = ContractLink::TYPES[$subject];
        $links = ContractLink::withoutGlobalScopes()->where('organization_id', $orgId)->where('linkable_type', $type)->when($ids, fn ($q) => $q->whereIn('linkable_id', $ids))->get(['contract_id', 'linkable_id']);
        if ($links->isEmpty()) {
            return [];
        }
        $contracts = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $links->pluck('contract_id')->unique())->whereIn('status', Contract::LIVE)->get()->keyBy('id');
        $out = [];
        foreach ($links as $l) {
            $c = $contracts[$l->contract_id] ?? null;
            if (! $c) {
                continue;
            }
            $term = max(1, (int) $c->term_months);
            $out[] = [
                'subject_id' => (int) $l->linkable_id, 'id' => (int) $c->id, 'contract_code' => $c->contract_code, 'title' => $c->title,
                'annualised' => bcadd(bcmul(bcdiv((string) $c->base_contract_value, (string) $term, 10), '12', 10), '0.005', 2),
            ];
        }

        return $out;
    }
}
