<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Props shaping for the cloud, allocation, showback and TCO pages (plan §7). Without `view it-finance-amounts` every cost, pool, result,
 * statement and TCO figure is nulled server-side (plan P6-18); percentages, counts, statuses, dates and the anomaly method stay.
 * Self-contained on purpose: do not combine with the other Presents* traits, whose method names collide.
 */
trait PresentsFinOps
{
    private const FINOPS_MONEY_KEYS = [
        'cost', 'base_cost', 'total', 'untagged', 'mtd', 'prior_mtd', 'baseline_cost', 'observed_cost', 'baseline_base_cost', 'observed_base_cost', 'pool_total', 'allocated_total',
        'direct', 'allocated', 'retained', 'it_total', 'budget_total', 'variance', 'variance_pct', 'amount', 'finops_total', 'cloud_ledger_actual', 'gap', 'gap_pct', 'cloud', 'labour',
        'contracts_memo', 'ea_modelled', 'delta_vs_model_pct', 'measured', 'current_base_amount', 'total_debit', 'total_credit', 'difference', 'tco_total', 'unattributed', 'annualised',
        'pool_base_amount', 'base_amount',
    ];

    /** Lists of money; an integer under one of these names (a count) is left alone. */
    private const FINOPS_MONEY_LISTS = ['series', 'by_pool', 'lines'];

    protected function renderFinOps(string $component, array $props)
    {
        $user = request()->user();
        $can = $user->can('view it-finance-amounts');
        $props['baseCurrency'] ??= app(FinanceSettings::class)->baseCurrency((int) $user->organization_id);
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskFinOps(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskFinOps(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::FINOPS_MONEY_LISTS, true) && is_array($v)) {
                $data[$k] = [];
            } elseif (is_string($k) && in_array($k, self::FINOPS_MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskFinOps($v);
            }
        }

        return $data;
    }

    /** The cost centres a viewer may see, null for all. @return list<int>|null */
    protected function visibleCcIds(User $user): ?array
    {
        return FinancePolicy::visibleCostCentreIds($user);
    }

    /** Cloud accounts the (scoped) viewer owns, so the scope never hides their own accounts. @return list<int> */
    protected function ownedAccountIds(User $user): array
    {
        return CloudAccount::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('owner_id', $user->id)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array{id:int,name:string,job_title:?string}|null */
    protected function finUserMini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title] : null;
    }

    /** @return list<array<string,mixed>> */
    protected function finFiscalYears(int $orgId): array
    {
        return FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderByDesc('starts_on')->get()
            ->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label, 'status' => $f->status, 'is_current' => (bool) $f->is_current])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function finPeriods(int $orgId, ?int $fiscalYearId): array
    {
        return $fiscalYearId ? Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fiscalYearId)->orderBy('sequence')->get()
            ->map(fn (Period $p) => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'sequence' => $p->sequence, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString()])->values()->all() : [];
    }

    /** @return list<array{id:int,code:string,name:string}> */
    protected function finCostCentres(User $user, bool $all = false): array
    {
        $ids = $all ? null : $this->visibleCcIds($user);

        return CostCentre::withoutGlobalScopes()->where('organization_id', $user->organization_id)->whereNull('deleted_at')->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all();
    }

    /** @return list<array{id:int,label:string}> */
    protected function finApplications(int $orgId): array
    {
        return EaApplication::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'label' => trim(($a->code ? $a->code.' ' : '').$a->name)])->values()->all();
    }

    /** @return list<array{id:int,label:string}> */
    protected function finBusinessServices(int $orgId): array
    {
        return BusinessService::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->orderBy('name')->get(['id', 'code', 'name'])
            ->map(fn ($a) => ['id' => $a->id, 'label' => trim(($a->code ? $a->code.' ' : '').$a->name)])->values()->all();
    }

    /** @return list<array{id:int,label:string}> */
    protected function finContracts(int $orgId): array
    {
        return Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('contract_code')->limit(500)->get(['id', 'contract_code', 'title'])
            ->map(fn ($c) => ['id' => $c->id, 'label' => trim("{$c->contract_code} {$c->title}")])->values()->all();
    }

    /** @return list<array{id:int,name:string,job_title:?string}> */
    protected function finUsers(int $orgId): array
    {
        return User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title'])->map(fn ($u) => $this->finUserMini($u))->values()->all();
    }

    /** @param array<string,string> $labels @return list<array{value:string,label:string}> */
    protected function opts(array $labels): array
    {
        return collect($labels)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }

    /** @return array<string,mixed> */
    protected function optionsPayload(): array
    {
        $formats = [['value' => 'aws_cur', 'label' => 'AWS Cost and Usage Report'], ['value' => 'azure', 'label' => 'Azure cost export'], ['value' => 'gcp', 'label' => 'Google Cloud billing export'], ['value' => 'generic', 'label' => 'Generic CSV']];
        foreach ($formats as &$f) {
            $spec = (array) config('finance.cloud.formats.'.$f['value']);
            $f['columns'] = array_values(array_unique(array_filter([
                ...($spec['date'] ?? []), ...($spec['service'] ?? []), ...($spec['resource_id'] ?? []), ...($spec['cost'] ?? []), ...($spec['currency'] ?? []), ...($spec['account'] ?? []),
                ...(isset($spec['tag_prefix']) ? [$spec['tag_prefix'].'<tag>'] : []), ...($spec['tags_json'] ?? []), ...($spec['tags_kv'] ?? []),
            ])));
        }
        unset($f);
        $currencies = Currency::withoutGlobalScopes()->where('organization_id', request()->user()->organization_id)->where('is_active', true)->orderByDesc('is_base')->orderBy('code')->pluck('code');

        return [
            'providers' => $this->opts((array) config('finance.cloud.providers')), 'formats' => $formats, 'dimensions' => $this->opts((array) config('finance.cloud.dimensions')),
            'currencies' => $currencies->map(fn ($c) => ['value' => $c, 'label' => $c])->values()->all(),
        ];
    }

    /** @param array<string,int> $stats mtd, lines, untagged_pct, anomalies by account id (see CloudAccountController) @return array<string,mixed> */
    protected function accountRow(CloudAccount $a, array $stats, ?object $batch, User $user): array
    {
        return [
            'id' => $a->id, 'provider' => $a->provider, 'account_identifier' => $a->account_identifier, 'name' => $a->name, 'default_format' => $a->default_format, 'currency' => $a->currency,
            'owner' => $this->finUserMini($a->owner), 'default_cost_centre' => $a->defaultCostCentre ? ['id' => $a->defaultCostCentre->id, 'code' => $a->defaultCostCentre->code, 'name' => $a->defaultCostCentre->name] : null,
            'contract' => $a->contract ? ['id' => $a->contract->id, 'contract_code' => $a->contract->contract_code, 'title' => $a->contract->title] : null,
            'tag_mapping' => (object) ($a->tag_mapping ?? []), 'is_active' => (bool) $a->is_active, 'last_usage_date' => $a->last_usage_date?->toDateString(),
            'mtd' => $stats['mtd'] ?? '0.00', 'lines_count' => (int) ($stats['lines'] ?? 0), 'untagged_pct' => $stats['untagged_pct'] ?? '0.00', 'open_anomalies' => (int) ($stats['anomalies'] ?? 0),
            'last_batch' => $batch ? ['id' => $batch->id, 'file_name' => $batch->file_name, 'status' => $batch->status, 'created_at' => $batch->created_at?->toIso8601String(), 'href' => route('finance.imports.show', $batch->id)] : null,
            'can' => ['edit' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class), 'delete' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class) && ($stats['lines'] ?? 0) == 0,
                'import' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class) && $a->is_active],
        ];
    }

    /** @return array<string,mixed> */
    protected function anomalyRow(CloudAnomaly $x, User $user): array
    {
        $open = in_array($x->status, CloudAnomaly::OPEN, true);
        $can = Gate::forUser($user)->allows('handleAnomaly', $x);

        return [
            'id' => $x->id, 'cloud_account' => ['id' => $x->account?->id, 'name' => $x->account?->name, 'provider' => $x->account?->provider], 'detected_on' => $x->detected_on->toDateString(),
            'scope' => $x->scope, 'scope_ref' => $x->scope_ref, 'scope_label' => $x->scope_label, 'method' => $x->method, 'baseline_cost' => (string) $x->baseline_cost, 'observed_cost' => (string) $x->observed_cost,
            'currency' => $x->account?->currency, 'baseline_base_cost' => (string) $x->baseline_base_cost, 'observed_base_cost' => (string) $x->observed_base_cost, 'deviation_pct' => (string) $x->deviation_pct,
            'status' => $x->status, 'owner' => $this->finUserMini($x->owner), 'note' => $x->note, 'acknowledged_by' => $this->finUserMini($x->acknowledgedBy), 'acknowledged_at' => $x->acknowledged_at?->toIso8601String(),
            'resolved_by' => $this->finUserMini($x->resolvedBy), 'resolved_at' => $x->resolved_at?->toIso8601String(),
            'can' => ['acknowledge' => $can && $x->status === 'open', 'resolve' => $can && $open, 'dismiss' => $can && $open],
        ];
    }

    /** @param array<string,mixed> $targets  "dimension:id" => label @return array<string,mixed> */
    protected function ruleRow(CloudTagRule $r, array $targets, User $user): array
    {
        $label = $targets[$r->dimension.':'.$r->target_id] ?? null;

        return [
            'id' => $r->id, 'cloud_account_id' => $r->cloud_account_id, 'account_name' => $r->account?->name, 'tag_key' => $r->tag_key, 'tag_value' => $r->tag_value, 'dimension' => $r->dimension,
            'target' => $label ? ['id' => $r->target_id, 'label' => $label] : null, 'hits' => (int) $r->hits, 'last_hit_at' => $r->last_hit_at?->toIso8601String(), 'is_active' => (bool) $r->is_active,
            'can' => ['edit' => Gate::forUser($user)->allows('manageCloud', CloudAccount::class)],
        ];
    }

    /** "dimension:id" => label for the targets of tag rules. @param list<CloudTagRule>|\Illuminate\Support\Collection $rules @return array<string,string> */
    protected function ruleTargets(int $orgId, $rules): array
    {
        $out = [];
        $ids = collect($rules)->groupBy('dimension')->map(fn ($g) => $g->pluck('target_id')->unique()->all());
        foreach (CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $ids['cost_centre'] ?? [0])->get(['id', 'code', 'name']) as $c) {
            $out['cost_centre:'.$c->id] = trim("{$c->code} {$c->name}");
        }
        foreach (EaApplication::withoutGlobalScopes()->whereIn('id', $ids['ea_application'] ?? [0])->get(['id', 'code', 'name']) as $c) {
            $out['ea_application:'.$c->id] = trim("{$c->code} {$c->name}");
        }
        foreach (BusinessService::withoutGlobalScopes()->whereIn('id', $ids['business_service'] ?? [0])->get(['id', 'code', 'name']) as $c) {
            $out['business_service:'.$c->id] = trim("{$c->code} {$c->name}");
        }

        return $out;
    }

    /** Per-account figures for the Accounts table in a handful of grouped queries. @param list<int> $ids @return array<int,array<string,mixed>> */
    protected function accountStats(int $orgId, array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $today = FinanceClock::today();
        $stats = array_fill_keys($ids, ['mtd' => '0.00', 'lines' => 0, 'untagged_pct' => '0.00', 'anomalies' => 0]);
        foreach (DB::table('fin_cloud_cost_lines')->where('organization_id', $orgId)->whereIn('cloud_account_id', $ids)->selectRaw('cloud_account_id as id, COUNT(*) as n')->groupBy('cloud_account_id')->get() as $r) {
            $stats[$r->id]['lines'] = (int) $r->n;
        }
        foreach (DB::table('fin_cloud_cost_lines')->where('organization_id', $orgId)->whereIn('cloud_account_id', $ids)->whereDate('usage_date', '>=', $today->startOfMonth()->toDateString())
            ->selectRaw('cloud_account_id as id, SUM(base_cost) as t')->groupBy('cloud_account_id')->get() as $r) {
            $stats[$r->id]['mtd'] = bcadd((string) $r->t, bccomp((string) $r->t, '0', 10) >= 0 ? '0.005' : '-0.005', 2);
        }
        foreach (DB::table('fin_cloud_cost_lines')->where('organization_id', $orgId)->whereIn('cloud_account_id', $ids)->whereDate('usage_date', '>=', $today->subDays(29)->toDateString())
            ->selectRaw('cloud_account_id as id, SUM(base_cost) as t, SUM(CASE WHEN is_untagged = ? THEN base_cost ELSE 0 END) as u', [true])->groupBy('cloud_account_id')->get() as $r) {
            $stats[$r->id]['untagged_pct'] = (float) $r->t == 0.0 ? '0.00' : bcadd(bcmul(bcdiv((string) $r->u, (string) $r->t, 8), '100', 8), '0', 2);
        }
        foreach (CloudAnomaly::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('cloud_account_id', $ids)->whereIn('status', CloudAnomaly::OPEN)->selectRaw('cloud_account_id as id, COUNT(*) as n')->groupBy('cloud_account_id')->get() as $r) {
            $stats[$r->id]['anomalies'] = (int) $r->n;
        }

        return $stats;
    }
}
