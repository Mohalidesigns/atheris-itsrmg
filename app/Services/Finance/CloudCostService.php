<?php

namespace App\Services\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Actual;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\Period;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Cloud cost as a FinOps sub-ledger (plan P6-1): accounts, daily cost lines imported from provider exports, tag attribution and
 * summaries. Lines are never posted to fin_actuals; the book of record stays invoices and the GL (a ledger reconciliation shows the gap).
 */
class CloudCostService
{
    use LogsFinanceEvents;

    private const REJECT_CAP = 200;

    public function __construct(private readonly CurrencyService $currency, private readonly FinanceSettings $settings) {}

    /* ---------------------------------------------------------------- accounts */

    /** @param array<string,mixed> $data */
    /** Tag rules and new accounts shape attribution for the whole bank, so a cost-centre-scoped viewer cannot create or change them (P6-sec-M1). */
    public function assertBankWide(User $by): void
    {
        if (FinancePolicy::visibleCostCentreIds($by) !== null && ! $by->can('manage it-finance-settings')) {
            throw new AuthorizationException('Tag rules and cloud accounts are managed bank-wide; ask a finance approver or settings administrator.');
        }
    }

    public function storeAccount(array $data, User $by): CloudAccount
    {
        $this->assertBankWide($by);
        $orgId = (int) $by->organization_id;
        $clean = $this->cleanAccount($data, $orgId, null);
        $account = CloudAccount::withoutGlobalScopes()->create($clean + ['organization_id' => $orgId, 'created_by_id' => $by->id]);
        $this->audit($account, $by, 'created', "Cloud account {$account->label} added");

        return $account->refresh();
    }

    /** @param array<string,mixed> $data */
    public function updateAccount(CloudAccount $a, array $data, User $by): CloudAccount
    {
        $this->sameBank($a, $by);
        if ($a->costLines()->exists()) {
            foreach (['provider', 'account_identifier'] as $k) {
                if (isset($data[$k]) && (string) $data[$k] !== (string) $a->{$k}) {
                    $this->fail('The provider and account identifier cannot change once cost lines are imported.', $k);
                }
            }
        }
        $clean = $this->cleanAccount($data + $a->only(['provider', 'account_identifier', 'name', 'default_format', 'currency', 'default_cost_centre_id', 'owner_id', 'contract_id', 'tag_mapping', 'is_active', 'notes']), (int) $a->organization_id, $a);
        $a->fill($clean)->save();

        return $a->refresh();
    }

    public function destroyAccount(CloudAccount $a, User $by): void
    {
        $this->sameBank($a, $by);
        $a->costLines()->exists() && $this->fail('This account has imported cost lines; deactivate it instead of deleting it.');
        $this->audit($a, $by, 'deleted', "Cloud account {$a->label} deleted");
        $a->delete();
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function cleanAccount(array $d, int $orgId, ?CloudAccount $existing): array
    {
        $provider = (string) ($d['provider'] ?? '');
        in_array($provider, CloudAccount::PROVIDERS, true) || $this->fail('Pick a cloud provider.', 'provider');
        $identifier = trim((string) ($d['account_identifier'] ?? ''));
        ($identifier !== '' && mb_strlen($identifier) <= 100) || $this->fail('Give the account or subscription identifier (max 100 characters).', 'account_identifier');
        $name = trim((string) ($d['name'] ?? ''));
        ($name !== '' && mb_strlen($name) <= 160) || $this->fail('Give the account a name (max 160 characters).', 'name');
        $format = (string) ($d['default_format'] ?? 'generic');
        in_array($format, CloudAccount::FORMATS, true) || $this->fail('Pick an export format.', 'default_format');
        $currency = strtoupper(trim((string) ($d['currency'] ?? '')));
        preg_match('/^[A-Z]{3}$/', $currency) || $this->fail('The billing currency must be a 3-letter code.', 'currency');

        $dup = CloudAccount::withoutGlobalScopes()->withTrashed()->where('organization_id', $orgId)->where('provider', $provider)->where('account_identifier', $identifier)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists();
        $dup && $this->fail('That provider account is already registered.', 'account_identifier');

        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->find((int) ($d['default_cost_centre_id'] ?? 0));
        $cc || $this->fail('Pick the default cost centre (a cost centre of this bank).', 'default_cost_centre_id');
        $owner = null;
        if (! empty($d['owner_id'])) {
            $owner = User::where('organization_id', $orgId)->find((int) $d['owner_id']);
            $owner || $this->fail('The owner must be a user of this bank.', 'owner_id');
        }
        $contract = null;
        if (! empty($d['contract_id'])) {
            $contract = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $d['contract_id']);
            $contract || $this->fail('The contract must belong to this bank.', 'contract_id');
        }
        $mapping = [];
        foreach ((array) ($d['tag_mapping'] ?? []) as $k => $dim) {
            $k = trim((string) $k);
            ($k !== '' && mb_strlen($k) <= 128) || $this->fail('Tag keys must be 1 to 128 characters.', 'tag_mapping');
            in_array($dim, CloudAccount::DIMENSIONS, true) || $this->fail("Tag \"{$k}\" must map to a cost centre, EA application or business service.", 'tag_mapping');
            $mapping[$k] = $dim;
        }

        return [
            'provider' => $provider, 'account_identifier' => $identifier, 'name' => $name, 'default_format' => $format, 'currency' => $currency,
            'default_cost_centre_id' => $cc->id, 'owner_id' => $owner?->id, 'contract_id' => $contract?->id, 'tag_mapping' => $mapping ?: null,
            'is_active' => array_key_exists('is_active', $d) ? (bool) $d['is_active'] : true, 'notes' => isset($d['notes']) ? trim((string) $d['notes']) ?: null : null,
        ];
    }

    /* ---------------------------------------------------------------- import */

    public function import(CloudAccount $a, UploadedFile $file, ?string $format, User $by): ImportBatch
    {
        $this->sameBank($a, $by);
        $a->is_active || $this->fail('This account is inactive; reactivate it before importing.', 'cloud_account_id');
        $format = $format ?: (string) $a->default_format;
        in_array($format, CloudAccount::FORMATS, true) || $this->fail('Pick an export format.', 'format');
        $sha = hash_file('sha256', $file->getRealPath());
        $prior = ImportBatch::withoutGlobalScopes()->where('organization_id', $a->organization_id)->where('type', 'cloud')->where('cloud_account_id', $a->id)
            ->where('file_sha256', $sha)->where('status', '!=', 'failed')->first();
        $prior && $this->fail('This exact file was already imported for this account (batch #'.$prior->id.' on '.$prior->created_at->toDateString().').', 'file');

        $parsed = (new CloudExportParser)->parse($file->getRealPath(), $format, (string) $a->account_identifier, ['default_currency' => $a->currency]);
        $daily = CloudExportParser::aggregate($parsed['rows']);

        return DB::transaction(function () use ($a, $file, $format, $by, $sha, $parsed, $daily) {
            $batch = ImportBatch::withoutGlobalScopes()->create([
                'organization_id' => $a->organization_id, 'created_by_id' => $by->id, 'type' => 'cloud', 'file_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255), 'file_sha256' => $sha,
                'imported_by_id' => $by->id, 'status' => 'completed', 'cloud_account_id' => $a->id, 'format' => $format, 'date_from' => $parsed['date_from'], 'date_to' => $parsed['date_to'],
                'row_count' => $parsed['row_count'],
            ]);
            $r = $this->ingestRows($a, $daily, $batch, $by);
            $rejected = array_merge($parsed['rejected'], $r['rejected']);
            $status = $r['inserted'] === 0 && ($rejected || $r['rows_refused']) ? 'failed' : ($rejected || $r['rows_refused'] ? 'completed_with_errors' : 'completed');
            $batch->forceFill([
                'accepted_count' => $r['inserted'], 'rejected_count' => count($parsed['rejected']) + count($r['rejected']) + $r['rows_refused'], 'duplicate_count' => 0, 'status' => $status, 'completed_at' => now(),
                'report' => [
                    'rejected' => array_slice($rejected, 0, self::REJECT_CAP), 'replaced_dates' => $r['replaced_dates'], 'appended_dates' => $r['appended_dates'], 'refused_dates' => $r['refused_dates'],
                    'rows' => $parsed['row_count'], 'accepted' => $r['inserted'], 'rejected_total' => count($rejected) + $r['rows_refused'],
                ],
            ])->save();
            $this->audit($batch, $by, 'imported', "Cloud export {$batch->file_name} imported for {$a->label}: {$r['inserted']} daily lines", [
                'format' => $format, 'replaced' => count($r['replaced_dates']), 'appended' => count($r['appended_dates']), 'refused' => count($r['refused_dates']),
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Writes aggregated daily lines under the restatement rules of plan P6-2. Shared by import() and the demo seeder.
     *
     * @param  list<array<string,mixed>>  $rows  rows from CloudExportParser::aggregate()
     * @return array{inserted:int,rejected:list<array{line:int,reason:string}>,rows_refused:int,replaced_dates:list<string>,appended_dates:list<string>,refused_dates:list<string>}
     */
    public function ingestRows(CloudAccount $a, array $rows, ?ImportBatch $batch, User $by): array
    {
        $orgId = (int) $a->organization_id;
        $periods = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->get(['id', 'starts_on', 'ends_on', 'status']);
        $periodFor = function (string $date) use ($periods): ?Period {
            foreach ($periods as $p) {
                if ($p->starts_on->toDateString() <= $date && $p->ends_on->toDateString() >= $date) {
                    return $p;
                }
            }

            return null;
        };
        $ctx = $this->context($orgId, (int) $a->id);
        $fx = [];
        $result = ['inserted' => 0, 'rejected' => [], 'rows_refused' => 0, 'replaced_dates' => [], 'appended_dates' => [], 'refused_dates' => []];
        $byDate = [];
        foreach ($rows as $i => $r) {
            if (($r['currency'] ?? $a->currency) !== $a->currency) {
                $result['rejected'][] = ['line' => $i + 1, 'reason' => "{$r['service']} on {$r['usage_date']} is billed in {$r['currency']}, but the account bills in {$a->currency}"];

                continue;
            }
            $key = $r['currency'].'|'.$r['usage_date'];
            if (! array_key_exists($key, $fx)) {
                $q = $this->currency->quote($orgId, $r['currency'], $this->settings->baseCurrency($orgId), CarbonImmutable::parse($r['usage_date']), 'spot');
                $fx[$key] = $q['rate'] ?? null;
            }
            if ($fx[$key] === null) {
                $result['rejected'][] = ['line' => $i + 1, 'reason' => "no {$r['currency']}→{$this->settings->baseCurrency($orgId)} rate on {$r['usage_date']}"];

                continue;
            }
            $byDate[$r['usage_date']][] = $r + ['_rate' => $fx[$key]];
        }

        DB::transaction(function () use ($a, $orgId, $by, $batch, $ctx, $periodFor, $byDate, &$result) {
            $hits = [];
            $replacedStats = [];
            $maxDate = null;
            foreach ($byDate as $date => $list) {
                $period = $periodFor($date);
                $closed = $period && $period->status !== 'open';
                $existing = DB::table('fin_cloud_cost_lines')->where('cloud_account_id', $a->id)->whereDate('usage_date', $date)->exists();
                if ($closed && $existing) {
                    $result['refused_dates'][] = $date;
                    $result['rows_refused'] += count($list);

                    continue;
                }
                $manual = [];
                if (! $closed && $existing) {
                    $scope = fn () => DB::table('fin_cloud_cost_lines')->where('organization_id', $orgId)->where('cloud_account_id', $a->id)->whereDate('usage_date', $date);
                    $old = $scope()->get(['service', 'resource_id', 'region', 'cost_centre_id', 'ea_application_id', 'business_service_id', 'attributed_by', 'base_cost', 'import_batch_id']);
                    foreach ($old->where('attributed_by', 'manual') as $m) { // manual attributions survive a restatement
                        $manual[$m->service.'|'.($m->resource_id ?? '').'|'.($m->region ?? '')] = $m;
                    }
                    $replacedStats[$date] = [
                        'before_lines' => $old->count(), 'before_base_total' => $old->reduce(fn ($c, $l) => bcadd($c, (string) $l->base_cost, 2), '0.00'),
                        'before_batches' => $old->pluck('import_batch_id')->unique()->filter()->count(),
                    ];
                    $scope()->delete();
                    $result['replaced_dates'][] = $date;
                } elseif ($closed) {
                    $result['appended_dates'][] = $date;
                }
                $insert = [];
                $now = now();
                foreach ($list as $r) {
                    $attr = $this->attribute($a, (array) $r['tags'], $ctx);
                    if ($manual && ($m = $manual[$r['service'].'|'.($r['resource_id'] ?? '').'|'.($r['region'] ?? '')] ?? null)) {
                        $attr = ['cost_centre_id' => $m->cost_centre_id, 'ea_application_id' => $m->ea_application_id, 'business_service_id' => $m->business_service_id, 'is_untagged' => false, 'attributed_by' => 'manual', 'rule_ids' => []];
                    }
                    foreach ($attr['rule_ids'] as $rid) {
                        $hits[$rid] = ($hits[$rid] ?? 0) + 1;
                    }
                    $insert[] = [
                        'organization_id' => $orgId, 'created_by_id' => $by->id, 'cloud_account_id' => $a->id, 'import_batch_id' => $batch?->id, 'usage_date' => $date, 'period_id' => $period?->id,
                        'service' => $r['service'], 'resource_id' => $r['resource_id'] ?? null, 'region' => $r['region'] ?? null, 'tags' => $r['tags'] ? json_encode($r['tags'], JSON_UNESCAPED_UNICODE) : null,
                        'line_hash' => $r['line_hash'], 'cost' => $r['cost'], 'currency' => $r['currency'], 'fx_rate' => $r['_rate'],
                        'base_cost' => $this->round2(bcmul((string) $r['cost'], (string) $r['_rate'], 10)), 'cost_centre_id' => $attr['cost_centre_id'], 'ea_application_id' => $attr['ea_application_id'],
                        'business_service_id' => $attr['business_service_id'], 'is_untagged' => $attr['is_untagged'], 'attributed_by' => $attr['attributed_by'], 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                foreach (array_chunk($insert, (int) config('finance.cloud.chunk', 500)) as $chunk) {
                    DB::table('fin_cloud_cost_lines')->insert($chunk);
                }
                $result['inserted'] += count($insert);
                if (isset($replacedStats[$date])) {
                    $replacedStats[$date] += ['after_lines' => count($insert), 'after_base_total' => array_reduce($insert, fn ($c, $l) => bcadd($c, (string) $l['base_cost'], 2), '0.00'), 'manual_kept' => count($manual)];
                }
                $maxDate = $maxDate === null || $date > $maxDate ? $date : $maxDate;
            }
            foreach ($hits as $rid => $n) {
                DB::table('fin_cloud_tag_rules')->where('id', $rid)->update(['hits' => DB::raw('hits + '.(int) $n), 'last_hit_at' => now()]);
            }
            if ($maxDate !== null) {
                $last = $a->last_usage_date?->toDateString();
                $last === null || $maxDate > $last ? DB::table('fin_cloud_accounts')->where('id', $a->id)->update(['last_usage_date' => $maxDate, 'updated_at' => now()]) : null;
                $a->last_usage_date = $last === null || $maxDate > $last ? $maxDate : $last;
            }
            if ($replacedStats) {
                $this->audit($a, $by, 'restated', "Cloud cost lines of {$a->label} restated for ".count($replacedStats).' day(s)', [
                    'dates' => array_keys($replacedStats), 'before_lines' => array_sum(array_column($replacedStats, 'before_lines')), 'after_lines' => array_sum(array_column($replacedStats, 'after_lines')),
                    'before_base_total' => array_reduce(array_column($replacedStats, 'before_base_total'), fn ($c, $v) => bcadd($c, (string) $v, 2), '0.00'),
                    'after_base_total' => array_reduce(array_column($replacedStats, 'after_base_total'), fn ($c, $v) => bcadd($c, (string) $v, 2), '0.00'),
                    'manual_kept' => array_sum(array_column($replacedStats, 'manual_kept')), 'import_batch_id' => $batch?->id,
                ]);
            }
            if (! $batch && $result['inserted']) {
                $this->audit($a, $by, 'cost_lines_added', "{$result['inserted']} cloud cost lines added to {$a->label}", ['replaced' => count($result['replaced_dates'])]);
            }
        });

        return $result;
    }

    /* ---------------------------------------------------------------- attribution */

    /**
     * Resolves tags to a cost centre, EA application or business service (plan P6-3): account rule, then bank-wide rule, then a built-in
     * match on code or name. `is_untagged` means no mapped dimension resolved at all.
     *
     * @param  array<string,string>  $tags
     * @param  array<string,mixed>|null  $ruleCache  the context built by context(); built on demand
     * @return array{cost_centre_id:?int,ea_application_id:?int,business_service_id:?int,is_untagged:bool,attributed_by:string,rule_ids:list<int>}
     */
    public function attribute(CloudAccount $a, array $tags, ?array $ruleCache = null): array
    {
        $ctx = $ruleCache ?? $this->context((int) $a->organization_id, (int) $a->id);
        $mapping = array_change_key_case((array) ($a->tag_mapping ?? []), CASE_LOWER);
        ksort($tags);
        $got = ['cost_centre' => null, 'ea_application' => null, 'business_service' => null];
        $via = [];
        $ruleIds = [];
        $lower = [];
        foreach ($tags as $k => $v) {
            $lower[mb_strtolower((string) $k)] = [(string) $k, trim((string) $v)];
        }
        foreach ($lower as $lk => [$k, $v]) { // 1 and 2: account rules, then bank-wide rules (any tag, the rule names its dimension)
            $hit = $ctx['rules'][$lk][mb_strtolower($v)] ?? null;
            if ($hit) {
                foreach (['account', 'org'] as $scope) {
                    foreach ($hit[$scope] ?? [] as $rule) {
                        if ($got[$rule['dimension']] === null) {
                            $got[$rule['dimension']] = (int) $rule['target_id'];
                            $via[$rule['dimension']] = 'rule';
                            $ruleIds[] = (int) $rule['id'];
                        }
                    }
                }
            }
        }
        foreach ($lower as $lk => [$k, $v]) { // 3: built-in matching on the mapped dimension
            $dim = $mapping[$lk] ?? null;
            if ($dim === null || $got[$dim] !== null || $v === '') {
                continue;
            }
            $id = match ($dim) {
                'cost_centre' => $ctx['cc'][mb_strtolower($v)] ?? null,
                'ea_application' => $ctx['app'][mb_strtolower($v)] ?? null,
                'business_service' => $ctx['svc'][mb_strtolower($v)] ?? null,
                default => null,
            };
            if ($id !== null) {
                $got[$dim] = $id;
                $via[$dim] = 'builtin';
            }
        }
        $resolved = array_filter($got, fn ($v) => $v !== null);

        return [
            'cost_centre_id' => $got['cost_centre'] ?? (int) $a->default_cost_centre_id, 'ea_application_id' => $got['ea_application'], 'business_service_id' => $got['business_service'],
            'is_untagged' => $resolved === [], 'attributed_by' => $resolved === [] ? 'default' : (in_array('rule', $via, true) ? 'rule' : 'builtin'),
            'rule_ids' => array_values(array_unique($ruleIds)),
        ];
    }

    /** Loads the rules and the built-in lookup maps once per ingest. @return array<string,mixed> */
    public function context(int $orgId, ?int $accountId): array
    {
        $rules = [];
        $q = DB::table('fin_cloud_tag_rules')->where('organization_id', $orgId)->where('is_active', true);
        $accountId !== null ? $q->where(fn ($b) => $b->whereNull('cloud_account_id')->orWhere('cloud_account_id', $accountId)) : $q->whereNull('cloud_account_id');
        foreach ($q->orderBy('id')->get() as $r) {
            $scope = $r->cloud_account_id === null ? 'org' : 'account';
            $rules[mb_strtolower($r->tag_key)][mb_strtolower($r->tag_value)][$scope][] = ['id' => (int) $r->id, 'dimension' => $r->dimension, 'target_id' => (int) $r->target_id];
        }
        $cc = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => (int) $id])->all();
        $ea = fn (string $class) => $class::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))
            ->orderByRaw('CASE WHEN organization_id IS NULL THEN 1 ELSE 0 END')->orderBy('id')->get(['id', 'code', 'name']);
        $maps = [];
        foreach (['app' => EaApplication::class, 'svc' => BusinessService::class] as $key => $class) {
            $maps[$key] = [];
            foreach ($ea($class) as $m) {
                foreach ([$m->code, $m->name] as $label) {
                    $label !== null && $label !== '' && ($maps[$key][mb_strtolower($label)] ??= (int) $m->id);
                }
            }
        }

        return ['rules' => $rules, 'cc' => $cc, 'app' => $maps['app'], 'svc' => $maps['svc']];
    }

    /** Re-attributes the lines of open periods from their tags (manual assignments are kept). Returns the number of lines changed. */
    public function allocateByTags(CloudAccount $a, ?string $from, ?string $to, User $by): int
    {
        $this->sameBank($a, $by);
        $ctx = $this->context((int) $a->organization_id, (int) $a->id);
        $changed = 0;
        $closed = Period::withoutGlobalScopes()->where('organization_id', $a->organization_id)->where('status', '!=', 'open')->pluck('id');
        DB::transaction(function () use ($a, $from, $to, $ctx, $closed, &$changed) {
            DB::table('fin_cloud_cost_lines')->where('cloud_account_id', $a->id)->where(fn ($q) => $q->whereNull('attributed_by')->orWhere('attributed_by', '!=', 'manual'))
                ->when($closed->isNotEmpty(), fn ($q) => $q->where(fn ($b) => $b->whereNull('period_id')->orWhereNotIn('period_id', $closed)))
                ->when($from, fn ($q) => $q->whereDate('usage_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('usage_date', '<=', $to))
                ->orderBy('id')->chunkById(500, function ($lines) use ($a, $ctx, &$changed) {
                    foreach ($lines as $l) {
                        $attr = $this->attribute($a, (array) json_decode((string) $l->tags, true), $ctx);
                        $new = ['cost_centre_id' => $attr['cost_centre_id'], 'ea_application_id' => $attr['ea_application_id'], 'business_service_id' => $attr['business_service_id'],
                            'is_untagged' => $attr['is_untagged'], 'attributed_by' => $attr['attributed_by']];
                        $old = ['cost_centre_id' => $l->cost_centre_id, 'ea_application_id' => $l->ea_application_id, 'business_service_id' => $l->business_service_id,
                            'is_untagged' => (bool) $l->is_untagged, 'attributed_by' => $l->attributed_by];
                        if ($new != $old) {
                            DB::table('fin_cloud_cost_lines')->where('id', $l->id)->update($new + ['updated_at' => now()]);
                            $changed++;
                        }
                    }
                });
        });
        $this->audit($a, $by, 'reattributed', "Cloud cost of {$a->label} re-attributed from tags: {$changed} lines changed", ['from' => $from, 'to' => $to, 'changed' => $changed]);

        return $changed;
    }

    /** Assigns every open-period line of one resource (and service) to a dimension (plan P6-3), optionally remembering it as a tag rule. */
    public function assignUntagged(CloudAccount $a, string $resourceId, ?string $service, string $dimension, int $targetId, bool $createRule, ?string $tagKey, User $by): int
    {
        $this->sameBank($a, $by);
        in_array($dimension, CloudAccount::DIMENSIONS, true) || $this->fail('Pick a cost centre, EA application or business service.', 'dimension');
        $this->assertTarget((int) $a->organization_id, $dimension, $targetId);
        $column = ['cost_centre' => 'cost_centre_id', 'ea_application' => 'ea_application_id', 'business_service' => 'business_service_id'][$dimension];
        $closed = Period::withoutGlobalScopes()->where('organization_id', $a->organization_id)->where('status', '!=', 'open')->pluck('id');
        $base = fn () => DB::table('fin_cloud_cost_lines')->where('cloud_account_id', $a->id)->where('is_untagged', true)
            ->when($resourceId === '', fn ($q) => $q->whereNull('resource_id'), fn ($q) => $q->where('resource_id', $resourceId))
            ->when($service !== null && $service !== '', fn ($q) => $q->where('service', $service))
            ->when($closed->isNotEmpty(), fn ($q) => $q->where(fn ($b) => $b->whereNull('period_id')->orWhereNotIn('period_id', $closed)));
        $sample = $base()->orderBy('id')->first(['tags']);

        return DB::transaction(function () use ($a, $base, $column, $targetId, $dimension, $createRule, $tagKey, $by, $sample, $resourceId) {
            $n = $base()->update([$column => $targetId, 'is_untagged' => false, 'attributed_by' => 'manual', 'updated_at' => now()]);
            if ($createRule && $tagKey && $sample) {
                $tags = (array) json_decode((string) $sample->tags, true);
                $value = $tags[$tagKey] ?? null;
                if ($value !== null && $value !== '') {
                    $exists = CloudTagRule::withoutGlobalScopes()->where('organization_id', $a->organization_id)->where('cloud_account_id', $a->id)->where('tag_key', $tagKey)
                        ->where('tag_value', $value)->where('dimension', $dimension)->exists();
                    $exists || $this->storeRule(['cloud_account_id' => $a->id, 'tag_key' => $tagKey, 'tag_value' => $value, 'dimension' => $dimension, 'target_id' => $targetId], $by);
                }
            }
            $this->audit($a, $by, 'untagged_assigned', "{$n} untagged cloud lines of ".($resourceId ?: 'no resource')." assigned to {$dimension} #{$targetId}", ['lines' => $n, 'dimension' => $dimension, 'target_id' => $targetId]);

            return $n;
        });
    }

    /** @param array<string,mixed> $data */
    public function storeRule(array $data, User $by): CloudTagRule
    {
        $this->assertBankWide($by);
        $orgId = (int) $by->organization_id;
        $clean = $this->cleanRule($data, $orgId, null);
        $rule = CloudTagRule::withoutGlobalScopes()->create($clean + ['organization_id' => $orgId, 'created_by_id' => $by->id]);
        $this->audit($rule, $by, 'created', "Tag rule {$rule->tag_key}={$rule->tag_value} → {$rule->dimension} added");

        return $rule->refresh();
    }

    /** @param array<string,mixed> $data */
    public function updateRule(CloudTagRule $r, array $data, User $by): CloudTagRule
    {
        $this->assertBankWide($by);
        $this->sameBank($r, $by);
        $clean = $this->cleanRule($data + $r->only(['cloud_account_id', 'tag_key', 'tag_value', 'dimension', 'target_id', 'is_active']), (int) $r->organization_id, $r);
        $r->fill($clean)->save();

        return $r->refresh();
    }

    public function destroyRule(CloudTagRule $r, User $by): void
    {
        $this->assertBankWide($by);
        $this->sameBank($r, $by);
        $this->audit($r, $by, 'deleted', "Tag rule {$r->tag_key}={$r->tag_value} deleted");
        $r->delete();
    }

    /** @param array<string,mixed> $d @return array<string,mixed> */
    private function cleanRule(array $d, int $orgId, ?CloudTagRule $existing): array
    {
        $accountId = ! empty($d['cloud_account_id']) ? (int) $d['cloud_account_id'] : null;
        $accountId && ! CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereKey($accountId)->exists() && $this->fail('That cloud account does not belong to this bank.', 'cloud_account_id');
        $key = trim((string) ($d['tag_key'] ?? ''));
        ($key !== '' && mb_strlen($key) <= 128) || $this->fail('Give the tag key (max 128 characters).', 'tag_key');
        $value = trim((string) ($d['tag_value'] ?? ''));
        ($value !== '' && mb_strlen($value) <= 255) || $this->fail('Give the tag value (max 255 characters).', 'tag_value');
        $dimension = (string) ($d['dimension'] ?? '');
        in_array($dimension, CloudAccount::DIMENSIONS, true) || $this->fail('Pick a cost centre, EA application or business service.', 'dimension');
        $target = (int) ($d['target_id'] ?? 0);
        $this->assertTarget($orgId, $dimension, $target);
        CloudTagRule::withoutGlobalScopes()->where('organization_id', $orgId)->where('cloud_account_id', $accountId)->where('tag_key', $key)->where('tag_value', $value)->where('dimension', $dimension)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists() && $this->fail('That tag rule already exists.', 'tag_key');

        return ['cloud_account_id' => $accountId, 'tag_key' => $key, 'tag_value' => $value, 'dimension' => $dimension, 'target_id' => $target, 'is_active' => array_key_exists('is_active', $d) ? (bool) $d['is_active'] : true];
    }

    private function assertTarget(int $orgId, string $dimension, int $id): void
    {
        $ok = match ($dimension) {
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereKey($id)->exists(),
            'ea_application' => EaApplication::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->whereKey($id)->exists(),
            'business_service' => BusinessService::withoutGlobalScopes()->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))->whereKey($id)->exists(),
            default => false,
        };
        $ok || $this->fail('That target does not belong to this bank.', 'target_id');
    }

    /* ---------------------------------------------------------------- reads */

    /**
     * Totals, the daily series and the grouped table of the Costs page, all in base currency and grouped in SQL.
     *
     * @param  array<string,mixed>  $filters  account_id, from, to, group_by, tagged, service, search, owned_accounts (list of account ids the viewer owns)
     * @param  list<int>|null  $ccIds  the viewer's cost centres, null for all
     * @return array{summary:array<string,mixed>,series:list<array<string,mixed>>,groups:list<array<string,mixed>>}
     */
    public function summary(int $orgId, array $filters, ?array $ccIds): array
    {
        $q = fn () => $this->lines($orgId, $filters, $ccIds);
        $tot = $q()->selectRaw('COALESCE(SUM(l.base_cost), 0) as total, COALESCE(SUM(CASE WHEN l.is_untagged = ? THEN l.base_cost ELSE 0 END), 0) as untagged, COUNT(DISTINCT l.usage_date) as days', [true])->first();
        $total = $this->round2((string) $tot->total);
        $untagged = $this->round2((string) $tot->untagged);
        $mtd = $this->mtd($orgId, $ccIds, null, $filters['owned_accounts'] ?? []);

        $series = $q()->selectRaw('l.usage_date as d, SUM(l.base_cost) as cost, SUM(CASE WHEN l.is_untagged = ? THEN l.base_cost ELSE 0 END) as untagged', [true])
            ->groupBy('l.usage_date')->orderBy('l.usage_date')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->d, 0, 10), 'cost' => $this->round2((string) $r->cost), 'untagged' => $this->round2((string) $r->untagged)])->all();

        $groupBy = in_array($filters['group_by'] ?? null, ['service', 'account', 'cost_centre', 'ea_application', 'business_service', 'region', 'day'], true) ? $filters['group_by'] : 'service';
        $col = ['service' => 'l.service', 'account' => 'l.cloud_account_id', 'cost_centre' => 'l.cost_centre_id', 'ea_application' => 'l.ea_application_id',
            'business_service' => 'l.business_service_id', 'region' => 'l.region', 'day' => 'l.usage_date'][$groupBy];
        $rows = $q()->selectRaw("{$col} as k, SUM(l.base_cost) as cost, SUM(CASE WHEN l.is_untagged = ? THEN l.base_cost ELSE 0 END) as untagged, COUNT(*) as lines", [true])
            ->groupBy($col)->orderByRaw('SUM(l.base_cost) DESC')->limit(500)->get();
        $labels = $this->labels($orgId, $groupBy, $rows->pluck('k')->filter()->all());
        $groups = $rows->map(function ($r) use ($total, $labels, $groupBy) {
            $key = $r->k === null ? '' : ($groupBy === 'day' ? substr((string) $r->k, 0, 10) : (string) $r->k);

            return [
                'key' => $key, 'label' => $key === '' ? 'Unattributed' : ($labels[$key] ?? $key), 'cost' => $this->round2((string) $r->cost),
                'share_pct' => bccomp($total, '0', 2) === 0 ? '0.00' : bcadd(bcmul(bcdiv((string) $r->cost, $total, 8), '100', 8), '0', 2),
                'untagged' => $this->round2((string) $r->untagged), 'lines' => (int) $r->lines,
            ];
        })->all();

        return [
            'summary' => [
                'total' => $total, 'untagged' => $untagged, 'untagged_pct' => bccomp($total, '0', 2) === 0 ? '0.00' : bcadd(bcmul(bcdiv($untagged, $total, 8), '100', 8), '0', 2),
                'days' => (int) $tot->days, 'mtd' => $mtd['mtd'], 'prior_mtd' => $mtd['prior_mtd'],
            ],
            'series' => $series, 'groups' => $groups,
        ];
    }

    /**
     * The untagged queue: resources with no mapped tag, with their cost over the filter window.
     *
     * @param  array<string,mixed>  $filters
     * @param  list<int>|null  $ccIds
     * @return list<array<string,mixed>>
     */
    public function untaggedQueue(int $orgId, array $filters, ?array $ccIds, int $limit = 200): array
    {
        $rows = $this->lines($orgId, ['tagged' => 'untagged'] + $filters, $ccIds)
            ->leftJoin('fin_periods as p', 'p.id', '=', 'l.period_id')
            ->selectRaw('l.cloud_account_id, l.service, l.resource_id, l.region, SUM(l.base_cost) as cost, COUNT(*) as lines, MIN(l.usage_date) as first_date, MAX(l.usage_date) as last_date, MIN(l.id) as sample_id, '
                ."SUM(CASE WHEN p.status IS NULL OR p.status = 'open' THEN 1 ELSE 0 END) as open_lines")
            ->groupBy('l.cloud_account_id', 'l.service', 'l.resource_id', 'l.region')->orderByRaw('SUM(l.base_cost) DESC')->limit($limit)->get();
        $accounts = CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('name', 'id');
        $tags = DB::table('fin_cloud_cost_lines')->whereIn('id', $rows->pluck('sample_id')->all() ?: [0])->pluck('tags', 'id');

        return $rows->map(fn ($r) => [
            'cloud_account_id' => (int) $r->cloud_account_id, 'account' => $accounts[$r->cloud_account_id] ?? null, 'service' => $r->service, 'resource_id' => $r->resource_id, 'region' => $r->region,
            'tags' => (object) (array) json_decode((string) ($tags[$r->sample_id] ?? ''), true), 'cost' => $this->round2((string) $r->cost), 'lines' => (int) $r->lines,
            'first_date' => substr((string) $r->first_date, 0, 10), 'last_date' => substr((string) $r->last_date, 0, 10), 'in_open_period' => (int) $r->open_lines > 0,
        ])->all();
    }

    /**
     * FinOps total against the cloud lines of the ledger for a period (plan P6-1): cost pool CP-CLD, GL 6302 or a cloud-commitment contract.
     *
     * @param  list<int>|null  $ccIds
     * @return array{period:?array{id:int,name:string,status:string},finops_total:string,cloud_ledger_actual:string,gap:string,gap_pct:?string}
     */
    public function ledgerReconciliation(int $orgId, int $periodId, ?array $ccIds): array
    {
        $p = Period::withoutGlobalScopes()->where('organization_id', $orgId)->find($periodId);
        if (! $p) {
            return ['period' => null, 'finops_total' => '0.00', 'cloud_ledger_actual' => '0.00', 'gap' => '0.00', 'gap_pct' => null];
        }
        $finops = $this->lines($orgId, ['from' => $p->starts_on->toDateString(), 'to' => $p->ends_on->toDateString()], $ccIds)->sum('l.base_cost');
        $cloudCats = CostPools::treeIds($orgId, CostPools::CLOUD);
        $ledger = Actual::withoutGlobalScopes()->from('fin_actuals as a')->where('a.organization_id', $orgId)->where('a.period_id', $p->id)->where('a.match_status', '!=', 'duplicate')
            ->leftJoin('fin_gl_accounts as g', 'g.id', '=', 'a.gl_account_id')->leftJoin('fin_contracts as c', 'c.id', '=', 'a.contract_id')
            ->when($ccIds !== null, fn ($q) => $q->whereIn('a.cost_centre_id', $ccIds ?: [0]))
            ->where(function ($q) use ($cloudCats) {
                $q->where('g.code', '6302')->orWhere('c.type', 'cloud_commitment');
                $cloudCats && $q->orWhereIn('a.category_id', $cloudCats);
            })->sum('a.base_amount');
        $f = $this->round2((string) $finops);
        $l = $this->round2((string) $ledger);
        $gap = bcsub($l, $f, 2);

        return [
            'period' => ['id' => $p->id, 'name' => $p->name, 'status' => $p->status], 'finops_total' => $f, 'cloud_ledger_actual' => $l, 'gap' => $gap,
            'gap_pct' => bccomp($l, '0', 2) === 0 ? null : bcadd(bcmul(bcdiv($gap, $l, 8), '100', 8), '0', 2),
        ];
    }

    /**
     * Month-to-date cost, the same span of the previous month, and the last 30 days (dashboard, metrics).
     *
     * @param  list<int>|null  $ccIds
     * @param  list<int>  $ownedAccounts
     * @return array{mtd:string,prior_mtd:string,trend:list<array{date:string,cost:string}>}
     */
    public function mtd(int $orgId, ?array $ccIds = null, ?CarbonInterface $today = null, array $ownedAccounts = []): array
    {
        return RequestMemo::remember('cloudMtd:'.md5(json_encode([$orgId, $ccIds, $today?->toDateString(), FinanceClock::today()->toDateString(), $ownedAccounts])), fn () => $this->computeMtd($orgId, $ccIds, $today, $ownedAccounts));
    }

    private function computeMtd(int $orgId, ?array $ccIds, ?CarbonInterface $today, array $ownedAccounts): array
    {
        $today = ($today ?? FinanceClock::today())->startOfDay();
        $start = $today->startOfMonth();
        $priorStart = $start->subMonthNoOverflow();
        $priorEnd = $priorStart->addDays(min($today->day, $priorStart->daysInMonth) - 1);
        $sum = fn (string $from, string $to) => $this->round2((string) $this->lines($orgId, ['from' => $from, 'to' => $to, 'owned_accounts' => $ownedAccounts], $ccIds)->sum('l.base_cost'));
        $trendFrom = $today->subDays(29)->toDateString();
        $trend = $this->lines($orgId, ['from' => $trendFrom, 'to' => $today->toDateString(), 'owned_accounts' => $ownedAccounts], $ccIds)
            ->selectRaw('l.usage_date as d, SUM(l.base_cost) as cost')->groupBy('l.usage_date')->orderBy('l.usage_date')->get()
            ->map(fn ($r) => ['date' => substr((string) $r->d, 0, 10), 'cost' => $this->round2((string) $r->cost)])->all();

        return ['mtd' => $sum($start->toDateString(), $today->toDateString()), 'prior_mtd' => $sum($priorStart->toDateString(), $priorEnd->toDateString()), 'trend' => $trend];
    }

    /** Base query over the cost lines with the page filters and the viewer's cost-centre scope (plan P6-20). */
    private function lines(int $orgId, array $f, ?array $ccIds)
    {
        $q = DB::table('fin_cloud_cost_lines as l')->where('l.organization_id', $orgId);
        if ($ccIds !== null) {
            $owned = array_map('intval', (array) ($f['owned_accounts'] ?? []));
            $q->where(fn ($b) => $b->whereIn('l.cost_centre_id', $ccIds ?: [0])->orWhereIn('l.cloud_account_id', $owned ?: [0]));
        }
        ! empty($f['account_id']) && $q->where('l.cloud_account_id', (int) $f['account_id']);
        ! empty($f['from']) && $q->whereDate('l.usage_date', '>=', $f['from']);
        ! empty($f['to']) && $q->whereDate('l.usage_date', '<=', $f['to']);
        ($f['tagged'] ?? 'all') === 'tagged' && $q->where('l.is_untagged', false);
        ($f['tagged'] ?? 'all') === 'untagged' && $q->where('l.is_untagged', true);
        ! empty($f['service']) && $q->where('l.service', $f['service']);
        if (! empty($f['search'])) {
            $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['search'])).'%';
            $q->where(fn ($b) => $b->whereRaw('LOWER(l.service) LIKE ?', [$like])->orWhereRaw('LOWER(l.resource_id) LIKE ?', [$like]));
        }

        return $q;
    }

    /** @param list<mixed> $keys @return array<string,string> */
    private function labels(int $orgId, string $groupBy, array $keys): array
    {
        if (! $keys) {
            return [];
        }

        return match ($groupBy) {
            'account' => CloudAccount::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $keys)->pluck('name', 'id')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('id', $keys)->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [(string) $c->id => "{$c->code} {$c->name}"])->all(),
            'ea_application' => EaApplication::withoutGlobalScopes()->whereIn('id', $keys)->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [(string) $c->id => trim("{$c->code} {$c->name}")])->all(),
            'business_service' => BusinessService::withoutGlobalScopes()->whereIn('id', $keys)->get(['id', 'code', 'name'])->mapWithKeys(fn ($c) => [(string) $c->id => trim("{$c->code} {$c->name}")])->all(),
            default => [],
        };
    }

    private function round2(string $v): string
    {
        return bcadd($v, bccomp($v, '0', 10) >= 0 ? '0.005' : '-0.005', 2);
    }

    private function sameBank(object $record, User $by): void
    {
        (int) $record->organization_id === (int) $by->organization_id || $this->fail('That record belongs to another bank.');
    }
}
