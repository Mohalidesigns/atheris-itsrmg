<?php

namespace Database\Seeders;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Allocation;
use App\Models\Finance\AllocationRun;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\ChargebackRun;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CloudAnomaly;
use App\Models\Finance\CloudCostLine;
use App\Models\Finance\CloudTagRule;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Driver;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Kri;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Finance\ActualsService;
use App\Services\Finance\AllocationService;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\ChargebackService;
use App\Services\Finance\CloudAnomalyService;
use App\Services\Finance\CloudCostService;
use App\Services\Finance\CloudExportParser;
use App\Services\Finance\ContractSweeper;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\MonitoringService;
use App\Services\Finance\ShowbackService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * IT Financial Management P6 demo data (ATH-FIN-PLAN-p6 §5.1): cloud cost / FinOps, allocation and showback, capitalisation and TCO inputs per bank.
 *
 * - Showback settings, two cloud accounts per bank (owners hold `view it-finance`, linked to the cloud contract), tag rules, and 90 days of
 *   cloud cost lines (about 25 resources per account, weekly seasonality, noise, about 12 % untagged). One 3-day AWS CUR restatement per bank
 *   goes through the real CSV import with two rejected rows.
 * - Anomalies are DETECTED from the data, never inserted: a fresh spike (open), an older one resolved and one dismissed as a false positive.
 *   The older two have their notification claims back-filled so the first sweep does not resend them.
 * - Allocation rules in every method and lens (business-unit lens, application/business-service lens, First Bank's legal-entity lens), run for
 *   FY2026 on budget and actual; closed periods are frozen exactly as the close would. Baseline lines are tagged to applications (First Bank)
 *   or business services (Kano has no EA applications) so Application TCO has direct figures. An exported chargeback journal per bank, and a
 *   voided plus re-exported one at First Bank.
 * - Capitalisations in mixed states, sourced from the pool of real capex actuals (never posting actuals into closed periods; the one capex
 *   expense is billed by an approved invoice dated in the open period).
 * - The Kri row KRI-FIN-CLD (measured later by the KRI seeder).
 *
 * Guarded per tenant on the cloud accounts. Every failure is logged with its reason (warn + Log), never swallowed.
 */
class FinanceFinOpsDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB', 'domain' => 'kanoheritage.ng', 'mode' => 'khb'],
        'first-bank-nigeria' => ['prefix' => 'FBN', 'domain' => 'firstbanknigeria.ng', 'mode' => 'fbn'],
    ];

    private const QUIET = [FinanceApprovalRequested::class, FinanceApprovalDecided::class];

    private const WEEKDAY = [0 => 0.78, 1 => 1.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 1.0, 6 => 0.82];

    private int $orgId;

    private string $prefix;

    private string $domain;

    private string $mode;

    private CarbonImmutable $today;

    private int $fyId;

    /** @var array<string,User> */
    private array $who = [];

    /** @var array<string,CloudAccount> */
    private array $accounts = [];

    /** @var list<string> */
    private array $problems = [];

    /** @var array<string,int|string> */
    private array $stats = [];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || ! CostCentre::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            $this->orgId = (int) $org->id;
            $this->prefix = $bank['prefix'];
            $this->domain = $bank['domain'];
            $this->mode = $bank['mode'];
            $this->today = FinanceClock::today();
            $this->problems = [];
            $this->stats = [];
            $this->accounts = [];
            mt_srand(6000 + $this->orgId);
            foreach (['finance', 'cfo', 'headofit'] as $key) {
                $this->who[$key] = User::where('email', "{$key}@{$this->domain}")->first() ?? null;
            }
            if (in_array(null, $this->who, true)) {
                $this->warn('finance, cfo or headofit demo user missing; FinOps seed skipped.');

                continue;
            }
            if (CloudAccount::withoutGlobalScopes()->where('organization_id', $this->orgId)->exists()) {
                continue; // already seeded for this tenant
            }
            $current = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_adjustment', false)
                ->whereDate('starts_on', '<=', $this->today->toDateString())->whereDate('ends_on', '>=', $this->today->toDateString())->first();
            if (! $current) {
                $this->warn('no period contains today; FinOps seed skipped.');

                continue;
            }
            $this->fyId = (int) $current->fiscal_year_id;
            Auth::setUser($this->who['finance']);
            $this->seedBank();
        }
        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------------------------------------------ plumbing */

    private function warn(string $m): void
    {
        $this->problems[] = $m;
        $this->command?->warn("  [{$this->prefix}] finance FinOps seed: {$m}");
        Log::warning("FinanceFinOpsDemoSeeder [{$this->prefix}] {$m}");
    }

    /** Runs a step with the approval events quieted; a failure is logged with its reason and the seed continues. */
    private function step(string $label, callable $fn): mixed
    {
        try {
            $out = null;
            Event::fakeFor(function () use ($fn, &$out) {
                $out = $fn();
            }, self::QUIET);

            return $out;
        } catch (Throwable $e) {
            $msg = $e instanceof ValidationException ? json_encode($e->errors()) : $e->getMessage();
            $this->warn("{$label} failed: ".mb_substr((string) $msg, 0, 260));

            return null;
        }
    }

    private function bump(string $key, int $n = 1): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + $n;
    }

    /** Variance, allocation and cost-line writes into the demo's closed calendar: open it for one write and put it back exactly as it was. */
    private function whilePeriodsOpen(callable $fn): mixed
    {
        $closed = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->whereIn('status', ['closed', 'locked'])->pluck('status', 'id');
        Period::withoutGlobalScopes()->whereIn('id', $closed->keys())->update(['status' => 'open']);
        try {
            return $fn();
        } finally {
            foreach ($closed as $id => $status) {
                Period::withoutGlobalScopes()->whereKey($id)->update(['status' => $status]);
            }
        }
    }

    private function cc(string $code): ?CostCentre
    {
        return CostCentre::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $code)->first();
    }

    private function ccId(string $code): ?int
    {
        return $this->cc($code)?->id;
    }

    private function ownerOf(string $ccCode): User
    {
        $id = $this->cc($ccCode)?->owner_id;
        $u = $id ? User::withoutGlobalScopes()->find($id) : null;

        return $u && $u->can('view it-finance') ? $u : $this->who['headofit'];
    }

    private function service(string $code): ?BusinessService
    {
        return BusinessService::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $code)->first();
    }

    private function app(string $code): ?EaApplication
    {
        return EaApplication::withoutGlobalScopes()->where('code', $code)->where(fn ($q) => $q->where('organization_id', $this->orgId)->orWhereNull('organization_id'))->first();
    }

    /* ------------------------------------------------------------------ the bank */

    private function seedBank(): void
    {
        $this->step('settings', fn () => $this->settings());
        $this->step('cloud accounts', fn () => $this->cloudAccounts());
        $this->step('tag rules', fn () => $this->tagRules());
        $this->step('cloud cost lines', fn () => $this->costLines());
        $this->step('cloud CSV import', fn () => $this->csvImport());
        $this->step('cloud anomalies', fn () => $this->anomalies());
        $this->step('line classification', fn () => $this->classifyLines());
        $this->step('allocation rules', fn () => $this->allocationRules());
        $this->step('allocation run', fn () => $this->allocationRun());
        $this->step('chargeback', fn () => $this->chargeback());
        $this->step('capitalisations', fn () => $this->capitalisations());
        $this->step('KRI-FIN-CLD', fn () => $this->kri());
        $this->step('self-check', fn () => $this->selfCheck());

        $this->problems === []
            ? $this->command?->info("  [{$this->prefix}] finance FinOps: ".json_encode($this->stats).', no warnings.')
            : $this->command?->warn("  [{$this->prefix}] finance FinOps: ".count($this->problems).' warnings.');
    }

    private function settings(): void
    {
        $svc = app(FinanceSettings::class);
        $units = [
            ['code' => 'RET', 'name' => 'Retail Banking', 'erp_cost_centre_code' => '5100-RET'],
            ['code' => 'CIB', 'name' => 'Corporate & Investment Banking', 'erp_cost_centre_code' => '5200-CIB'],
            ['code' => 'TRS', 'name' => 'Treasury', 'erp_cost_centre_code' => '5300-TRS'],
            ['code' => 'OPS', 'name' => 'Operations', 'erp_cost_centre_code' => '5400-OPS'],
            ['code' => 'DIG', 'name' => 'Digital Channels', 'erp_cost_centre_code' => '5500-DIG'],
            ['code' => 'RSK', 'name' => 'Risk & Compliance', 'erp_cost_centre_code' => '5600-RSK'],
        ];
        if ($this->mode === 'fbn') {
            $units[] = ['code' => 'UKS', 'name' => 'UK Subsidiary', 'erp_cost_centre_code' => '5700-UKS'];
            $units[] = ['code' => 'GHS', 'name' => 'Ghana Subsidiary', 'erp_cost_centre_code' => '5800-GHS'];
        }
        $setting = $svc->for($this->orgId);
        $svc->update($setting, 'finops', ['cloud_anomaly_pct' => 30, 'cloud_anomaly_abs_floor' => '250000.00', 'showback_units' => $units, 'chargeback_debit_gl' => '6950', 'chargeback_credit_gl' => '6951']);
        $svc->update($svc->for($this->orgId), 'general', ['showback_enabled' => true]);
    }

    /* ------------------------------------------------------------------ cloud */

    private function cloudAccounts(): void
    {
        $svc = app(CloudCostService::class);
        $specs = $this->mode === 'khb' ? [
            'aws' => ['provider' => 'aws', 'account_identifier' => '402118830517', 'name' => 'Digital channels production', 'default_format' => 'aws_cur', 'cc' => 'KHB-IT-DIG', 'contract' => 'KHB-CON-0012',
                'tag_mapping' => ['CostCentre' => 'cost_centre', 'Service' => 'business_service']],
            'azure' => ['provider' => 'azure', 'account_identifier' => 'a3f1c2d4-7b6e-4f0a-9c1d-5e2b8a7f6c31', 'name' => 'Microsoft 365 & analytics', 'default_format' => 'azure', 'cc' => 'KHB-IT-DC', 'contract' => 'KHB-CON-0013',
                'tag_mapping' => ['CostCentre' => 'cost_centre', 'Service' => 'business_service']],
        ] : [
            'aws' => ['provider' => 'aws', 'account_identifier' => '731904552281', 'name' => 'Digital banking', 'default_format' => 'aws_cur', 'cc' => 'FBN-GT-DIG', 'contract' => 'FBN-CON-0012',
                'tag_mapping' => ['CostCentre' => 'cost_centre', 'Application' => 'ea_application']],
            'azure' => ['provider' => 'azure', 'account_identifier' => 'c91e7b02-5d4a-4e18-8a36-0f7d2b9c4e55', 'name' => 'Data & analytics platform', 'default_format' => 'azure', 'cc' => 'FBN-GT-DATA', 'contract' => 'FBN-CON-0013',
                'tag_mapping' => ['CostCentre' => 'cost_centre', 'Application' => 'ea_application']],
        ];
        $ownerCc = $this->mode === 'khb' ? ['aws' => 'KHB-IT-DIG', 'azure' => 'KHB-IT-DC'] : ['aws' => 'FBN-GT-DIG', 'azure' => 'FBN-GT-DC'];
        foreach ($specs as $key => $s) {
            $cc = $this->cc($s['cc']);
            if (! $cc) {
                $this->warn("cost centre {$s['cc']} missing; account {$s['name']} skipped.");

                continue;
            }
            $contract = Contract::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('contract_code', $s['contract'])->first();
            $this->accounts[$key] = $svc->storeAccount([
                'provider' => $s['provider'], 'account_identifier' => $s['account_identifier'], 'name' => $s['name'], 'default_format' => $s['default_format'], 'currency' => 'USD',
                'default_cost_centre_id' => $cc->id, 'owner_id' => $this->ownerOf($ownerCc[$key])->id, 'contract_id' => $contract?->id, 'tag_mapping' => $s['tag_mapping'],
                'notes' => 'Monthly cost export pulled by the Cloud Centre of Excellence.',
            ], $this->who['finance']);
            $this->bump('accounts');
        }
    }

    private function tagRules(): void
    {
        $svc = app(CloudCostService::class);
        $rules = [];
        if ($this->mode === 'khb') {
            foreach (['mobile' => 'BS-002', 'ussd' => 'BS-001', 'internet' => 'BS-003', 'agent' => 'BS-004', 'atm' => 'BS-005', 'core' => 'BS-008'] as $value => $code) {
                $t = $this->service($code);
                $t && $rules[] = ['tag_key' => 'Service', 'tag_value' => $value, 'dimension' => 'business_service', 'target_id' => $t->id];
            }
            $net = $this->cc('KHB-IT-NET');
            $net && $rules[] = ['tag_key' => 'Team', 'tag_value' => 'network', 'dimension' => 'cost_centre', 'target_id' => $net->id];
        } else {
            foreach (['firstmobile' => 'FBN-APP-005', 'firstonline' => 'FBN-APP-006', 'ussd' => 'FBN-APP-007', 'firstmonie' => 'FBN-APP-008', 'gateway' => 'FBN-APP-042', 'cdp' => 'FBN-APP-901'] as $value => $code) {
                $t = $this->app($code);
                $t && $rules[] = ['tag_key' => 'Application', 'tag_value' => $value, 'dimension' => 'ea_application', 'target_id' => $t->id];
            }
            $pay = $this->cc('FBN-GT-PAY');
            $pay && $rules[] = ['tag_key' => 'Team', 'tag_value' => 'payments', 'dimension' => 'cost_centre', 'target_id' => $pay->id];
        }
        foreach ($rules as $r) {
            $svc->storeRule($r, $this->who['finance']);
            $this->bump('tag_rules');
        }
        $this->stats['tag_rules'] ?? $this->warn('no tag rule could be created (targets missing).');
    }

    /**
     * The resource catalogue of one account: [service, count, base daily USD, region, tags per resource (cycled; [] is untagged)].
     *
     * @return list<array{0:string,1:int,2:float,3:string,4:list<array<string,string>>}>
     */
    private function catalogue(string $key): array
    {
        $khb = $this->mode === 'khb';
        if ($key === 'aws') {
            $t = $khb
                ? fn (string $v, bool $cc = false) => ['Service' => $v] + ($cc ? ['CostCentre' => 'KHB-IT-DIG'] : [])
                : fn (string $v, bool $cc = false) => ['Application' => $v] + ($cc ? ['CostCentre' => 'FBN-GT-DIG'] : []);
            $team = $khb ? ['Team' => 'network'] : ['Team' => 'payments'];
            [$a, $b, $c, $d, $e, $f] = $khb ? ['mobile', 'internet', 'ussd', 'agent', 'atm', 'core'] : ['firstmobile', 'firstonline', 'FBN-APP-007', 'firstmonie', 'gateway', 'cdp'];

            return [
                ['Amazon Elastic Compute Cloud', 6, $khb ? 38.0 : 40.0, 'eu-west-1', [$t($a, true), $t($c), $t($b, true), $t($d), $t($e), []]],
                ['Amazon Relational Database Service', 3, $khb ? 45.0 : 48.0, 'eu-west-1', [$t($a), $t($f), $t($b)]],
                ['Amazon Simple Storage Service', 4, 12.0, 'eu-west-1', [$t($a), $t($b), $t($d), []]],
                ['Amazon CloudFront', 2, 18.0, 'global', [$t($b), $t($a, true)]],
                ['AWS Lambda', 2, 9.0, 'eu-west-1', [$t($c), $t($d)]],
                ['Amazon Elastic Kubernetes Service', 2, $khb ? 40.0 : 42.0, 'eu-west-1', [$t($a), $t($b)]],
                ['AWS WAF', 1, 15.0, 'global', [$team]],
                ['Amazon CloudWatch', 2, 6.0, 'eu-west-1', [$team, []]],
                ['AWS Key Management Service', 1, 3.0, 'eu-west-1', [$t($f)]],
                ['Elastic Load Balancing', 2, 14.0, 'eu-west-1', [$t($e), []]],
            ];
        }
        if ($khb) {
            return [
                ['Virtual Machines', 4, 30.0, 'uksouth', [['Service' => 'Finacle Core'], ['Service' => 'Internet Banking'], ['Service' => 'Internet Banking', 'CostCentre' => 'KHB-IT-DC'], []]],
                ['Azure SQL Database', 2, 40.0, 'uksouth', [['Service' => 'Finacle Core'], ['Service' => 'Mobile Banking']]],
                ['Storage', 3, 10.0, 'uksouth', [['Service' => 'Mobile Banking'], ['Service' => 'KYC & Onboarding'], []]],
                ['Azure Synapse Analytics', 1, 55.0, 'uksouth', [['Service' => 'AML Screening']]],
                ['Azure Active Directory', 2, 8.0, 'global', [['CostCentre' => 'KHB-SEC'], ['CostCentre' => 'KHB-IT-DC']]],
                ['Azure Monitor', 2, 6.0, 'uksouth', [['CostCentre' => 'KHB-IT-DC'], []]],
                ['Azure Backup', 2, 12.0, 'uksouth', [['Service' => 'Finacle Core'], ['Service' => 'Internet Banking']]],
                ['Azure Key Vault', 1, 3.0, 'uksouth', [['CostCentre' => 'KHB-SEC']]],
            ];
        }

        return [
            ['Azure Databricks', 3, 55.0, 'uksouth', [['Application' => 'Analytics Lakehouse (Databricks)'], ['Application' => 'Analytics Lakehouse (Databricks)'], ['Application' => 'FBN-APP-020', 'CostCentre' => 'FBN-GT-DATA']]],
            ['Azure Synapse Analytics', 1, 70.0, 'uksouth', [['Application' => 'Data Warehouse (Teradata)']]],
            ['Azure SQL Database', 2, 45.0, 'uksouth', [['Application' => 'Regulatory Reporting Hub (CBN/NDPC)'], ['Application' => 'FBN-APP-901']]],
            ['Azure Data Lake Storage', 4, 14.0, 'uksouth', [['Application' => 'Analytics Lakehouse (Databricks)'], ['Application' => 'Data Warehouse (Teradata)'], ['Application' => 'Power BI Tenant'], []]],
            ['Azure Data Factory', 2, 20.0, 'uksouth', [['Application' => 'ESB (MuleSoft)'], ['Application' => 'Power BI Tenant']]],
            ['Azure Event Hubs', 2, 18.0, 'uksouth', [['Application' => 'Fraud Detection (FICO Falcon)'], []]],
            ['Azure Monitor', 2, 7.0, 'uksouth', [['CostCentre' => 'FBN-GT-DATA'], []]],
            ['Power BI Embedded', 1, 25.0, 'uksouth', [['Application' => 'Power BI Tenant']]],
            ['Virtual Machines', 3, 26.0, 'uksouth', [['Application' => 'Tableau Server'], ['Application' => 'Tableau Server'], []]],
        ];
    }

    /** @return list<array{service:string,resource_id:string,region:string,base:float,tags:array<string,string>}> */
    private function resources(string $key): array
    {
        $out = [];
        $n = 0;
        foreach ($this->catalogue($key) as [$service, $count, $base, $region, $tags]) {
            for ($i = 0; $i < $count; $i++) {
                $n++;
                $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $service));
                $out[] = [
                    'service' => $service, 'region' => $region, 'base' => $base * (1 + (($i % 3) - 1) * 0.04), 'tags' => $tags[$i % count($tags)],
                    'resource_id' => ($key === 'aws' ? 'arn:aws:'.$slug.':'.$region.':' : '/subscriptions/sub-'.strtolower($this->prefix).'/resources/'.$slug.'/').strtolower($this->prefix).sprintf('-%02d', $n),
                ];
            }
        }

        return $out;
    }

    /** The factor a service is multiplied by on a date (the injected spikes). @return array<string,array<string,float>> date => service => factor */
    private function spikes(string $key): array
    {
        $d = fn (int $ago) => $this->today->subDays($ago)->toDateString();
        if ($this->mode === 'khb') {
            return $key === 'aws' ? [
                $d(2) => ['Amazon Elastic Compute Cloud' => 3.4], $d(25) => ['Amazon Relational Database Service' => 2.6], $d(41) => ['Amazon Elastic Kubernetes Service' => 4.0],
            ] : [];
        }

        return $key === 'azure' ? [$d(3) => ['Azure Databricks' => 2.8], $d(25) => ['Azure Databricks' => 2.5], $d(41) => ['Azure Databricks' => 2.4]] : [];
    }

    /**
     * The day's rows of one account: base x weekday x slow growth x deterministic noise (+-8 %), spikes applied. Deterministic, so the CSV
     * restatement reproduces what was ingested.
     *
     * @return list<array<string,mixed>>
     */
    private function dayRows(string $key, string $date, ?array $spikes = null): array
    {
        $spikes ??= $this->spikes($key);
        $dow = (int) date('w', strtotime($date));
        $age = (int) round((strtotime($this->today->toDateString()) - strtotime($date)) / 86400);
        $rows = [];
        foreach ($this->resources($key) as $r) {
            $noise = 1 + ((crc32($r['resource_id'].$date) % 1601) - 800) / 10000;
            $factor = self::WEEKDAY[$dow] * (1 + 0.0007 * (90 - $age)) * $noise * ($spikes[$date][$r['service']] ?? 1.0);
            $rows[] = ['usage_date' => $date, 'service' => $r['service'], 'resource_id' => $r['resource_id'], 'region' => $r['region'], 'tags' => $r['tags'],
                'cost' => number_format($r['base'] * $factor, 6, '.', ''), 'currency' => 'USD'];
        }

        return $rows;
    }

    private function costLines(): void
    {
        $svc = app(CloudCostService::class);
        foreach ($this->accounts as $key => $account) {
            $rows = [];
            for ($ago = 90; $ago >= 1; $ago--) {
                $rows = array_merge($rows, $this->dayRows($key, $this->today->subDays($ago)->toDateString()));
            }
            $r = $svc->ingestRows($account, CloudExportParser::aggregate($rows), null, $this->who['finance']);
            $r['rejected'] === [] || $this->warn('cloud lines rejected for '.$account->name.': '.json_encode(array_slice($r['rejected'], 0, 2)));
            $this->bump('cloud_lines', $r['inserted']);
        }
    }

    /** One real import: a 3-day AWS CUR restatement with two bad rows (another account, an unreadable cost). */
    private function csvImport(): void
    {
        $account = $this->accounts['aws'] ?? null;
        if (! $account) {
            return;
        }
        $keys = collect($this->resources('aws'))->flatMap(fn ($r) => array_keys($r['tags']))->unique()->values()->all();
        $path = tempnam(sys_get_temp_dir(), 'cur');
        $fh = fopen($path, 'w');
        fputcsv($fh, array_merge(['lineItem/UsageAccountId', 'lineItem/UsageStartDate', 'product/ProductName', 'lineItem/ResourceId', 'product/region', 'lineItem/UnblendedCost', 'lineItem/CurrencyCode'],
            array_map(fn ($k) => 'resourceTags/user:'.$k, $keys)), ',', '"', '');
        $emit = function (array $r, string $acct) use ($fh, $keys) {
            fputcsv($fh, array_merge([$acct, $r['usage_date'].'T00:00:00Z', $r['service'], $r['resource_id'], $r['region'], $r['cost'], 'USD'], array_map(fn ($k) => $r['tags'][$k] ?? '', $keys)), ',', '"', '');
        };
        foreach ([9, 8, 7] as $ago) {
            foreach ($this->dayRows('aws', $this->today->subDays($ago)->toDateString()) as $r) {
                $emit($r, (string) $account->account_identifier);
            }
        }
        $stray = $this->dayRows('aws', $this->today->subDays(8)->toDateString())[0];
        $emit($stray, '999900001111'); // a different AWS account
        $bad = $this->dayRows('aws', $this->today->subDays(8)->toDateString())[1];
        $bad['cost'] = 'N/A';
        $emit($bad, (string) $account->account_identifier); // unreadable cost
        fclose($fh);
        $batch = app(CloudCostService::class)->import($account, new UploadedFile($path, 'aws-cur-restatement.csv', 'text/csv', null, true), 'aws_cur', $this->who['finance']);
        @unlink($path);
        $batch->rejected_count === 2 || $this->warn("the CSV import rejected {$batch->rejected_count} rows (expected 2).");
        $this->bump('cloud_imports');
    }

    private function anomalies(): void
    {
        $svc = app(CloudAnomalyService::class);
        $sweeper = app(ContractSweeper::class);
        foreach ($this->accounts as $key => $account) {
            $spikes = array_keys($this->spikes($key));
            if ($spikes === []) {
                continue;
            }
            sort($spikes); // oldest first: dismissed, resolved, fresh
            [$old, $mid, $fresh] = $spikes;
            foreach ([[$old, 'dismiss'], [$mid, 'resolve']] as [$day, $outcome]) {
                $found = $svc->detect($account, CarbonImmutable::parse($day)->addDay());
                $found->isEmpty() && $this->warn("the {$day} spike on {$account->name} was not detected.");
                foreach ($found as $x) {
                    $outcome === 'resolve'
                        ? $svc->resolve($x, 'Month-end batch re-run on the production cluster; the job was fixed and the extra cost credited by the vendor.', $this->ownerOf($this->mode === 'khb' ? 'KHB-IT-DIG' : 'FBN-GT-DC'))
                        : $svc->dismiss($x, 'Planned load test of the new release, approved by the CAB; not a real anomaly.', $this->who['finance']);
                    $sweeper->claim($x->refresh(), 'cloud_anomaly', 0, collect([$this->who['finance'], $this->who['cfo']]));
                }
            }
            $new = $svc->detect($account, $this->today);
            $new->isEmpty() && $this->warn("the {$fresh} spike on {$account->name} was not detected.");
            $this->bump('anomalies', CloudAnomaly::withoutGlobalScopes()->where('cloud_account_id', $account->id)->count());
        }
    }

    /* ------------------------------------------------------------------ allocation and showback */

    private function allocationRules(): void
    {
        $svc = app(AllocationService::class);
        $by = $this->who['finance'];
        $hc = Driver::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', 'HEADCOUNT')->first();
        $k = $this->mode === 'khb' ? 1 : 3;
        $headcount = ['RET' => 1850 * $k, 'CIB' => 320 * $k, 'TRS' => 95 * $k, 'OPS' => 640 * $k, 'DIG' => 210 * $k, 'RSK' => 140 * $k];
        $dc = $this->ccId($this->mode === 'khb' ? 'KHB-IT-DC' : 'FBN-GT-DC');
        $net = $this->ccId($this->mode === 'khb' ? 'KHB-IT-NET' : 'FBN-GT-NET');
        $itsm = $this->ccId($this->mode === 'khb' ? 'KHB-IT-ITSM' : 'FBN-GT-INF');
        $direct = $this->ccId($this->mode === 'khb' ? 'KHB-IT-TRY' : 'FBN-GT-DATA');
        $directUnit = $this->mode === 'khb' ? 'TRS' : 'RSK';
        $cbs = $this->ccId($this->mode === 'khb' ? 'KHB-IT-APP' : 'FBN-GT-CBS');
        $from = CarbonImmutable::parse(Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)->min('starts_on'))->toDateString();

        $rules = [];
        $bu = function (string $name, ?int $src, string $unit, array $extra) use (&$rules) {
            $src && $rules[] = $extra + ['name' => $name, 'source_type' => 'cost_centre', 'source_id' => $src, 'target_type' => 'business_unit', 'target_ref' => $unit];
        };
        foreach (['RET' => 35, 'CIB' => 20, 'TRS' => 10, 'OPS' => 20, 'DIG' => 15] as $unit => $pct) {
            $bu("Data centre to {$unit}", $dc, $unit, ['method' => 'percentage', 'percentage' => (string) $pct]);
        }
        if ($hc) {
            foreach ($headcount as $unit => $qty) {
                $bu("Service desk and end-user to {$unit} by headcount", $itsm, $unit, ['method' => 'driver', 'driver_id' => $hc->id, 'driver_quantity' => (string) $qty]);
            }
        } else {
            $this->warn('HEADCOUNT driver missing; driver rules skipped.');
        }
        $bu("Direct to {$directUnit}", $direct, $directUnit, ['method' => 'direct']);
        foreach (['RET' => 60, 'CIB' => 15, 'OPS' => 15, 'DIG' => 10] as $unit => $pct) {
            $bu("Network to {$unit} by branch share", $net, $unit, ['method' => 'percentage', 'percentage' => (string) $pct]);
        }
        if ($cbs) {
            $targets = $this->mode === 'khb'
                ? [['business_service', $this->service('BS-008'), 60, 'Finacle Core'], ['business_service', $this->service('BS-002'), 20, 'Mobile Banking'], ['business_service', $this->service('BS-003'), 20, 'Internet Banking']]
                : [['ea_application', $this->app('FBN-APP-001'), 60, 'Finacle'], ['ea_application', $this->app('FBN-APP-005'), 20, 'FirstMobile'], ['ea_application', $this->app('FBN-APP-006'), 20, 'FirstOnline']];
            foreach ($targets as [$type, $target, $pct, $label]) {
                $target && $rules[] = ['name' => "Core banking to {$label}", 'source_type' => 'cost_centre', 'source_id' => $cbs, 'target_type' => $type, 'target_id' => $target->id, 'method' => 'percentage', 'percentage' => (string) $pct];
            }
        }
        if ($this->mode === 'fbn') {
            foreach ([['FBN-GT-UK', 'UK-BANK', 'UK subsidiary IT'], ['FBN-GT-GH', 'GH-BANK', 'Ghana subsidiary IT']] as [$ccCode, $entityCode, $label]) {
                $entity = LegalEntity::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('code', $entityCode)->first();
                $src = $this->ccId($ccCode);
                $entity && $src && $rules[] = ['name' => "{$label} to its legal entity", 'source_type' => 'cost_centre', 'source_id' => $src, 'target_type' => 'legal_entity', 'target_id' => $entity->id, 'method' => 'direct'];
            }
        }
        $this->whilePeriodsOpen(function () use ($rules, $svc, $by, $from) {
            foreach ($rules as $r) {
                $svc->store($r + ['effective_from' => $from], $by);
                $this->bump('allocation_rules');
            }
        });
    }

    private function allocationRun(): void
    {
        $svc = app(AllocationService::class);
        $by = $this->who['finance'];
        $out = $svc->run($this->orgId, $this->fyId, null, ['budget', 'actual'], $by);
        $this->stats['allocation_run'] = collect($out)->where('status', 'computed')->count();
        // Closed periods are final: freeze them as the close would (final computation, then the runs are flagged)
        $frozen = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)->whereIn('status', ['closed', 'locked'])->get();
        foreach ($frozen as $p) {
            $svc->freeze($p, $by);
        }
        $this->stats['frozen_periods'] = $frozen->count();
    }

    private function classifyLines(): void
    {
        $khb = $this->mode === 'khb';
        $map = $khb
            ? ['finacle' => 'BS-008', 'mobile' => 'BS-002', 'ussd' => 'BS-001', 'atm' => 'BS-005', 'agent' => 'BS-004', 'internet' => 'BS-003', 'card' => 'BS-012', 'switch' => 'BS-010', 'nibss' => 'BS-010', 'aml' => 'BS-014', 'kyc' => 'BS-013', 'swift' => 'BS-010', 'treasury' => 'BS-016', 'fx ' => 'BS-016', 'bloomberg' => 'BS-016', 'open banking' => 'BS-003']
            : ['finacle' => 'FBN-APP-001', 'mobile' => 'FBN-APP-005', 'internet' => 'FBN-APP-006', 'online' => 'FBN-APP-006', 'ussd' => 'FBN-APP-007', 'agent' => 'FBN-APP-008', 'atm' => 'FBN-APP-009', 'card' => 'FBN-APP-010',
                'swift' => 'FBN-APP-011', 'nibss' => 'FBN-APP-012', 'switch' => 'FBN-APP-013', 'aml' => 'FBN-APP-014', 'fraud' => 'FBN-APP-015', 'kyc' => 'FBN-APP-016', 'crm' => 'FBN-APP-017', 'servicenow' => 'FBN-APP-024',
                'treasury' => 'FBN-APP-037', 'murex' => 'FBN-APP-037', 'api' => 'FBN-APP-042'];
        $col = $khb ? 'business_service_id' : 'ea_application_id';
        $lines = app(MonitoringService::class)->baselineLines($this->orgId, $this->fyId)->filter(fn ($l) => $l->{$col} === null);
        $done = 0;
        $subjects = [];
        foreach ($lines as $l) {
            if ($done >= 25) {
                break;
            }
            $desc = mb_strtolower((string) $l->description);
            foreach ($map as $kw => $code) {
                if (! str_contains($desc, $kw)) {
                    continue;
                }
                $target = $khb ? $this->service($code) : $this->app($code);
                if ($target) {
                    // Demo tagging writes straight to the line: the classifier refuses lines with spend in closed periods (P6 review H1), but the
                    // seeded estate is tagged before the allocation run and the chargeback so the frozen figures are computed from the tagged lines.
                    $done += DB::table('fin_budget_lines')->where('organization_id', $this->orgId)->where('id', $l->id)->update([$col => $target->id, 'updated_at' => now()]);
                    $subjects[$code] = true;
                }

                break;
            }
        }
        $this->stats['classified_lines'] = $done;
        count($subjects) >= 5 || $this->warn('only '.count($subjects).' application or service subjects were tagged on budget lines (need 5).');
    }

    private function chargeback(): void
    {
        $svc = app(ChargebackService::class);
        $closed = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)->where('status', 'closed')->orderByDesc('starts_on')->get();
        $latest = $closed->first();
        if (! $latest) {
            $this->warn('no closed period to export a chargeback for.');

            return;
        }
        if ($this->mode === 'fbn' && ($before = $closed->get(1))) {
            $first = $svc->export($this->orgId, $before->id, 'business_unit', $this->who['finance']);
            $svc->void($first, 'Journal rejected by the GL team: cost-centre code 5700-UKS was not yet loaded in the ERP.', $this->who['cfo']); // the exporter cannot void
            $svc->export($this->orgId, $before->id, 'business_unit', $this->who['finance']);
            $this->bump('chargeback_runs', 2);
        }
        $svc->export($this->orgId, $latest->id, 'business_unit', $this->who['finance']);
        $this->bump('chargeback_runs');
    }

    /* ------------------------------------------------------------------ capitalisation */

    private function capitalisations(): void
    {
        $svc = app(CapitalisationService::class);
        $khb = $this->mode === 'khb';
        $inv = fn (string $code) => Investment::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('investment_code', $code)->first();
        $release = $inv($khb ? 'KHB-IVT-0001' : 'FBN-IVT-0001');
        $closedInv = $khb ? null : $inv('FBN-IVT-0009');

        // 1. flag the sources (the closed investment is no longer editable through the service: a direct, reasoned write)
        foreach ([[$release, 'ias_38'], [$closedInv, 'ias_16']] as [$i, $std]) {
            if (! $i) {
                continue;
            }
            $i->stage === 'closed'
                ? Investment::withoutGlobalScopes()->whereKey($i->id)->update(['is_capitalisable' => true, 'capitalisation_standard' => $std])
                : app(InvestmentService::class)->update($i->refresh(), ['is_capitalisable' => true, 'capitalisation_standard' => $std], $this->who['finance']);
        }

        // 2. the capex expense: billed by an approved invoice dated in the open period (no actual is posted into a closed one)
        $expense = ExpenseRequest::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('request_code', 'EXP-0009')->where('is_capex', true)->first();
        $expense && $this->billCapexExpense($expense);

        $afuJune = $this->today->subMonthsNoOverflow(4)->endOfMonth()->toDateString(); // relative to now, never a literal year
        $afuSep = $this->today->subDays(19)->toDateString();
        $afuToday = $this->today->toDateString();

        // (a) a closed investment, capitalised with catch-up lines and a created asset (First Bank)
        if ($closedInv) {
            $c = $this->prepare($closedInv, ['title' => 'Branch SD-WAN rollout: 750 branches in service', 'standard' => 'ias_16', 'available_for_use_on' => $this->today->subMonthsNoOverflow(2)->endOfMonth()->toDateString(), 'useful_life_months' => 84,
                'asset_name' => 'Branch SD-WAN estate (750 branches)', 'asset_type' => 'network', 'asset_owner_id' => $closedInv->manager_id], 0.45, 0);
            $c && $this->decideAll($c);
        }
        // (b) a phased release of an in-delivery investment, IAS 38
        if ($release) {
            $r1 = $this->prepare($release, ['title' => ($khb ? 'Finacle 11 upgrade' : 'Finacle upgrade and decoupling').': Release 1 (core ledger) in production', 'standard' => 'ias_38', 'available_for_use_on' => $afuJune,
                'useful_life_months' => 60, 'asset_name' => ($khb ? 'Finacle 11 core ledger module' : 'Finacle decoupled core ledger module'), 'asset_type' => 'software'], 0.5, 0);
            $r1 && $this->decideAll($r1);
            // (e) a rejected one and a draft, then (d) the one waiting at the Finance Controller
            $rej = $this->prepare($release, ['title' => 'Release 1 data-migration tooling and training', 'standard' => 'ias_38', 'available_for_use_on' => $afuSep, 'useful_life_months' => 36], null, 1_000_000);
            if ($rej) {
                $this->submitAs($release, $rej);
                $rej->refresh()->status === 'submitted' && $svc->reject($rej, $this->who['finance'], 'Migration tooling and training are expensed, not capitalised (IAS 38.67); resubmit without them.');
                $this->bump('cap_rejected');
            }
            $draft = $this->prepare($release, ['title' => 'Release 2 (channels integration), evidence still being collected', 'standard' => 'ias_38', 'available_for_use_on' => $afuSep, 'useful_life_months' => 60], null, 2_000_000, false);
            $draft && $this->bump('cap_draft');
            $pending = $this->prepare($release, ['title' => 'Release 2 (channels integration) in production', 'standard' => 'ias_38', 'available_for_use_on' => $afuSep, 'useful_life_months' => 60,
                'asset_name' => ($khb ? 'Finacle 11 channels integration layer' : 'Finacle channels integration layer'), 'asset_type' => 'software'], null, 5_000_000, true, true);
            if ($pending) {
                $this->submitAs($release, $pending);
                $this->bump('cap_pending');
            }
        }
        // (c) a capex expense (hardware, IAS 16) linked to an existing asset
        if ($expense) {
            $asset = Asset::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('asset_type', 'hardware')->where(fn ($q) => $q->where('name', 'like', '%cooling%')->orWhere('name', 'like', '%UPS%')->orWhere('name', 'like', '%power%'))->first()
                ?? Asset::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('asset_type', 'hardware')->first();
            $c = $this->prepare($expense, ['title' => 'Precision cooling unit, DR site: in service', 'standard' => 'ias_16', 'available_for_use_on' => $afuToday, 'useful_life_months' => 96, 'create_asset' => $asset === null,
                'asset_id' => $asset?->id], 1.0, 0);
            $c && $this->decideAll($c);
        }
    }

    /**
     * Bills the capex expense by an approved invoice dated in the open period and posts it as the invoice approval does. The source is a clone of the
     * bank's existing capex invoice (the GL batch carries no invoice link for capex), so cost centre, line and GL are real.
     */
    private function billCapexExpense(ExpenseRequest $e): void
    {
        $src = Invoice::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_capex', true)->orderBy('id')->first();
        $period = Period::withoutGlobalScopes()->where('organization_id', $this->orgId)->where('is_adjustment', false)
            ->whereDate('starts_on', '<=', $this->today->toDateString())->whereDate('ends_on', '>=', $this->today->toDateString())->first();
        if (! $src || ! $period || $period->status !== 'open') {
            $this->warn('cannot bill the capex expense: no template capex invoice or the current period is not open.');

            return;
        }
        $date = $this->today->subDays(3)->toDateString();
        $inv = $src->replicate();
        $inv->forceFill([
            'invoice_code' => Invoice::generateNextCode($this->orgId), 'vendor_invoice_no' => 'C81/INV/2026/'.(4400 + $e->id), 'vendor_id' => $e->vendor_id ?: $src->vendor_id, 'po_id' => null,
            'expense_request_id' => $e->id, 'cost_centre_id' => $e->cost_centre_id, 'budget_line_id' => $e->budget_line_id ?: $src->budget_line_id, 'period_id' => $period->id, 'invoice_date' => $date,
            'due_on' => date('Y-m-d', strtotime($date.' +30 days')), 'subtotal' => $e->amount, 'total_amount' => $e->amount, 'base_total_amount' => $e->base_amount, 'tax_amount' => '0.00',
            'currency' => $e->currency, 'fx_rate' => $e->fx_rate, 'status' => 'approved', 'submitted_by_id' => $this->who['headofit']->id, 'approved_by_id' => $this->who['finance']->id,
            'approved_at' => now(), 'match_detail' => null, 'description' => 'Invoice against '.$e->request_code.' (capex)', 'is_capex' => true,
        ])->save();
        app(ActualsService::class)->postInvoice($inv->refresh(), $this->who['finance']);
        $this->bump('capex_invoices');
    }

    /**
     * Prepares a capitalisation as the investment manager / expense requester. `$fraction` takes that share of what is eligible (rounded down
     * to a million); `$absolute` instead takes that amount; `$allOfRest` takes what is left less `$absolute` as a buffer for the cases that follow.
     *
     * @param  array<string,mixed>  $data
     */
    private function prepare(object $source, array $data, ?float $fraction, int $absolute, bool $ready = true, bool $allOfRest = false): ?Capitalisation
    {
        $svc = app(CapitalisationService::class);
        $type = $source instanceof Investment ? 'investment' : 'expense';
        $preparers = [User::withoutGlobalScopes()->find($source instanceof Investment ? $source->manager_id : $source->requested_by_id), $this->who['headofit']];
        $e = $svc->eligibility($type, (int) $source->id, $data['available_for_use_on'], $this->orgId);
        if (bccomp($e['remaining'], '0', 2) <= 0) {
            $this->warn("nothing left to capitalise on {$data['title']}.");

            return null;
        }
        if ($allOfRest) {
            $amount = max(1_000_000, (int) (floor(((float) $e['remaining'] - $absolute) / 1_000_000) * 1_000_000));
        } elseif ($fraction !== null) {
            $amount = $fraction >= 1.0 ? (float) $e['remaining'] : (int) (floor((float) $e['remaining'] * $fraction / 1_000_000) * 1_000_000);
        } else {
            $amount = $absolute;
        }
        if ($amount > (float) $e['remaining']) {
            $this->warn("not enough left for {$data['title']} (wanted {$amount}, have {$e['remaining']}).");

            return null;
        }
        foreach ($preparers as $by) {
            if (! $by || ! $by->can('view it-finance-amounts') || ! Gate::forUser($by)->allows('capitalise', $source)) {
                continue;
            }
            Auth::setUser($by);
            try {
                $c = $svc->store($data + ['source_type' => $type, 'source_id' => $source->id, 'amount' => number_format($amount, 2, '.', ''), 'method' => 'straight_line', 'create_asset' => true], $by);
                if ($ready) {
                    $c = $svc->update($c, ['criteria_checklist' => collect($c->criteria_checklist)->map(fn ($i) => ['key' => $i['key'], 'met' => true,
                        'note' => 'Evidence is on file and was reviewed by the preparer: '.mb_strtolower($i['label'] ?? $i['key']).'.'])->all()], $by);
                }
                $this->bump('capitalisations');

                return $c->refresh();
            } finally {
                Auth::setUser($this->who['finance']);
            }
        }
        $this->warn("nobody can prepare a capitalisation for {$data['title']}.");

        return null;
    }

    private function submitAs(object $source, Capitalisation $c): void
    {
        $by = User::withoutGlobalScopes()->find($c->created_by_id) ?? $this->who['headofit'];
        Auth::setUser($by);
        try {
            app(CapitalisationService::class)->submit($c, $by);
        } finally {
            Auth::setUser($this->who['finance']);
        }
    }

    /** Submits as the preparer, then decides as the Finance Controller and the CFO until the capitalisation is booked. */
    private function decideAll(Capitalisation $c): void
    {
        $svc = app(CapitalisationService::class);
        $this->submitAs($c->source(), $c);
        foreach ([$this->who['finance'], $this->who['cfo']] as $u) {
            $c->refresh();
            if ($c->status !== 'submitted') {
                break;
            }
            Auth::setUser($u);
            try {
                $svc->approve($c, $u, 'Criteria evidenced; amount agrees to the capex ledger.');
            } finally {
                Auth::setUser($this->who['finance']);
            }
        }
        $c->refresh()->status === 'capitalised' ? $this->bump('cap_capitalised') : $this->warn("{$c->capitalisation_code} is {$c->status}, not capitalised.");
    }

    private function kri(): void
    {
        Kri::withoutGlobalScopes()->updateOrCreate(['organization_id' => $this->orgId, 'code' => 'KRI-FIN-CLD'], [
            'name' => 'Cloud cost anomalies (30 days)', 'description' => 'Cloud cost anomalies detected in the last 30 days that are still open or acknowledged.', 'category' => 'finance', 'unit' => 'count',
            'direction' => 'higher_worse', 'threshold_green' => 0, 'threshold_amber' => 2, 'threshold_red' => 5, 'frequency' => 'monthly', 'source' => 'platform', 'owner_id' => $this->who['cfo']->id,
        ]);
    }

    /* ------------------------------------------------------------------ self-check */

    private function selfCheck(): void
    {
        $o = $this->orgId;
        $q = fn (string $class) => $class::withoutGlobalScopes()->where('organization_id', $o);
        count($this->accounts) === 2 || $this->warn('expected 2 cloud accounts.');
        foreach ($this->accounts as $a) {
            $days = (int) DB::table('fin_cloud_cost_lines')->where('cloud_account_id', $a->id)->distinct()->count(DB::raw('usage_date'));
            $days >= 85 || $this->warn("{$a->name} has only {$days} days of cost.");
        }
        $q(CloudCostLine::class)->where('is_untagged', true)->exists() || $this->warn('no untagged cost lines.');
        $q(CloudAnomaly::class)->whereIn('status', ['open', 'acknowledged'])->whereDate('detected_on', '>=', $this->today->subDays(5)->toDateString())->exists() || $this->warn('no open anomaly from the seeded spike.');
        $q(CloudAnomaly::class)->where('status', 'resolved')->exists() && $q(CloudAnomaly::class)->where('status', 'false_positive')->exists() || $this->warn('resolved and dismissed anomalies are missing.');
        $q(CloudTagRule::class)->exists() || $this->warn('no tag rules.');
        $methods = $q(Allocation::class)->distinct()->pluck('method')->all();
        ($q(Allocation::class)->count() >= 8 && count($methods) === 3) || $this->warn('allocation rules: need at least 8 across all three methods, have '.$q(Allocation::class)->count().' ('.implode(',', $methods).').');
        $closed = Period::withoutGlobalScopes()->where('organization_id', $o)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)->whereIn('status', ['closed', 'locked'])->pluck('id');
        $withResults = $q(AllocationRun::class)->whereIn('period_id', $closed)->distinct()->pluck('period_id');
        $closed->diff($withResults)->isEmpty() || $this->warn('allocation results are missing for '.$closed->diff($withResults)->count().' closed periods.');
        $latest = Period::withoutGlobalScopes()->where('organization_id', $o)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)->where('status', 'closed')->orderByDesc('starts_on')->first();
        if ($latest) {
            $st = app(ShowbackService::class)->statement($o, 'business_unit', $this->fyId, (int) Period::withoutGlobalScopes()->where('organization_id', $o)->where('fiscal_year_id', $this->fyId)->where('is_adjustment', false)->orderBy('sequence')->value('id'), (int) $latest->id, 'actual', null, $this->who['finance']);
            ($st['totals']['reconciles'] ?? false) || $this->warn('the business-unit showback statement does not reconcile (difference '.($st['totals']['difference'] ?? '?').').');
        }
        $q(ChargebackRun::class)->where('status', 'exported')->exists() || $this->warn('no exported chargeback run.');
        foreach (['capitalised' => 2, 'submitted' => 1, 'draft' => 1, 'rejected' => 1] as $status => $min) {
            $n = $q(Capitalisation::class)->where('status', $status)->count();
            $n >= $min || $this->warn("capitalisations in {$status}: {$n}, need {$min}.");
        }
        $engine = app(ApprovalEngine::class);
        foreach ($q(Capitalisation::class)->where('status', 'submitted')->get() as $pending) {
            $request = $engine->current(CapitalisationService::PROCESS, $pending);
            $step = $request?->currentStep();
            $step && $engine->eligibleUsers($step, $request)->filter(fn ($u) => $engine->canDecide($step, $request, $pending, $u) && $u->can(CapitalisationService::PERMISSION))->isEmpty()
                && $this->warn("nobody can decide \"{$step->name}\" of {$pending->capitalisation_code}.");
            $step || $this->warn("{$pending->capitalisation_code} is submitted but has no pending approval step.");
        }
    }
}
