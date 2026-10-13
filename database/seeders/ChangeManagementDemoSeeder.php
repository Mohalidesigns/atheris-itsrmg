<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Change\ChangeApproval;
use App\Models\Change\ChangeCabBoard;
use App\Models\Change\ChangeCabMeeting;
use App\Models\Change\ChangeCabVote;
use App\Models\Change\ChangeEvent;
use App\Models\Change\ChangeFreezeWindow;
use App\Models\Change\ChangeRequest;
use App\Models\Change\ChangeStandardTemplate;
use App\Models\Change\ChangeTask;
use App\Models\Organization;
use App\Models\User;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangeConflictDetector;
use App\Services\Change\ChangeFreezeChecker;
use App\Services\Change\ChangePolicySeeder;
use App\Services\Change\ChangeRiskScorer;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Change Management demo data (ATH-CHG-001 §10). Runs after IssuesDemoSeeder.
 *
 * Per bank, deterministically (mt_srand): the default approval policy, a CAB and ECAB with real
 * members, three freeze windows, six standard templates (one awaiting approval), two CAB
 * meetings (one held with minutes) and ~24 changes across types, categories and statuses with
 * the approvals the routing would have produced, votes on board stages, tasks, a timeline per
 * change, two scheduling conflicts, an emergency started before approval and failed / backed-out
 * changes with their PIR. Sources and affected assets are real in-bank records.
 */
class ChangeManagementDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['prefix' => 'KHB-CHG-', 'admin' => 'admin@kanoheritage.ng', 'seed' => 7301],
        'first-bank-nigeria' => ['prefix' => 'FBN-CHG-', 'admin' => 'ciso@firstbanknigeria.ng', 'seed' => 7302],
    ];

    private const TEMPLATES = [
        ['Password policy push (Group Policy)', 'security', 'Push the approved password-complexity GPO to a named OU.', 'Link the GPO to the OU; run gpupdate on a pilot host; confirm via RSoP.', 'Unlink the GPO; gpupdate /force on affected hosts.', 'low', 0, true],
        ['Routine OS patching (monthly cycle)', 'infrastructure', 'Monthly vendor OS patches on non-core servers inside the patch window.', 'Snapshot; apply the approved patch set; reboot; run the post-patch service checklist.', 'Revert to the pre-patch snapshot.', 'medium', 30, true],
        ['TLS certificate renewal', 'security', 'Renew an expiring certificate on an existing endpoint with the same key size and SANs.', 'Generate CSR; issue from the approved CA; install; restart the service; verify chain.', 'Reinstall the previous certificate from the vault.', 'low', 5, true],
        ['Firewall rule change (pre-approved pattern)', 'network', 'Add or remove a rule that matches a pre-approved pattern (named source, destination, service).', 'Stage the rule in the policy; peer review; push; confirm traffic with the requester.', 'Remove the rule and push the previous policy revision.', 'medium', 0, true],
        ['DNS record change', 'network', 'Add, change or remove an A / CNAME / TXT record in an internal or external zone.', 'Update the zone; lower TTL first where propagation matters; verify resolution.', 'Restore the previous record value.', 'low', 0, true],
        ['User access review script run', 'process', 'Run the quarterly access-review extraction script against AD and the core banking users table.', 'Run read-only script from the jump host; deliver the extract to the reviewers.', 'None required (read-only).', 'low', 0, false],
    ];

    private const SERVICES = ['Internet banking', 'Mobile banking', 'Finacle CBS', 'ATM switch', 'Card management', 'NIBSS NIP', 'Core network', 'Active Directory', 'Email (M365)', 'SWIFT', 'Treasury front office', 'Data warehouse'];

    /**
     * Scenario columns: title, type, category, priority, impact, likelihood, status, outage, services,
     * source, created_days_ago, start_offset_days (relative to now; null = no window), duration_h, flags.
     * flags: at (pending stage key for in_approval), overdue, conflict (group), freeze, retro,
     * pir (outcome), template (index), cab_votes (pending votes), rejected_at (stage key)
     */
    private const SCENARIOS = [
        // closed, oldest first
        ['Finacle 11 service pack 4 upgrade on production', 'normal', 'core_banking', 'high', 4, 3, 'closed', true, ['Finacle CBS', 'Internet banking', 'Mobile banking'], 'project', 78, -52, 8, ['pir' => 'successful']],
        ['Replace HQ perimeter firewall HA pair with PA-5450', 'normal', 'network', 'high', 4, 3, 'closed', true, ['Core network', 'Internet banking'], 'risk', 70, -48, 6, ['pir' => 'successful_with_issues']],
        ['Rotate database service account credentials (Oracle)', 'normal', 'database', 'medium', 3, 2, 'closed', false, ['Finacle CBS'], 'audit', 61, -44, 2, []],
        ['Patch CVE on internet banking web tier (critical)', 'emergency', 'application', 'critical', 4, 4, 'closed', true, ['Internet banking'], 'vulnerability', 55, -53, 3, ['pir' => 'successful']],
        ['Monthly OS patching — Windows application cluster', 'standard', 'infrastructure', 'low', 2, 2, 'closed', true, ['Active Directory'], 'other', 47, -40, 4, ['template' => 1]],
        ['Enable MFA enforcement for all privileged AD accounts', 'normal', 'security', 'high', 3, 4, 'closed', false, ['Active Directory', 'Email (M365)'], 'issue', 44, -31, 3, ['pir' => 'successful']],
        ['Migrate card management batch jobs to new scheduler', 'normal', 'application', 'medium', 3, 4, 'closed', false, ['Card management', 'ATM switch'], 'project', 40, -26, 5, ['pir' => 'backed_out']],
        ['Core switch firmware upgrade (Nexus 9K)', 'normal', 'network', 'high', 5, 2, 'closed', true, ['Core network'], 'vulnerability', 36, -24, 6, ['pir' => 'failed']],
        ['Renew wildcard TLS certificate on API gateway', 'standard', 'security', 'low', 2, 1, 'closed', false, ['Internet banking', 'Mobile banking'], 'other', 30, -22, 1, ['template' => 2]],
        ['Decommission legacy SMS gateway and cut over to new provider', 'normal', 'vendor', 'medium', 3, 2, 'closed', false, ['Mobile banking'], 'risk', 29, -16, 3, []],
        // rejected / cancelled
        ['Open outbound SSH from branch VLAN to vendor jump host', 'normal', 'network', 'medium', 3, 3, 'rejected', false, ['Core network'], 'other', 21, 5, 2, ['rejected_at' => 'security_review']],
        ['Increase NIP transaction limit at switch level', 'normal', 'core_banking', 'high', 4, 2, 'cancelled', false, ['NIBSS NIP'], 'regulatory', 19, 6, 2, []],
        // review
        ['Treasury front-office upgrade (Calypso patch bundle)', 'normal', 'application', 'high', 4, 3, 'review', false, ['Treasury front office'], 'project', 18, -2, 6, ['pir' => null]],
        ['Replace failing SAN controller in Lagos DR', 'emergency', 'infrastructure', 'critical', 5, 3, 'review', true, ['Finacle CBS', 'Data warehouse'], 'incident', 4, -1, 5, ['failed' => true]],
        // implementing
        ['Hotfix for mobile banking login loop (iOS 18.1)', 'emergency', 'application', 'critical', 4, 3, 'implementing', false, ['Mobile banking'], 'incident', 1, 0, 3, []],
        // in approval
        ['Upgrade Oracle 19c to 19.24 RU on core database', 'normal', 'database', 'high', 5, 3, 'in_approval', true, ['Finacle CBS', 'Internet banking', 'Mobile banking'], 'vulnerability', 9, 12, 8, ['at' => 'cab', 'cab_votes' => 2]],
        ['Enable TLS 1.3 only on Cloudflare WAF edge', 'normal', 'security', 'medium', 3, 3, 'in_approval', false, ['Internet banking'], 'audit', 7, 9, 2, ['at' => 'security_review']],
        ['Add NIBSS BVN v2 endpoint to API gateway allow-list', 'normal', 'network', 'medium', 2, 3, 'in_approval', false, ['NIBSS NIP'], 'regulatory', 6, 8, 1, ['at' => 'technical_review', 'overdue' => true]],
        ['Re-platform data warehouse ETL onto new cluster', 'normal', 'data', 'high', 4, 3, 'in_approval', false, ['Data warehouse'], 'project', 5, 15, 10, ['at' => 'business_approval']],
        ['Block exploited RDP exposure on jump host (ECAB pending)', 'emergency', 'security', 'critical', 4, 4, 'in_approval', false, ['Active Directory'], 'incident', 2, -1, 2, ['at' => 'ecab', 'retro' => true, 'overdue' => true, 'cab_votes' => 1]],
        // approved / scheduled
        ['Expand SWIFT Alliance HSM key ceremony to DR site', 'normal', 'security', 'high', 4, 2, 'approved', false, ['SWIFT'], 'audit', 8, null, 4, []],
        ['Quarterly firewall rule clean-up (pre-approved pattern)', 'standard', 'network', 'low', 2, 2, 'scheduled', false, ['Core network'], 'other', 6, 3, 2, ['template' => 3, 'conflict' => 'net']],
        ['Replace core switch line card (slot 4)', 'normal', 'network', 'high', 4, 3, 'scheduled', true, ['Core network', 'Internet banking'], 'risk', 11, 3, 4, ['conflict' => 'net']],
        ['Finacle month-end parameter change for new fee schedule', 'normal', 'core_banking', 'high', 4, 2, 'scheduled', false, ['Finacle CBS'], 'regulatory', 10, 'month_end', 3, ['freeze' => true]],
        ['Patch Microsoft Sentinel data connectors', 'normal', 'security', 'low', 2, 2, 'scheduled', false, ['Email (M365)'], 'other', 4, 6, 2, []],
        ['Add new ATM switch acquiring BIN range', 'normal', 'core_banking', 'medium', 3, 2, 'scheduled', false, ['ATM switch', 'Card management'], 'project', 12, 9, 3, []],
        // drafts
        ['Migrate internal DNS to Infoblox grid', 'normal', 'network', 'medium', 3, 3, 'draft', false, ['Core network', 'Active Directory'], 'project', 2, 20, 6, []],
        ['Quarterly user access review extract', 'standard', 'process', 'low', 1, 1, 'draft', false, ['Active Directory', 'Finacle CBS'], 'audit', 1, 4, 1, ['template' => 5]],
    ];

    private ChangeApprovalRouter $router;

    private ChangeRiskScorer $scorer;

    private ChangeFreezeChecker $freeze;

    private int $orgId;

    private string $prefix;

    /** @var array<string, ?User> */
    private array $people = [];

    private Collection $assets;

    private array $records = [];

    private ChangeCabBoard $cab;

    private ChangeCabBoard $ecab;

    private array $templates = [];

    private int $codeCounter = 0;

    private array $conflictGroups = [];

    public function run(): void
    {
        $this->router = app(ChangeApprovalRouter::class);
        $this->scorer = app(ChangeRiskScorer::class);
        $this->freeze = app(ChangeFreezeChecker::class);

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin) {
                continue;
            }
            if (ChangeRequest::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            Auth::setUser($admin);
            mt_srand($bank['seed']);
            $this->orgId = $org->id;
            $this->prefix = $bank['prefix'];
            $this->codeCounter = 0;
            $this->conflictGroups = [];

            $this->people();
            $this->roles();
            ChangePolicySeeder::ensureDefaults($this->orgId);
            $this->boards();
            $this->freezeWindows();
            $this->templates();
            $this->assets = Asset::withoutGlobalScopes()->where('organization_id', $this->orgId)->whereNull('deleted_at')->orderBy('id')->get();
            $this->records = $this->records();

            $changes = collect();
            foreach (self::SCENARIOS as $scenario) {
                $changes->push($this->change($scenario));
            }
            $this->meetings($changes);
        }

        mt_srand();
        Auth::forgetUser();
    }

    /* ------------------------------- people ------------------------------- */

    private function people(): void
    {
        $users = User::where('organization_id', $this->orgId)->orderBy('id')->get();
        $by = fn (string ...$needles) => $users->first(fn (User $u) => collect($needles)->contains(fn ($n) => stripos((string) $u->job_title, $n) !== false));

        $this->people = [
            'ciso' => $by('CISO'),
            'head_it' => $by('Head of IT'),
            'compliance' => $by('Head of Compliance'),
            'cro' => $by('Chief Risk Officer') ?? $by('Risk Analyst'),
            'risk_analyst' => $by('Risk Analyst'),
            'audit' => $by('Head of Internal Audit'),
            'network' => $by('Network Engineer'),
            'app_owner' => $by('Finacle'),
            'flexcube' => $by('Flexcube'),
            'digital' => $by('Head of Digital Channels'),
            'incident' => $by('Incident Respon'),
            'soc' => $by('Security Operations Analyst'),
            'sysadmin' => $by('Platform Admin'),
            'treasury' => $by('Treasury IT'),
            'dpco' => $by('DPCO'),
        ];
        foreach ($this->people as $k => $u) {
            $this->people[$k] = $u ?? $users->first();
        }
    }

    private function p(string $key): User
    {
        return $this->people[$key];
    }

    /** The Head of IT Operations is the bank's Change Manager. */
    private function roles(): void
    {
        if (Role::where('name', 'Change Manager')->exists()) {
            $this->p('head_it')->assignRole('Change Manager');
        }
    }

    /* ------------------------------- boards ------------------------------- */

    private function boards(): void
    {
        [$this->cab, $this->ecab] = ChangePolicySeeder::ensureBoards($this->orgId);
        $this->cab->forceFill(['chair_id' => $this->p('ciso')->id, 'quorum' => 3, 'meeting_cadence' => 'Weekly, Thursdays 10:00'])->save();
        $this->ecab->forceFill(['chair_id' => $this->p('ciso')->id, 'quorum' => 2])->save();

        $cabSeats = [
            ['ciso', 'chair', 'Information Security', true],
            ['head_it', 'member', 'IT Operations', true],
            ['compliance', 'member', 'Compliance', true],
            ['cro', 'member', 'Risk', true],
            ['app_owner', 'member', 'Applications', true],
            ['network', 'member', 'Infrastructure', true],
            ['digital', 'member', 'Business', true],
            ['audit', 'advisor', 'Internal Audit', false],
        ];
        foreach ($cabSeats as [$key, $role, $represents, $voting]) {
            $this->cab->members()->updateOrCreate(['user_id' => $this->p($key)->id], ['role' => $role, 'represents' => $represents, 'is_voting' => $voting]);
        }
        foreach ([['ciso', 'chair', 'Information Security'], ['head_it', 'member', 'IT Operations'], ['incident', 'member', 'Information Security']] as [$key, $role, $represents]) {
            $this->ecab->members()->updateOrCreate(['user_id' => $this->p($key)->id], ['role' => $role, 'represents' => $represents, 'is_voting' => true]);
        }
    }

    /* ---------------------------- freeze windows ---------------------------- */

    private function freezeWindows(): void
    {
        $monthEnd = now()->endOfMonth()->setTime(18, 0);
        $yearEnd = now()->month === 12 && now()->day > 28 ? Carbon::create(now()->year + 1, 12, 28) : Carbon::create(now()->year, 12, 28);
        $cbnWeek = now()->subDays(40)->startOfDay();

        $windows = [
            ['Month-end processing', $monthEnd, $monthEnd->copy()->addDays(2)->setTime(6, 0), 'categories', ['core_banking', 'database', 'application'], 'End-of-month interest, charges and GL close run on the core banking system; no change to the processing estate until the close is confirmed.', true],
            ['Year-end close (28 Dec – 3 Jan)', $yearEnd->copy()->setTime(0, 0), $yearEnd->copy()->addDays(6)->setTime(23, 59), 'all', null, 'Annual financial close, year-end reporting and the festive-season transaction peak.', true],
            ['CBN returns week', $cbnWeek, $cbnWeek->copy()->addDays(6)->setTime(23, 59), 'categories', ['core_banking', 'data', 'database'], 'Regulatory returns are compiled from the data warehouse and the core; data and core changes are deferred.', true],
        ];
        foreach ($windows as [$name, $start, $end, $scope, $categories, $reason, $allowEmergency]) {
            ChangeFreezeWindow::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $this->orgId, 'name' => $name],
                ['starts_at' => $start, 'ends_at' => $end, 'scope' => $scope, 'categories' => $categories, 'reason' => $reason, 'allow_emergency' => $allowEmergency, 'created_by_id' => $this->p('head_it')->id],
            );
        }
    }

    /* ------------------------------ templates ------------------------------ */

    private function templates(): void
    {
        foreach (self::TEMPLATES as $i => [$name, $category, $description, $plan, $backout, $risk, $downtime, $approved]) {
            $this->templates[$i] = ChangeStandardTemplate::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $this->orgId, 'name' => $name],
                [
                    'category' => $category, 'description' => $description, 'implementation_plan' => $plan, 'backout_plan' => $backout,
                    'risk_level' => $risk, 'expected_downtime_minutes' => $downtime ?: null, 'is_active' => true,
                    'approved_by_id' => $approved ? $this->p('head_it')->id : null,
                    'approved_at' => $approved ? now()->subDays(120 + $i * 9) : null,
                    'review_due_at' => $approved ? now()->addDays(245 - $i * 9)->toDateString() : null,
                    'usage_count' => $approved ? mt_rand(2, 14) : 0,
                    'created_at' => now()->subDays(130 + $i * 9), 'updated_at' => now()->subDays(120 + $i * 9),
                ],
            );
        }
    }

    /* ------------------------------- records ------------------------------- */

    private function records(): array
    {
        $out = [];
        foreach (ChangeRequest::SOURCES as $type => $meta) {
            if (! $meta['model']) {
                continue;
            }
            $out[$type] = $meta['model']::withoutGlobalScopes()->where('organization_id', $this->orgId)
                ->when(in_array(SoftDeletes::class, class_uses_recursive($meta['model'])), fn ($q) => $q->whereNull('deleted_at'))
                ->orderBy('id')->get(['id', $meta['code'].' as code', 'title'])->values();
        }

        return $out;
    }

    private function assetsFor(string $category, array $services, bool $lowRisk = false): array
    {
        if ($this->assets->isEmpty()) {
            return [];
        }
        $pool = $lowRisk ? $this->assets->where('criticality', '!=', 'critical')->values() : $this->assets;
        if ($pool->isEmpty()) {
            $pool = $this->assets;
        }
        $keywords = match ($category) {
            'core_banking' => ['Finacle', 'Core', 'Flexcube', 'Oracle'],
            'network' => ['Firewall', 'Switch', 'Load Balancer', 'VPN', 'Router'],
            'database' => ['DB', 'Oracle', 'Store', 'PostgreSQL'],
            'security' => ['Sentinel', 'HSM', 'WAF', 'Defender', 'Okta', 'Entra'],
            'infrastructure' => ['Server', 'Cluster', 'Data Centre', 'AIX', 'AWS'],
            'application' => ['Banking', 'Mobile', 'Portal', 'Gateway', 'CRM'],
            'data' => ['Warehouse', 'Lakehouse', 'Archive', 'Analytics'],
            'vendor' => ['Gateway', 'API', 'Interswitch', 'NIBSS'],
            default => ['365', 'SharePoint', 'Active Directory', 'Identity'],
        };
        $picked = $pool->filter(fn ($a) => collect($keywords)->contains(fn ($k) => stripos($a->name, $k) !== false))->take($lowRisk ? 2 : 3)->pluck('id');
        if ($picked->isEmpty()) {
            $picked = collect([$pool[mt_rand(0, $pool->count() - 1)]->id]);
        }
        if (! $lowRisk && in_array('Internet banking', $services, true) && ($ib = $this->assets->first(fn ($a) => stripos($a->name, 'Internet') !== false || stripos($a->name, 'Load Balancer') !== false))) {
            $picked->push($ib->id);
        }

        return $picked->unique()->values()->all();
    }

    /* -------------------------------- changes -------------------------------- */

    private function change(array $s): ChangeRequest
    {
        [$title, $type, $category, $priority, $impact, $likelihood, $status, $outage, $services, $source, $createdAgo, $startOffset, $durationH, $flags] = $s;

        $created = now()->subDays($createdAgo)->setTime(mt_rand(8, 16), mt_rand(0, 59));
        $requester = $this->pick(['soc', 'app_owner', 'treasury', 'digital', 'sysadmin', 'network', 'flexcube'], $category);
        $owner = $this->pickOwner($category, $requester);
        $implementer = $this->pickImplementer($category, $requester, $owner);
        $manager = $this->p('head_it');

        // Window
        $start = null;
        $end = null;
        if ($startOffset === 'month_end') {
            $start = now()->endOfMonth()->setTime(20, 0);
        } elseif ($startOffset !== null) {
            $start = now()->addDays($startOffset)->setTime($outage ? 22 : 9, 0);
        }
        if ($start) {
            if (isset($flags['conflict'])) {
                $start = $this->conflictGroups[$flags['conflict']] ??= now()->addDays($startOffset)->setTime(22, 0);
                $start = $start->copy();
            }
            $end = $start->copy()->addHours($durationH);
        }

        // Source
        $sourceId = null;
        $reference = null;
        if (isset($this->records[$source]) && $this->records[$source]->isNotEmpty()) {
            $pool = $this->records[$source];
            $sourceId = $pool[mt_rand(0, $pool->count() - 1)]->id;
        } else {
            $reference = match ($source) {
                'audit' => 'Internal Audit report IA-2026-0'.mt_rand(3, 9).', recommendation '.mt_rand(1, 12),
                'regulatory' => ['CBN circular PSM/DIR/PUB/CIR/001/0'.mt_rand(10, 99), 'NIBSS technical bulletin NIP-v2-'.mt_rand(10, 40), 'CBN IT Standards Blueprint §'.mt_rand(3, 9).'.'.mt_rand(1, 6)][mt_rand(0, 2)],
                'project' => 'Project charter PRJ-2026-'.str_pad((string) mt_rand(1, 40), 3, '0', STR_PAD_LEFT),
                default => 'Service request SR-'.mt_rand(10000, 99999),
            };
        }

        $template = isset($flags['template']) ? $this->templates[$flags['template']] : null;
        $assetIds = $this->assetsFor($category, $services, $impact * ($likelihood + ($outage ? 1 : 0)) < 10 && $category !== 'core_banking');

        $change = new ChangeRequest([
            'organization_id' => $this->orgId,
            'change_code' => $this->prefix.str_pad((string) ++$this->codeCounter, 4, '0', STR_PAD_LEFT),
            'title' => $title,
            'description' => $this->description($title, $category, $type),
            'justification' => $this->justification($source, $type),
            'change_type' => $type, 'category' => $category, 'priority' => $priority,
            'impact' => $impact, 'likelihood' => $likelihood,
            'impact_assessment' => $type === 'standard' ? null : $this->impactAssessment($services, $outage, $durationH),
            'implementation_plan' => $template?->implementation_plan ?? $this->plan($category),
            'backout_plan' => $template?->backout_plan ?? $this->backout($category),
            'test_plan' => in_array($status, ['draft'], true) && mt_rand(0, 1) ? null : $this->testPlan($category),
            'communication_plan' => $outage ? 'Customer notice on channels 48 h ahead; internal broadcast 2 h ahead; service desk briefed.' : 'Internal notice to affected teams; service desk briefed.',
            'outage_required' => $outage,
            'expected_downtime_minutes' => $outage ? $durationH * 30 : ($template?->expected_downtime_minutes),
            'affected_asset_ids' => $assetIds,
            'affected_services' => $services,
            'source_type' => $source, 'source_id' => $sourceId, 'source_reference' => $reference,
            'standard_template_id' => $template?->id,
            'requester_id' => $requester->id, 'change_owner_id' => $owner->id, 'implementer_id' => $implementer->id, 'change_manager_id' => $manager->id,
            'status' => 'draft',
            'scheduled_start_at' => $start, 'scheduled_end_at' => $end,
            'created_by_id' => $requester->id,
            'created_at' => $created, 'updated_at' => $created,
        ]);
        $this->scorer->apply($change, false);
        $change->pir_required = $type === 'emergency' || in_array($change->risk_level, ['high', 'critical'], true);
        $change->save();
        ChangeRequest::withoutGlobalScopes()->where('id', $change->id)->update(['created_at' => $created, 'updated_at' => $created]);

        $events = [];
        $events[] = [$created, 'created', ucfirst($type)." change raised ({$change->risk_level} risk, ".str_replace('_', ' ', $category).').', $requester->id];
        $events[] = [$created->copy()->addMinutes(3), 'assigned', "Change owner set to {$owner->name}.", $requester->id];
        $events[] = [$created->copy()->addMinutes(4), 'assigned', "Implementer set to {$implementer->name}.", $requester->id];
        $this->tasks($change, $status, $implementer, $created);

        if ($status === 'draft') {
            $this->flush($change, $events, $created->copy()->addMinutes(4));

            return $change;
        }

        // ---- submit & route ----
        $submitted = $created->copy()->addHours(mt_rand(1, 6));
        $attrs = ['submitted_at' => $submitted, 'status' => 'submitted'];
        $events[] = [$submitted, 'submitted', 'Submitted for approval ('.ucfirst($type).", risk {$change->risk_score} / {$change->risk_level}).", $requester->id];

        $window = $start ? $this->freeze->blocking(['scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'category' => $category], $this->orgId) : null;
        $inFreeze = $window && ! ($type === 'emergency' && $window->allow_emergency);
        if ($inFreeze) {
            $attrs['freeze_override_reason'] = 'Fee schedule is mandated by the CBN guide to charges effective on the 1st; the parameter must be live before the month-end run.';
            $events[] = [$submitted->copy()->addMinute(), 'freeze_override', "Scheduled inside freeze \"{$window->name}\": {$attrs['freeze_override_reason']}", $requester->id];
        }

        $stages = $this->router->stagesFor($this->orgId, $type, $change->risk_level, $inFreeze);
        $attrs['cab_required'] = $stages->contains(fn ($s) => $s->approver_type === 'board');
        if ($template) {
            $template->increment('usage_count');
        }

        $pendingAt = $flags['at'] ?? null;
        $rejectedAt = $flags['rejected_at'] ?? null;
        $decideAll = ! in_array($status, ['in_approval', 'rejected', 'cancelled'], true);
        $cursor = $submitted->copy()->addMinutes(2);
        $events[] = [$cursor, 'routed', $stages->isEmpty()
            ? 'No approval stage applies: pre-approved by standard change template '.($template?->name ?? '').'.'
            : 'Routed through '.$stages->count().' approval stage(s): '.$stages->pluck('name')->implode(' → ').'.', $requester->id];

        $reachedPending = false;
        $approvedAt = null;
        foreach ($stages as $stage) {
            $approval = ChangeApproval::withoutGlobalScopes()->create([
                'organization_id' => $this->orgId, 'change_request_id' => $change->id, 'stage_id' => $stage->id,
                'stage_key' => $stage->key, 'stage_name' => $stage->name, 'sequence' => $stage->sequence,
                'approver_type' => $stage->approver_type,
                'approver_ref' => $stage->approver_type === 'owner' ? (string) $owner->id : $stage->approver_ref,
                'assignee_id' => match ($stage->approver_type) {
                    'owner' => $owner->id, 'job_title' => $this->approverFor($stage->key, $owner)?->id, 'role' => $manager->id, default => null
                },
                'board_id' => $stage->approver_type === 'board' ? (int) $stage->approver_ref : null,
                'status' => 'pending', 'due_at' => $cursor->copy()->addHours($stage->sla_hours),
                'created_at' => $cursor, 'updated_at' => $cursor,
            ]);

            if ($reachedPending) {
                if (in_array($status, ['rejected', 'cancelled'], true)) {
                    $approval->forceFill(['status' => 'skipped'])->save();
                }

                continue;
            }

            $isPendingStage = ($pendingAt && $stage->key === $pendingAt) || ($status === 'cancelled' && $stage->sequence >= 40 && ! $pendingAt);
            $isRejectStage = $rejectedAt && $stage->key === $rejectedAt;

            if ($isPendingStage) {
                $reachedPending = true;
                if (! empty($flags['overdue'])) {
                    $due = now()->subHours(mt_rand(6, 30));
                    $approval->forceFill(['due_at' => $due, 'reminded_at' => $due->copy()->addMinutes(40)])->save();
                    $events[] = [$due->copy()->addMinutes(40), 'notified', "Overdue reminder sent for {$stage->name}.", null];
                }
                $events[] = [$cursor->copy()->addSeconds(30), 'notified', "{$stage->name}: approvers notified.", null];
                if ($stage->approver_type === 'board' && ! empty($flags['cab_votes'])) {
                    $this->votes($change, $approval, (int) $flags['cab_votes'], $cursor->copy()->addHours(3), $events, [$requester->id, $implementer->id, $owner->id], false);
                }
                if ($status === 'cancelled') {
                    $approval->forceFill(['status' => 'skipped'])->save();
                }

                continue;
            }

            // decided stage
            $cursor = $cursor->copy()->addHours(mt_rand(2, min(36, max(2, $stage->sla_hours))))->addMinutes(mt_rand(0, 59));
            if ($cursor->gt(now()->subHour())) {
                $cursor = now()->subHours(mt_rand(1, 3));
            }
            if ($isRejectStage) {
                $reachedPending = true;
                $decider = $this->approverFor($stage->key, $owner) ?? $manager;
                $note = 'Direct SSH from the branch VLAN bypasses the privileged access gateway; route it through the PAM bastion instead.';
                $approval->forceFill(['status' => 'rejected', 'decided_by_id' => $decider->id, 'decided_at' => $cursor, 'decision_note' => $note])->save();
                $events[] = [$cursor, 'approval', "{$stage->name}: rejected. {$note}", $decider->id];
                $events[] = [$cursor->copy()->addSecond(), 'status', 'Rejected. Later approval stages skipped.', $decider->id];
                $attrs = array_merge($attrs, ['status' => 'rejected', 'rejected_at' => $cursor, 'rejection_reason' => $note]);

                continue;
            }

            if ($stage->approver_type === 'board') {
                $board = (int) $stage->approver_ref === $this->ecab->id ? $this->ecab : $this->cab;
                $quorum = $stage->quorum ?: $board->quorum;
                $tally = $this->votes($change, $approval, $quorum + ($board === $this->cab ? mt_rand(0, 1) : 0), $cursor->copy()->subHours(mt_rand(3, 20)), $events, [$requester->id, $implementer->id, $owner->id], true);
                $note = "Quorum reached on {$board->name}: {$tally['approve']} approve, 0 reject.";
                $approval->forceFill(['status' => 'approved', 'decided_at' => $cursor, 'decision_note' => $note])->save();
                $events[] = [$cursor, 'approval', "{$stage->name}: approved. {$note}", null];
            } else {
                $decider = $this->approverFor($stage->key, $owner) ?? $manager;
                $note = $this->decisionNote($stage->key);
                $approval->forceFill(['status' => 'approved', 'decided_by_id' => $decider->id, 'decided_at' => $cursor, 'decision_note' => $note])->save();
                $events[] = [$cursor, 'approval', "{$stage->name}: approved. {$note}", $decider->id];
                if ($stage->key === 'change_manager') {
                    $attrs['approved_by_id'] = $decider->id;
                }
            }
            $approvedAt = $cursor;
        }

        if ($status === 'in_approval') {
            $attrs['status'] = 'in_approval';
            if (! empty($flags['retro'])) {
                $startedAt = now()->subHours(mt_rand(26, 40));
                $attrs = array_merge($attrs, ['status' => 'implementing', 'actual_start_at' => $startedAt, 'started_before_approval' => true,
                    'emergency_start_reason' => 'Active exploitation of the exposed RDP service observed by the SOC; the port had to be closed immediately.']);
                $events[] = [$startedAt, 'status', "Emergency implementation started before approval: {$attrs['emergency_start_reason']} ECAB and Change Manager authorisation must follow within 24 h.", $this->p('ciso')->id];
            }
            $this->flush($change, $events, $attrs);

            return $change;
        }
        if ($status === 'rejected') {
            $this->flush($change, $events, $attrs);

            return $change;
        }
        if ($status === 'cancelled') {
            $cancelledAt = $cursor->copy()->addDays(2)->min(now()->subHours(5));
            $events[] = [$cancelledAt, 'status', 'Cancelled: NIBSS confirmed the limit is enforced centrally from the next release, so no switch-level change is needed.', $requester->id];
            $this->flush($change, $events, array_merge($attrs, ['status' => 'cancelled', 'cancelled_at' => $cancelledAt, 'closure_code' => 'cancelled']));

            return $change;
        }

        // ---- approved ----
        $approvedAt ??= $cursor->copy()->addMinutes(5);
        $attrs = array_merge($attrs, ['approved_at' => $approvedAt, 'approved_by_id' => $attrs['approved_by_id'] ?? ($stages->isEmpty() ? null : $manager->id)]);
        $events[] = [$approvedAt->copy()->addSecond(), 'status', 'Approved.'.($start ? ' Window already set: scheduled.' : ' Ready to schedule.'), $manager->id];
        if ($status === 'approved') {
            $this->flush($change, $events, array_merge($attrs, ['status' => 'approved']));

            return $change;
        }

        // ---- scheduled ----
        $scheduledAt = $approvedAt->copy()->addHours(mt_rand(1, 20))->min(now()->subHours(2));
        $events[] = [$scheduledAt, 'scheduled', 'Scheduled for '.$start->format('j M Y H:i').' – '.$end->format('j M Y H:i').'.', $manager->id];
        $attrs['status'] = 'scheduled';
        if ($status === 'scheduled') {
            $this->flush($change, $events, $attrs);
            $this->conflictEvent($change, $scheduledAt->copy()->addSecond());

            return $change;
        }

        // ---- implementing ----
        $actualStart = $type === 'emergency' && $start->lte(now()) ? $start->copy()->addMinutes(mt_rand(5, 40)) : $start->copy()->addMinutes(mt_rand(0, 30));
        if ($status === 'implementing') {
            $actualStart = now()->subMinutes(mt_rand(30, 150));
        }
        $events[] = [$actualStart, 'status', 'Implementation started.', $implementer->id];
        $attrs = array_merge($attrs, ['status' => 'implementing', 'actual_start_at' => $actualStart]);
        if ($status === 'implementing') {
            $this->flush($change, $events, $attrs);

            return $change;
        }

        // ---- outcome ----
        $actualEnd = $actualStart->copy()->addHours($durationH)->addMinutes(mt_rand(-30, 90))->min(now()->subHours(1));
        $pir = $flags['pir'] ?? 'successful';
        $failed = ! empty($flags['failed']) || in_array($pir, ['failed', 'backed_out'], true);
        $outcomeCode = $failed ? ($pir === 'backed_out' ? 'backed_out' : 'failed') : 'implemented';

        if ($failed) {
            $note = $outcomeCode === 'backed_out'
                ? 'Batch jobs on the new scheduler missed the card settlement cut-off window; reverted to the legacy scheduler per the back-out plan.'
                : 'Post-upgrade the device failed to rejoin the vPC domain; service restored from the standby but the primary needed a vendor RMA.';
            $events[] = [$actualEnd, 'status', ($outcomeCode === 'backed_out' ? 'Backed out: ' : 'Failed: ').$note, $implementer->id];
            $events[] = [$actualEnd->copy()->addSecond(), 'status', 'Post-implementation review required.', $implementer->id];
            $attrs = array_merge($attrs, ['status' => 'review', 'actual_end_at' => $actualEnd, 'pir_required' => true, 'closure_code' => $outcomeCode]);
        } else {
            $events[] = [$actualEnd, 'status', 'Implemented: post-implementation checks passed.', $implementer->id];
            $attrs = array_merge($attrs, ['actual_end_at' => $actualEnd, 'implemented_at' => $actualEnd, 'closure_code' => 'implemented']);
            if ($change->pir_required) {
                $events[] = [$actualEnd->copy()->addSecond(), 'status', 'Awaiting post-implementation review.', $implementer->id];
                $attrs['status'] = 'review';
            } else {
                $attrs = array_merge($attrs, ['status' => 'closed', 'closed_at' => $actualEnd]);
                $events[] = [$actualEnd->copy()->addSecond(), 'status', 'Closed (implemented, no PIR required).', $implementer->id];
            }
        }
        $this->closeTasks($change, $actualEnd, $implementer);

        if ($status === 'review' || $attrs['status'] === 'closed') {
            $this->flush($change, $events, $attrs);

            return $change;
        }

        // ---- PIR & close ----
        $reviewer = $this->p('ciso')->id === $implementer->id ? $this->p('compliance') : $this->p('ciso');
        $reviewedAt = $actualEnd->copy()->addDays(mt_rand(1, 5))->min(now()->subHours(1));
        $summary = $this->pirSummary($pir);
        $events[] = [$reviewedAt, 'pir', 'PIR outcome: '.str_replace('_', ' ', $pir).". {$summary}", $reviewer->id];
        $events[] = [$reviewedAt->copy()->addSecond(), 'status', 'Closed after post-implementation review.', $reviewer->id];
        $attrs = array_merge($attrs, [
            'status' => 'closed', 'pir_outcome' => $pir, 'pir_summary' => $summary, 'pir_by_id' => $reviewer->id,
            'reviewed_at' => $reviewedAt, 'closed_at' => $reviewedAt, 'closure_code' => $outcomeCode,
        ]);
        $attrs['status'] = 'closed';
        $this->flush($change, $events, $attrs);

        return $change;
    }

    /** @return array{approve:int} */
    private function votes(ChangeRequest $change, ChangeApproval $approval, int $count, Carbon $from, array &$events, array $excluded, bool $allApprove): array
    {
        $board = (int) $approval->board_id === $this->ecab->id ? $this->ecab : $this->cab;
        $voters = $board->members()->where('is_voting', true)->whereNotIn('user_id', $excluded)->with('user')->get()->pluck('user')->filter()->values();
        $tally = ['approve' => 0];
        $at = $from->copy();
        foreach ($voters->take($count) as $i => $voter) {
            $vote = $allApprove || $i < $count - 1 || mt_rand(0, 2) ? 'approve' : 'abstain';
            if ($allApprove && $i >= $board->quorum) {
                $vote = 'abstain';
            }
            $comment = $vote === 'approve' ? ['Risk assessment and back-out plan are adequate.', 'No objection; window avoids the processing peak.', 'Supported, subject to the communication plan.', null][mt_rand(0, 3)] : 'Not close enough to the system to vote either way.';
            $at = $at->copy()->addMinutes(mt_rand(20, 240));
            if ($at->gt(now()->subMinutes(10))) {
                $at = now()->subMinutes(mt_rand(10, 60));
            }
            ChangeCabVote::withoutGlobalScopes()->create([
                'organization_id' => $this->orgId, 'change_request_id' => $change->id, 'approval_id' => $approval->id,
                'user_id' => $voter->id, 'vote' => $vote, 'comment' => $comment, 'voted_at' => $at, 'created_at' => $at, 'updated_at' => $at,
            ]);
            if ($vote === 'approve') {
                $tally['approve']++;
            }
            $events[] = [$at, 'cab_vote', "{$voter->name} voted {$vote}".($comment ? ": {$comment}" : '.'), $voter->id];
        }

        return $tally;
    }

    private function approverFor(string $stageKey, User $owner): ?User
    {
        return match ($stageKey) {
            'technical_review' => $this->p('head_it'),
            'security_review' => $this->p('ciso'),
            'business_approval' => $owner,
            'change_manager' => $this->p('head_it'),
            default => null,
        };
    }

    private function tasks(ChangeRequest $change, string $status, User $implementer, Carbon $created): void
    {
        $defs = [
            ['Confirm backup / snapshot taken', 'pre_implementation', 1],
            ['Notify affected teams and service desk', 'communication', 2],
            ['Execute implementation plan', 'implementation', 3],
            ['Run post-implementation validation checks', 'validation', 4],
        ];
        if ($change->outage_required) {
            $defs[] = ['Publish customer outage notice', 'communication', 0];
        }
        if ($status === 'draft' && mt_rand(0, 1)) {
            $defs = array_slice($defs, 0, 2);
        }
        usort($defs, fn ($a, $b) => $a[2] <=> $b[2]);
        $assignees = [$implementer, $this->p('network'), $this->p('sysadmin'), $this->p('soc')];
        foreach ($defs as $i => [$title, $type, $seq]) {
            ChangeTask::withoutGlobalScopes()->create([
                'organization_id' => $this->orgId, 'change_request_id' => $change->id, 'title' => $title, 'type' => $type,
                'assignee_id' => $type === 'communication' ? $this->p('digital')->id : $assignees[$i % count($assignees)]->id,
                'sequence' => $i + 1, 'status' => 'pending',
                'due_at' => $change->scheduled_start_at?->copy()->subHours($type === 'validation' ? -2 : 4),
                'created_at' => $created->copy()->addMinutes(10 + $i), 'updated_at' => $created->copy()->addMinutes(10 + $i),
            ]);
        }
    }

    private function closeTasks(ChangeRequest $change, Carbon $at, User $implementer): void
    {
        foreach (ChangeTask::withoutGlobalScopes()->where('change_request_id', $change->id)->get() as $i => $task) {
            $done = $at->copy()->subMinutes(max(1, (4 - $i) * 25));
            $task->forceFill(['status' => 'done', 'completed_at' => $done, 'completed_by_id' => $task->assignee_id ?? $implementer->id, 'updated_at' => $done])->save();
        }
    }

    private function conflictEvent(ChangeRequest $change, Carbon $at): void
    {
        $conflicts = app(ChangeConflictDetector::class)->conflicts($change->fresh());
        if (! $conflicts) {
            return;
        }
        ChangeEvent::create([
            'change_request_id' => $change->id, 'event_type' => 'conflict',
            'description' => count($conflicts).' scheduling conflict(s): '.collect($conflicts)->pluck('change_code')->implode(', ').'.',
            'actor_id' => $this->p('head_it')->id, 'payload' => ['conflicts' => $conflicts], 'created_at' => $at,
        ]);
        // The earlier change learns of the later one too.
        foreach ($conflicts as $c) {
            if (! ChangeEvent::where('change_request_id', $c['id'])->where('event_type', 'conflict')->exists()) {
                ChangeEvent::create([
                    'change_request_id' => $c['id'], 'event_type' => 'conflict',
                    'description' => "Scheduling conflict: {$change->change_code} shares the window.",
                    'actor_id' => $this->p('head_it')->id, 'payload' => ['conflicts' => [['id' => $change->id, 'change_code' => $change->change_code]]], 'created_at' => $at,
                ]);
            }
        }
    }

    /** Persist the attributes and the timeline, in order, and set updated_at to the latest event. */
    private function flush(ChangeRequest $change, array $events, array|Carbon $attrs): void
    {
        $attrs = $attrs instanceof Carbon ? ['updated_at' => $attrs] : $attrs;
        if (mt_rand(1, 3) === 1 && count($events) > 4) {
            $events[] = [$events[3][0]->copy()->addHours(mt_rand(1, 30))->min(now()->subMinutes(30)), 'comment', ['Service desk briefed; knowledge article drafted.', 'Vendor confirmed engineer availability for the window.', 'Risk team asked for the back-out timing to be stated explicitly — added.', 'Linked incident updated with this change reference.'][mt_rand(0, 3)], $change->requester_id];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($events as [$at, $type, $description, $actorId]) {
            ChangeEvent::create(['change_request_id' => $change->id, 'event_type' => $type, 'description' => $description, 'actor_id' => $actorId, 'created_at' => $at]);
        }
        $attrs['updated_at'] = $attrs['updated_at'] ?? collect($events)->max(fn ($e) => $e[0]);
        ChangeRequest::withoutGlobalScopes()->where('id', $change->id)->update($attrs);
        $change->refresh();
    }

    /* ------------------------------- meetings ------------------------------- */

    private function meetings(Collection $changes): void
    {
        $lastThursday = now()->previous(Carbon::THURSDAY)->setTime(10, 0);
        $nextThursday = now()->next(Carbon::THURSDAY)->setTime(10, 0);
        $chair = $this->p('ciso');

        $held = ChangeCabMeeting::withoutGlobalScopes()->create([
            'organization_id' => $this->orgId, 'board_id' => $this->cab->id, 'title' => 'CAB — '.$lastThursday->format('j M Y'),
            'scheduled_at' => $lastThursday, 'status' => 'held', 'chaired_by_id' => $chair->id,
            'minutes' => "Present: chair, IT Operations, Compliance, Risk, Applications, Infrastructure, Business; Internal Audit in attendance.\n\nAll scheduled high-risk changes for the coming fortnight reviewed. The core switch line-card replacement and the firewall clean-up share a window; IT Operations to sequence them with a 60-minute gap. Oracle RU upgrade deferred pending a full test report from the DBA team. Month-end freeze override for the fee-schedule change noted and accepted on regulatory grounds.",
            'created_at' => $lastThursday->copy()->subDays(5), 'updated_at' => $lastThursday->copy()->addHours(2),
        ]);
        $heldItems = $changes->filter(fn (ChangeRequest $c) => $c->cab_required && in_array($c->status, ['scheduled', 'approved', 'review', 'closed', 'implementing'], true))
            ->sortByDesc('created_at')->take(4)->values();
        $seq = 0;
        foreach ($heldItems as $c) {
            $held->items()->create(['change_request_id' => $c->id, 'sequence' => ++$seq, 'decision' => 'approved', 'notes' => 'Approved as presented.']);
        }
        $deferred = $changes->first(fn (ChangeRequest $c) => $c->status === 'in_approval' && str_contains($c->title, 'Oracle'));
        if ($deferred) {
            $held->items()->create(['change_request_id' => $deferred->id, 'sequence' => ++$seq, 'decision' => 'more_info', 'notes' => 'Full regression test report requested from the DBA team before a vote.']);
        }

        $upcoming = ChangeCabMeeting::withoutGlobalScopes()->create([
            'organization_id' => $this->orgId, 'board_id' => $this->cab->id, 'title' => 'CAB — '.$nextThursday->format('j M Y'),
            'scheduled_at' => $nextThursday, 'status' => 'scheduled', 'chaired_by_id' => $chair->id,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        $seq = 0;
        foreach ($changes->filter(fn (ChangeRequest $c) => $c->status === 'in_approval' && $c->cab_required && $c->change_type === 'normal') as $c) {
            $upcoming->items()->create(['change_request_id' => $c->id, 'sequence' => ++$seq, 'decision' => 'pending']);
        }
    }

    /* --------------------------------- copy --------------------------------- */

    private function pick(array $keys, string $category): User
    {
        $preferred = match ($category) {
            'network' => 'network', 'core_banking' => 'app_owner', 'security' => 'soc', 'application' => 'digital', 'database' => 'sysadmin', 'data' => 'treasury', default => null,
        };
        $key = $preferred && mt_rand(0, 2) ? $preferred : $keys[mt_rand(0, count($keys) - 1)];
        $user = $this->p($key);

        return $user->id === $this->p('ciso')->id || $user->id === $this->p('head_it')->id ? $this->p('sysadmin') : $user;
    }

    private function pickOwner(string $category, User $requester): User
    {
        $candidates = match ($category) {
            'core_banking', 'database' => ['app_owner', 'flexcube'],
            'network', 'infrastructure' => ['network', 'sysadmin'],
            'application' => ['digital', 'app_owner'],
            'data' => ['treasury', 'dpco'],
            'security' => ['soc', 'incident'],
            default => ['digital', 'treasury'],
        };
        foreach ($candidates as $k) {
            if ($this->p($k)->id !== $requester->id && $this->p($k)->id !== $this->p('ciso')->id && $this->p($k)->id !== $this->p('head_it')->id) {
                return $this->p($k);
            }
        }

        return $this->p('treasury')->id !== $requester->id ? $this->p('treasury') : $this->p('digital');
    }

    private function pickImplementer(string $category, User $requester, User $owner): User
    {
        $candidates = match ($category) {
            'network' => ['network', 'sysadmin'],
            'core_banking', 'database', 'application' => ['sysadmin', 'flexcube', 'app_owner'],
            'security' => ['soc', 'incident', 'sysadmin'],
            default => ['sysadmin', 'network', 'incident'],
        };
        foreach ($candidates as $k) {
            $u = $this->p($k);
            if (! in_array($u->id, [$requester->id, $owner->id, $this->p('ciso')->id, $this->p('head_it')->id], true)) {
                return $u;
            }
        }

        return $this->p('incident');
    }

    private function description(string $title, string $category, string $type): string
    {
        $lead = match ($type) {
            'emergency' => 'Emergency change raised to restore or protect service; the fix cannot wait for the next CAB.',
            'standard' => 'Standard change raised from a pre-approved template; the procedure and back-out are those of the template.',
            default => 'Normal change assessed for risk and routed for approval before implementation.',
        };

        return "{$lead} {$title}. Scope: ".str_replace('_', ' ', $category).' estate as listed under affected assets and services.';
    }

    private function justification(string $source, string $type): string
    {
        return match ($source) {
            'incident' => 'Corrective action from a live incident; the service is degraded until this change is implemented.',
            'vulnerability' => 'Closes an exploitable vulnerability rated high or above by the scanner and confirmed by the SOC.',
            'risk' => 'Treatment action from the risk register to bring residual exposure within appetite.',
            'issue' => 'Remediates an open issue in the Issues & Remediation register before its SLA expires.',
            'audit' => 'Management action agreed in response to an audit recommendation.',
            'regulatory' => 'Required to comply with a regulatory directive by its effective date.',
            'project' => 'Planned delivery milestone of an approved project.',
            default => 'Operational improvement requested by the service owner.',
        };
    }

    private function impactAssessment(array $services, bool $outage, int $durationH): string
    {
        return 'Affects '.implode(', ', $services).'. '.($outage
            ? "A service interruption of up to {$durationH} hours is expected; the window is placed outside the processing peak and customers are notified in advance."
            : 'No customer-facing outage expected; brief session resets possible during the switch-over.').' Security impact analysis completed; no change to the data classification or the control baseline.';
    }

    private function plan(string $category): string
    {
        return match ($category) {
            'network' => '1. Export the running configuration. 2. Peer-review the staged change. 3. Apply in the maintenance window. 4. Verify reachability from the test matrix. 5. Hand over to the NOC.',
            'core_banking', 'database' => '1. Take a cold backup and confirm restore point. 2. Freeze batch. 3. Apply the change in the DR environment first. 4. Apply to production. 5. Run the smoke test pack and release batch.',
            'security' => '1. Pilot on the security team devices. 2. Apply to the target scope. 3. Confirm SIEM telemetry and no blocked business flows. 4. Close the pilot.',
            default => '1. Confirm pre-requisites and backups. 2. Implement per runbook. 3. Validate with the service owner. 4. Update the CMDB.',
        };
    }

    private function backout(string $category): string
    {
        return match ($category) {
            'network' => 'Reload the exported configuration; verify reachability; notify the NOC. Estimated 20 minutes.',
            'core_banking', 'database' => 'Restore from the cold backup and replay the archived logs to the freeze point. Estimated 90 minutes.',
            default => 'Revert to the previous version / snapshot per the runbook and re-run validation. Estimated 30 minutes.',
        };
    }

    private function testPlan(string $category): string
    {
        return match ($category) {
            'core_banking', 'database' => 'Regression pack executed in UAT with sign-off from Operations; EOD batch run in DR before production.',
            'network' => 'Change validated in the lab topology; reachability matrix executed post-change.',
            default => 'Functional checks against the acceptance checklist agreed with the service owner.',
        };
    }

    private function decisionNote(string $key): string
    {
        return match ($key) {
            'technical_review' => 'Technically sound; back-out time is realistic.',
            'security_review' => 'No adverse security impact; logging remains in place.',
            'business_approval' => 'Business impact acceptable for the proposed window.',
            'change_manager' => 'Authorised. Proceed in the approved window.',
            default => 'Approved.',
        };
    }

    private function pirSummary(?string $pir): string
    {
        return match ($pir) {
            'successful_with_issues' => 'Implemented within the window but the HA failover test took longer than planned; the runbook timing has been corrected.',
            'failed' => 'The upgrade did not complete and service ran on the standby for 6 hours. Lesson: require a vendor pre-check of the hardware revision before firmware upgrades on the core switch.',
            'backed_out' => 'Backed out cleanly within the back-out time. Lesson: schedule dependency on the settlement cut-off was missed in the impact assessment; added to the checklist.',
            default => 'Implemented as planned, within the window, no incidents raised. No lessons beyond keeping the communication cadence.',
        };
    }
}
