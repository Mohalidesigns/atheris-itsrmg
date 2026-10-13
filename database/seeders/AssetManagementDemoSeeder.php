<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetSyncJob;
use App\Models\Ea\BusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\Relationship;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assets\AssetCriticality;
use App\Services\Assets\AssetDiscoveryService;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Asset Management demo data (ATH-VAL-007). Runs after ChangeManagementDemoSeeder.
 *
 * The bank seeders assign asset owners and departments at random (the Board Risk Chair owned
 * Finacle), slugify names into hostnames, record no provenance, and leave First Bank with no
 * business services; the platform seeder invented sync-job totals and service dependencies.
 * This seeder makes each bank's register coherent, deterministically:
 * - owners, custodians and departments follow the asset's category and the bank's job titles;
 *   two assets per bank stay unowned and one unclassified (deliberate gaps);
 * - real hostnames; a few assets past or near end of life (one legacy core still "active");
 * - business services per bank (First Bank's on its BIAN capabilities), owners, supporting
 *   assets and `serving` dependency chains, including RTO conflicts and a service with no
 *   supporting asset (deliberate gaps);
 * - discovery history produced by actually running the sandbox connectors twice and a CSV import
 *   with rejected rows, then a reviewed queue: some finds accepted, one rejected, the rest waiting;
 * - First Bank's EA applications linked to the physical assets they run as (contract I-3), written
 *   without model events so the stewardship seals stay as EaStewardshipSeeder left them;
 * - inherited criticality computed last.
 */
class AssetManagementDemoSeeder extends Seeder
{
    private const SERVICE = BusinessService::class;

    private const BANKS = [
        'kano-heritage-bank' => [
            'admin' => 'admin@kanoheritage.ng', 'prefix' => 'khb', 'seed' => 7102,
            'people' => ['ciso' => 'admin', 'core' => 'finacle', 'legacy_core' => 'flexcube', 'payments' => 'treasury.it', 'channels' => 'digital',
                'itops' => 'itops', 'sysadmin' => 'sysadmin', 'network' => 'network', 'privacy' => 'dpco', 'soc' => 'soc.analyst',
                'compliance' => 'compliance', 'risk' => 'cro'],
            'domain' => 'kanoheritage.ng',
        ],
        'first-bank-nigeria' => [
            'admin' => 'ciso@firstbanknigeria.ng', 'prefix' => 'fbn', 'seed' => 7103,
            'people' => ['ciso' => 'ciso', 'core' => 'finacle.app', 'legacy_core' => 'flexcube.app', 'payments' => 'treasury.it', 'channels' => 'digital',
                'itops' => 'itops', 'sysadmin' => 'sysadmin', 'network' => 'network', 'privacy' => 'dpco', 'soc' => 'soc',
                'compliance' => 'compliance', 'risk' => 'cro'],
            'domain' => 'firstbanknigeria.ng',
        ],
    ];

    /** category => [department, owner role, custodian role] */
    private const CATEGORY = [
        'Core Banking' => ['Core Banking Operations', 'core', 'itops'],
        'Payments' => ['Payments & Settlement', 'payments', 'itops'],
        'Third-Party' => ['Payments & Settlement', 'payments', 'itops'],
        'Channel' => ['Digital Channels', 'channels', 'itops'],
        'Cloud' => ['IT Infrastructure', 'itops', 'sysadmin'],
        'Infrastructure' => ['IT Infrastructure', 'itops', 'sysadmin'],
        'Server' => ['IT Infrastructure', 'itops', 'sysadmin'],
        'Network' => ['Network Operations', 'itops', 'network'],
        'Facility' => ['Data Centre Operations', 'itops', 'sysadmin'],
        'Data' => ['Data Management', 'itops', 'sysadmin'],
        'Security' => ['Information Security', 'ciso', 'soc'],
        'Crypto' => ['Information Security', 'ciso', 'soc'],
    ];

    /** Per bank: owner overrides by name fragment, deliberate gaps, criticality and EOL adjustments. */
    private const TWEAKS = [
        'kano-heritage-bank' => [
            'owner' => ['Flexcube' => 'legacy_core', 'PII' => 'privacy', 'Treasury Trade' => 'payments'],
            'unowned' => ['Message Queue (RabbitMQ)', 'WhatsApp Banking Bot'],
            'unclassified' => ['Call Centre IVR'],
            'criticality' => ['Call Centre IVR' => 'low', 'Message Queue (RabbitMQ)' => 'medium', 'Linux RHEL 9 Cluster (Web)' => 'high'],
            'eol' => ['Flexcube Core Banking (Legacy)' => -13, 'VPN Concentrator (Cisco ASA)' => 45, 'Fraud Detection DB (MySQL)' => 75],
            'stale' => ['Active Directory — Secondary DC'],
        ],
        'first-bank-nigeria' => [
            'owner' => ['Flexcube' => 'legacy_core', 'Treasury' => 'payments', 'PII' => 'privacy', 'Islamic' => 'core', 'Microfinance' => 'core'],
            'unowned' => ['RabbitMQ Cluster', 'FirstCollect (Cash-in Solution)'],
            'unclassified' => ['Contact Centre IVR'],
            'criticality' => ['Contact Centre IVR' => 'low', 'GitLab Self-Managed' => 'low', 'RabbitMQ Cluster' => 'medium'],
            'eol' => ['Microfinance Core (Rural)' => -120, 'Cisco ASA VPN Concentrator' => 45, 'Thales Luna HSM Cluster (DR)' => 80],
            'stale' => ['Active Directory — Abuja DC'],
        ],
    ];

    /**
     * [capability (name or code), service, criticality, RTO, RPO, owner role|null, [asset name fragments]].
     * A null owner and an empty asset list are deliberate gaps.
     */
    private const SERVICES = [
        'kano-heritage-bank' => [
            ['Channels', 'USSD Banking', 'critical', 15, 5, 'channels', ['USSD Channel', 'F5 Load Balancer']],
            ['Channels', 'Mobile Banking', 'critical', 15, 5, 'channels', ['Mobile Banking App', 'AWS Production Account', 'F5 Load Balancer']],
            ['Channels', 'Internet Banking', 'critical', 30, 10, 'channels', ['Internet Banking Portal', 'Linux RHEL 9 Cluster', 'Cloudflare WAF']],
            ['Channels', 'Agent Banking', 'high', 30, 15, 'channels', ['Agent Banking Platform']],
            ['Channels', 'ATM Network', 'critical', 15, 5, 'channels', ['ATM Network', 'HSM (Thales Luna)']],
            ['Channels', 'PoS Terminal Network', 'high', 30, 10, 'channels', ['PoS Terminal Network', 'HSM (Thales Luna)']],
            ['Channels', 'WhatsApp Banking', 'medium', 60, 30, null, ['WhatsApp Banking Bot']],
            ['Core Banking', 'Finacle Core', 'critical', 30, 10, 'core', ['Finacle Core Banking (Prod)', 'Finacle Core Banking (DR)', 'AMB-CORE DB', 'IBM AIX p770 (Finacle Core)', 'Kano HQ Data Centre', 'Lagos DR Data Centre']],
            ['Core Banking', 'Flexcube Core', 'critical', 30, 10, 'legacy_core', ['Flexcube Core Banking (Legacy)', 'IBM AIX p770 (Backup)']],
            ['Payments', 'NIBSS NIP', 'critical', 15, 5, 'payments', ['NIBSS NIP Gateway', 'Finacle Payments Hub', 'Message Queue (RabbitMQ)']],
            ['Payments', 'Interswitch Switch', 'critical', 15, 5, 'payments', ['Interswitch Card Gateway', 'HSM (Thales Luna)']],
            ['Payments', 'Card Issuance', 'high', 60, 30, null, []],
            ['Customer Lifecycle', 'KYC & Onboarding', 'high', 240, 60, 'compliance', ['Unified Payments BVN API', 'Customer PII Data Vault', 'BVN-Linked Accounts Store']],
            ['Customer Lifecycle', 'AML Screening', 'high', 120, 60, 'compliance', ['AML Screening Store']],
            ['Customer Lifecycle', 'Loan Origination', 'medium', 480, 120, 'risk', []],
            ['Treasury', 'FX Operations', 'high', 120, 60, 'payments', ['Treasury Trade Store', 'FMDQ OTC Gateway', 'SWIFT Gateway Alliance']],
        ],
        'first-bank-nigeria' => [
            ['BIAN-CC-008-03', 'USSD Banking (*894#)', 'critical', 15, 5, 'channels', ['USSD *894#', 'F5 BIG-IP Cluster']],
            ['BIAN-CC-008-02', 'Mobile Banking (FirstMobile)', 'critical', 15, 5, 'channels', ['FirstMobile', 'AWS Production Account', 'F5 BIG-IP Cluster']],
            ['BIAN-CC-008-01', 'Internet Banking (FirstOnline)', 'critical', 30, 10, 'channels', ['FirstOnline', 'RHEL 9 Cluster (Web)', 'Cloudflare WAF']],
            ['FBN-CAP-005', 'Agent Banking (FirstMonie)', 'high', 30, 15, 'channels', ['FirstMonie Agent Banking', 'Teamapt / Moniepoint']],
            ['BIAN-CC-008-04', 'ATM & PoS Acquiring', 'critical', 15, 5, 'channels', ['ATM Network', 'PoS Terminal Network', 'FirstPay (Merchant Gateway)', 'Thales Luna HSM Cluster (Primary)']],
            ['BIAN-CC-008-05', 'Contact Centre', 'medium', 120, 60, null, ['Contact Centre IVR', 'Contact Centre CRM']],
            ['BIAN-CC-004-01', 'Instant Payments (NIBSS NIP)', 'critical', 15, 5, 'payments', ['NIBSS NIP Gateway', 'FBN Payments Hub', 'RabbitMQ Cluster']],
            ['BIAN-CC-004-02', 'Card Switching (Interswitch / Mastercard)', 'critical', 15, 5, 'payments', ['Interswitch Card Gateway', 'Mastercard Network Gateway', 'Thales Luna HSM Cluster (Primary)']],
            ['BIAN-CC-004-02', 'SWIFT Cross-border Payments', 'critical', 60, 15, 'payments', ['SWIFT Alliance Access Gateway']],
            ['BIAN-CC-003-02', 'Core Deposits & Accounts (Finacle)', 'critical', 30, 10, 'core', ['Finacle Core Banking (Prod)', 'Finacle Core Banking (DR)', 'Finacle Core DB', 'IBM Power 10', 'Lagos HQ Marina DC', 'Abuja DR DC']],
            ['BIAN-CC-003-04', 'Treasury Dealing', 'high', 120, 30, 'legacy_core', ['Flexcube Universal Banking', 'Treasury Trade Store', 'FMDQ OTC Gateway', 'CSCS Clearing Gateway']],
            ['BIAN-CC-001-01', 'Customer Onboarding & KYC', 'high', 240, 60, 'compliance', ['Unified Payments BVN API', 'Customer PII Vault', 'BVN-Linked Account Store']],
            ['BIAN-CC-006-03', 'AML & Sanctions Screening', 'high', 120, 60, 'compliance', ['AML Screening Store']],
            ['BIAN-CC-005-03', 'Regulatory Reporting (CBN / NDPC)', 'high', 1440, 240, 'risk', ['Data Warehouse (Teradata)', 'CRMS Customer Credit Bureau DB', 'IBM AIX p780 (Legacy CRMS)']],
            ['FBN-CAP-003', 'Islamic Banking (FBN Quest)', 'medium', 480, 120, null, []],
            ['FBN-CAP-004', 'Microfinance (Rural)', 'medium', 480, 120, 'core', ['Microfinance Core (Rural)']],
        ],
    ];

    /** [provider, consumer]: the provider serves the consumer. */
    private const DEPENDENCIES = [
        'kano-heritage-bank' => [
            ['Finacle Core', 'USSD Banking'], ['Finacle Core', 'Mobile Banking'], ['Finacle Core', 'Internet Banking'], ['Finacle Core', 'Agent Banking'],
            ['Finacle Core', 'ATM Network'], ['Finacle Core', 'WhatsApp Banking'], ['Finacle Core', 'NIBSS NIP'], ['Finacle Core', 'Card Issuance'],
            ['NIBSS NIP', 'USSD Banking'], ['NIBSS NIP', 'Mobile Banking'], ['NIBSS NIP', 'Internet Banking'], ['NIBSS NIP', 'Agent Banking'],
            ['Interswitch Switch', 'ATM Network'], ['Interswitch Switch', 'PoS Terminal Network'], ['Card Issuance', 'ATM Network'],
            ['KYC & Onboarding', 'Mobile Banking'], ['KYC & Onboarding', 'Loan Origination'], ['AML Screening', 'NIBSS NIP'],
            ['Flexcube Core', 'FX Operations'], ['Flexcube Core', 'Loan Origination'],
        ],
        'first-bank-nigeria' => [
            ['Core Deposits & Accounts (Finacle)', 'USSD Banking (*894#)'], ['Core Deposits & Accounts (Finacle)', 'Mobile Banking (FirstMobile)'],
            ['Core Deposits & Accounts (Finacle)', 'Internet Banking (FirstOnline)'], ['Core Deposits & Accounts (Finacle)', 'Agent Banking (FirstMonie)'],
            ['Core Deposits & Accounts (Finacle)', 'ATM & PoS Acquiring'], ['Core Deposits & Accounts (Finacle)', 'Instant Payments (NIBSS NIP)'],
            ['Core Deposits & Accounts (Finacle)', 'Regulatory Reporting (CBN / NDPC)'],
            ['Instant Payments (NIBSS NIP)', 'USSD Banking (*894#)'], ['Instant Payments (NIBSS NIP)', 'Mobile Banking (FirstMobile)'],
            ['Instant Payments (NIBSS NIP)', 'Internet Banking (FirstOnline)'], ['Instant Payments (NIBSS NIP)', 'Agent Banking (FirstMonie)'],
            ['Card Switching (Interswitch / Mastercard)', 'ATM & PoS Acquiring'],
            ['Customer Onboarding & KYC', 'Mobile Banking (FirstMobile)'], ['Customer Onboarding & KYC', 'Agent Banking (FirstMonie)'],
            ['AML & Sanctions Screening', 'Instant Payments (NIBSS NIP)'], ['AML & Sanctions Screening', 'SWIFT Cross-border Payments'],
            ['AML & Sanctions Screening', 'Regulatory Reporting (CBN / NDPC)'], ['SWIFT Cross-border Payments', 'Treasury Dealing'],
            ['Core Deposits & Accounts (Finacle)', 'Contact Centre'],
        ],
    ];

    /** First Bank EA application name => asset name, where the names differ. */
    private const EA_APP_ALIASES = [
        'FirstMobile App' => 'FirstMobile (iOS / Android)',
        'FirstOnline Internet Banking' => 'FirstOnline (Internet Banking)',
        'FirstMonie Agent Platform' => 'FirstMonie Agent Banking',
        'Oracle Flexcube (Treasury)' => 'Flexcube Universal Banking (Treasury)',
        'IslamicBanking Core (FBN Quest)' => 'Islamic Banking Core (FBN Quest)',
        'SWIFT Alliance Gateway' => 'SWIFT Alliance Access Gateway',
        'Rancher (Kubernetes)' => 'Kubernetes (Rancher) — PaaS',
        'Azure AD (Entra) Cloud Identity' => 'Entra ID (Cloud Identity)',
        'Legacy IBM AIX CRMS (EOL)' => 'IBM AIX p780 (Legacy CRMS)',
        'Proofpoint Email Security' => 'Proofpoint Email Gateway',
        'ATM Network Management' => 'ATM Network (3,500 terminals)',
        'Contact Centre (Genesys)' => 'Contact Centre IVR',
        'Tenable.io Scanner' => 'Tenable.io',
        'USSD *894# Platform' => 'USSD *894#',
    ];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin || DB::table('asset_business_service')->where('organization_id', $org->id)->exists()) {
                continue;
            }

            mt_srand($bank['seed']);
            $people = $this->people($org->id, $bank);

            $this->register($org->id, $slug, $bank, $people);
            $services = $this->services($org->id, $slug, $org->name, $people);
            $this->dependencies($org->id, $slug, $services);
            $this->discovery($org->id, $slug, $bank, $admin, $people);
            if ($slug === 'first-bank-nigeria') {
                $this->linkEaApplications($org->id);
            }

            app(AssetCriticality::class)->recompute([], $org->id);
        }
        mt_srand();
    }

    /** role => user id, from the bank's own staff (by e-mail local part). */
    private function people(int $orgId, array $bank): array
    {
        $byEmail = User::where('organization_id', $orgId)->pluck('id', 'email');

        return collect($bank['people'])->map(fn ($local) => $byEmail["{$local}@{$bank['domain']}"] ?? null)->all();
    }

    private function register(int $orgId, string $slug, array $bank, array $people): void
    {
        $tweaks = self::TWEAKS[$slug];

        foreach (Asset::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->get() as $asset) {
            [$department, $ownerRole, $custodianRole] = self::CATEGORY[$asset->category] ?? ['IT', 'itops', 'sysadmin'];
            foreach ($tweaks['owner'] as $fragment => $role) {
                if (str_contains($asset->name, $fragment)) {
                    $ownerRole = $role;
                }
            }

            $owner = in_array($asset->name, $tweaks['unowned'], true) ? null : ($people[$ownerRole] ?? null);
            $custodian = $people[$custodianRole] ?? null;

            $attrs = [
                'department' => $department,
                'owner_id' => $owner,
                'custodian_id' => $custodian !== $owner ? $custodian : null,
                'hostname' => $asset->asset_type === 'facility' ? null : Str::limit($bank['prefix'].'-'.Str::slug(preg_replace('/\(.*?\)/', '', $asset->name)), 40, ''),
                'source' => 'manual',
                'external_id' => null,
            ];
            if (in_array($asset->name, $tweaks['unclassified'], true)) {
                $attrs['data_classification'] = null;
            }
            if (isset($tweaks['criticality'][$asset->name])) {
                $attrs['criticality'] = $tweaks['criticality'][$asset->name];
            }
            if (isset($tweaks['eol'][$asset->name])) {
                $attrs['end_of_life'] = now()->addDays($tweaks['eol'][$asset->name])->toDateString();
            }

            // Query-builder write: this is a data repair, not user activity for the audit trail.
            DB::table('assets')->where('id', $asset->id)->update($attrs + ['updated_at' => now()]);
        }
    }

    /** @return array<string, int> service name => id */
    private function services(int $orgId, string $slug, string $bankName, array $people): array
    {
        $capabilities = Capability::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'code', 'name']);
        $assets = Asset::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'name']);
        $ids = [];

        foreach (self::SERVICES[$slug] as $i => [$capKey, $name, $criticality, $rto, $rpo, $ownerRole, $assetFragments]) {
            $capability = $capabilities->first(fn ($c) => $c->code === $capKey || $c->name === $capKey);

            $existing = BusinessService::withoutGlobalScopes()->where('organization_id', $orgId)->where('name', $name)->first();
            $attrs = [
                'capability_id' => $capability?->id,
                'owner_id' => $ownerRole ? ($people[$ownerRole] ?? null) : null,
                'criticality' => $criticality,
                'rto_minutes' => $rto,
                'rpo_minutes' => $rpo,
                'status' => 'active',
                'description' => $existing?->description && ! str_contains($existing->description, 'serving Nigerian banking customers')
                    ? $existing->description
                    : "{$name}: ".($capability ? "realises {$capability->name}. " : '').'Recovery objectives as agreed in the '.$bankName.' business impact analysis.',
            ];

            if ($existing) {
                DB::table('ea_business_services')->where('id', $existing->id)->update($attrs + ['updated_at' => now()]);
                $id = $existing->id;
            } else {
                $code = 'BS-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                while (DB::table('ea_business_services')->where('organization_id', $orgId)->where('code', $code)->exists()) {
                    $code = 'BS-'.str_pad((string) ((int) substr($code, 3) + 100), 3, '0', STR_PAD_LEFT);
                }
                $id = DB::table('ea_business_services')->insertGetId($attrs + [
                    'organization_id' => $orgId, 'code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $ids[$name] = $id;

            // The platform seeder named processes "Process 1 for service 3".
            foreach (DB::table('ea_processes')->where('service_id', $id)->where('name', 'like', 'Process % for service %')->orderBy('id')->pluck('id')->values() as $n => $processId) {
                DB::table('ea_processes')->where('id', $processId)->update([
                    'name' => $name.' — '.(['Customer request handling', 'Settlement & reconciliation', 'Exception handling'][$n] ?? 'Operations'),
                    'criticality' => $criticality,
                ]);
            }

            foreach ($assetFragments as $fragment) {
                foreach ($assets->filter(fn ($a) => str_contains($a->name, $fragment)) as $asset) {
                    DB::table('asset_business_service')->insertOrIgnore([
                        'organization_id' => $orgId, 'asset_id' => $asset->id, 'business_service_id' => $id,
                        'role' => 'supports', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        return $ids;
    }

    private function dependencies(int $orgId, string $slug, array $services): void
    {
        $ids = array_values($services);
        Relationship::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where(fn ($q) => $q->whereIn('source_type', ['service', self::SERVICE])->orWhereIn('target_type', ['service', self::SERVICE]))
            ->where(fn ($q) => $q->whereIn('source_id', $ids)->orWhereIn('target_id', $ids))
            ->delete();

        foreach (self::DEPENDENCIES[$slug] as [$provider, $consumer]) {
            DB::table('ea_relationships')->insert([
                'organization_id' => $orgId,
                'source_type' => self::SERVICE, 'source_id' => $services[$provider],
                'target_type' => self::SERVICE, 'target_id' => $services[$consumer],
                'relation_type' => 'serving', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Run the sandbox connectors for real (twice: a week ago and yesterday) and one CSV import, so
     * the history, provenance and review queue all come from the same reconciler the UI uses.
     */
    private function discovery(int $orgId, string $slug, array $bank, User $admin, array $people): void
    {
        AssetSyncJob::withoutGlobalScopes()->where('organization_id', $orgId)->delete();
        $service = app(AssetDiscoveryService::class);
        $order = array_keys(AssetDiscoveryService::CONNECTORS);

        foreach ([7, 1] as $daysAgo) {
            foreach ($order as $i => $source) {
                $job = $service->sync($source, $admin);
                $finished = now()->subDays($daysAgo)->setTime(1, 0)->addMinutes($i * 25 + 7);
                DB::table('asset_sync_jobs')->where('id', $job->id)->update([
                    'started_at' => $finished->copy()->subMinutes(3), 'finished_at' => $finished,
                    'created_at' => $finished, 'updated_at' => $finished,
                    // Nightly runs are scheduled; only the first, manual onboarding run has a person.
                    'triggered_by' => $daysAgo === 7 ? $admin->id : null,
                ]);
            }
        }

        $this->csvImport($orgId, $bank, $admin, $service);

        // Provenance timestamps: everything a connector reconfirmed was seen in last night's run,
        // except the assets the tweaks mark stale.
        $lastNight = now()->subDay()->setTime(1, 0)->addMinutes(mt_rand(10, 110));
        DB::table('assets')->where('organization_id', $orgId)->whereNotIn('source', ['manual', 'csv'])->update(['last_seen_at' => $lastNight]);
        DB::table('assets')->where('organization_id', $orgId)->whereIn('name', self::TWEAKS[$slug]['stale'])
            ->update(['last_seen_at' => now()->subDays(46)]);

        // Review: two finds accepted with owners, one rejected, the rest left in the queue.
        $queue = Asset::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'under_review')->orderBy('id')->get();
        $decide = function (string $fragment, array $attrs) use ($queue, $admin) {
            $asset = $queue->first(fn ($a) => str_contains($a->name, $fragment));
            if ($asset) {
                DB::table('assets')->where('id', $asset->id)->update($attrs + ['reviewed_at' => now()->subHours(20), 'reviewed_by' => $admin->id]);
            }
        };
        $decide('statement-render-worker', ['status' => 'active', 'owner_id' => $people['channels'], 'custodian_id' => $people['sysadmin'], 'criticality' => 'medium', 'department' => 'Digital Channels', 'data_classification' => 'confidential']);
        $decide('reporting replica', ['status' => 'active', 'owner_id' => $people['itops'], 'custodian_id' => $people['sysadmin'], 'criticality' => 'medium', 'department' => 'Data Management', 'data_classification' => 'restricted']);
        $decide('Treasury Excel add-in', ['status' => 'decommissioned', 'description' => "Found by entra discovery.\n\nRejected at discovery review: personal test registration by a treasury analyst; removed from the tenant."]);
    }

    private function csvImport(int $orgId, array $bank, User $admin, AssetDiscoveryService $service): void
    {
        $itops = "itops@{$bank['domain']}";
        $rows = [
            AssetDiscoveryService::CSV_COLUMNS,
            ['Branch file server — Ikeja', 'hardware', 'medium', 'active', $itops, 'IT Infrastructure', 'Ikeja branch', "{$bank['prefix']}-fs-ikj-01", '10.40.1.20', 'Dell', 'PowerEdge R650', 'internal', now()->addYears(4)->toDateString(), 'INV-2291', 'Branch file and print server'],
            ['Branch file server — Kano Sabon Gari', 'hardware', 'medium', 'active', $itops, 'IT Infrastructure', 'Sabon Gari branch', "{$bank['prefix']}-fs-sbg-01", '10.60.1.20', 'Dell', 'PowerEdge R640', 'internal', now()->addYears(2)->toDateString(), 'INV-2292', 'Branch file and print server'],
            ['Branch teller laptops (batch 14)', 'laptop', 'low', 'active', $itops, 'Retail Operations', 'Lagos Island', '', '', 'HP', 'EliteBook 840', 'internal', '', 'INV-2293', 'Teller endpoints'],
            ['Branch CCTV NVR — Ikeja', 'hardware', 'low', 'active', 'facilities.contractor@gmail.com', 'Physical Security', 'Ikeja branch', '', '10.40.9.5', 'Hikvision', 'DS-7616', 'internal', '', 'INV-2294', 'Network video recorder'],
        ];

        $path = tempnam(sys_get_temp_dir(), 'assets').'.csv';
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        $job = $service->importCsv(new UploadedFile($path, 'branch-it-inventory-q3.csv', 'text/csv', null, true), $admin);
        @unlink($path);

        $when = now()->subDays(3)->setTime(14, 12);
        DB::table('asset_sync_jobs')->where('id', $job->id)->update(['started_at' => $when->copy()->subMinute(), 'finished_at' => $when, 'created_at' => $when, 'updated_at' => $when]);
        DB::table('assets')->where('organization_id', $orgId)->where('source', 'csv')->update(['last_seen_at' => $when]);
    }

    /** EA application_ext.asset_id ← the physical asset of the same (or aliased) name. */
    private function linkEaApplications(int $orgId): void
    {
        $assets = Asset::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'name'])
            ->keyBy(fn ($a) => Str::lower($a->name));

        foreach (DB::table('ea_applications_ext')->where('organization_id', $orgId)->whereNull('asset_id')->get(['id', 'name']) as $app) {
            $asset = $assets[Str::lower(self::EA_APP_ALIASES[$app->name] ?? $app->name)] ?? null;
            if ($asset) {
                // No model events: linking the physical instance must not break the stewardship seal.
                DB::table('ea_applications_ext')->where('id', $app->id)->update(['asset_id' => $asset->id]);
            }
        }
    }
}
