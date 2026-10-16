<?php

namespace Database\Seeders;

use App\Models\Capa\CapaAction;
use App\Models\Capa\ContainmentAction;
use App\Models\Capa\RootCause;
use App\Models\Change\ChangeRequest;
use App\Models\Incident;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Integrated CAPA demo data (IT-GOV-CAPA-001). Runs last in DatabaseSeeder, after every seeder that raises issues.
 *
 * Kano Heritage Bank's register is the CAPA register: twelve of its seeded issues are moved onto the
 * six-stage workflow so every stage, all three framework paths and the §6 KPIs have data —
 * - identification (one deliberately missing its CBN cross-reference), containment, RCA (a complete
 *   5-whys chain and a draft fishbone), an action plan awaiting IT Governance sign-off;
 * - implementation with actions routed through Change Management, one of them overdue;
 * - one CAPA still in its settling period and one whose verification is due;
 * - two closed CAPAs verified by Internal Audit, one of which has since recurred (recurrence KPI);
 * - a customer-impacting incident that opens its own CAPA through the CBN CPR §6.0 auto-trigger.
 * Picks are by title (the issue seeders are deterministic), timestamps are backdated from each issue's
 * raised date, and every stage leaves a timeline entry.
 */
class CapaDemoSeeder extends Seeder
{
    private const BANK = 'kano-heritage-bank';

    private const VERIFIER = 'Head of Internal Audit';

    private const GOVERNANCE = 'Head of GRC';

    /** Each pick: title prefix => [stage, framework, classification, reference, CBN provisions, days ago raised (null = keep)]. */
    private const PICKS = [
        'CCM failure: PII masking in non-prod' => ['identification', 'iso27001', 'nonconformity', 'iso27001_10_2', [], null],
        'KRI red: Overdue critical patches' => ['identification', 'cobit2019', 'performance_variance', 'cobit_mea01', ['cbn_itsb_3_5', 'cbn_rbcsf_3_1_5'], null],
        'Core banking findings: NIBSS NIP Gateway' => ['containment', 'itilv4', 'problem_statement', 'itil_problem_management', ['cbn_itsb_4_2', 'cbn_cpr_6_0'], 6],
        'Unauthorised drift: Firewall rule added' => ['rca', 'iso27001', 'nonconformity', 'iso27001_10_2', ['cbn_rbcsf_3_1_5'], 9],
        'KRI red: Major outages (30d)' => ['rca', 'cobit2019', 'performance_variance', 'cobit_mea01', ['cbn_itsb_3_5'], 8],
        'Core banking findings: Flexcube Legacy' => ['action_planning', 'itilv4', 'problem_statement', 'itil_problem_management', ['cbn_itsb_3_5', 'cbn_itsb_4_2'], 15],
        'Privileged AD accounts missing MFA' => ['implementation', 'iso27001', 'nonconformity', 'iso27001_10_2', ['cbn_rbcsf_3_1_5'], 28],
        'Reduce exposure on KHB-RSK-002' => ['implementation', 'cobit2019', 'risk_event', 'cobit_apo12', ['cbn_itsb_4_2', 'cbn_cpr_6_0'], 24],
        'Backup integrity test failure' => ['verification', 'itilv4', 'problem_statement', 'itil_problem_management', ['cbn_itsb_3_5'], 34],
        'Corrective action from KHB-INC-003' => ['verification', 'iso27001', 'nonconformity', 'iso27001_10_2', ['cbn_rbcsf_3_1_5'], 70],
        'Outstanding critical CVE on perimeter' => ['closed', 'iso27001', 'nonconformity', 'iso27001_10_2', ['cbn_rbcsf_3_1_5', 'cbn_itsb_3_5'], 300],
        'Corrective action from KHB-INC-004' => ['closed', 'itilv4', 'problem_statement', 'itil_problem_management', ['cbn_itsb_4_2', 'cbn_cpr_6_0'], 200],
    ];

    /** Days after raising that each stage finished, for CAPAs that got past it. */
    private const MILESTONES = ['containment' => 1, 'rca' => 4, 'action_planning' => 8];

    /** Per pick: containment, 5 whys, fishbone, summary, CBN correlation, actions [title, change code, status, due offset days]. */
    private const CONTENT = [
        'CCM failure: PII masking in non-prod' => [],
        'KRI red: Overdue critical patches' => [],
        'Core banking findings: NIBSS NIP Gateway' => [
            'containment' => [['NIP gateway failed over to the secondary node; settlement queue drained manually', 'Customer debits held pending reconciliation']],
        ],
        'Unauthorised drift: Firewall rule added' => [
            'containment' => [['Rule any → DMZ 3389 removed and the VPN concentrator config restored from baseline', null], ['RDP sessions from the DMZ reviewed for the exposure window', 'No successful logins found']],
            'whys' => ['An any → DMZ 3389 rule appeared on the VPN concentrator', 'An engineer added it to support a vendor session', 'The emergency access request bypassed the change process', 'The VPN concentrator is not covered by the firewall change workflow', 'Firewall rule ownership for the VPN tier was never assigned'],
            'summary' => 'Firewall changes on the VPN tier sit outside the change and rule-review controls (ISO 27001 A.8.20 / A.8.32).',
        ],
        'KRI red: Major outages (30d)' => [
            'containment' => [['Major-incident bridge stood up for every P1; vendor escalation contacts confirmed', null]],
            'fishbone' => ['people' => ['On-call rota thin over weekends'], 'process' => ['Capacity reviews skipped since Q2'], 'technology' => ['Ageing SAN controller in Lagos DR', 'No automated failover test'], 'governance' => []],
        ],
        'Core banking findings: Flexcube Legacy' => [
            'containment' => [['End-of-day batch rerun with manual checkpointing; branch cut-off moved 30 minutes', null]],
            'whys' => ['Flexcube EOD batch overran into the business day', 'Interest accrual jobs ran serially', 'The scheduler profile was never tuned after the 2024 data growth', 'No capacity baseline exists for the legacy core'],
            'summary' => 'No capacity baseline or batch-window monitoring for the legacy core; growth went unnoticed until EOD overran.',
            'correlation' => 'Fixes the CBN IT Standards Blueprint §3.5 / §4.2 requirement for formal RCA of persistent core banking platform errors.',
            'actions' => [['Profile and parallelise the interest accrual jobs', 'KHB-CHG-0024', 'planned', 21], ['Add EOD batch-window KRI to the CCM engine', null, 'planned', 30]],
        ],
        'Privileged AD accounts missing MFA' => [
            'containment' => [['Interactive logon disabled for the 14 privileged accounts without MFA', 'Break-glass accounts kept in the PAM vault']],
            'whys' => ['14 privileged AD accounts could log on without MFA', 'They were created outside the PAM onboarding flow', 'Service owners raise admin accounts directly in AD', 'The joiner process does not cover privileged roles'],
            'summary' => 'Privileged accounts can be created outside PAM, so the MFA control never applies to them (ISO 27001 A.5.15 / A.8.5).',
            'correlation' => 'Fixes CBN Risk-Based Cyber Security Framework §3.1.5 remedial plan following the internal audit access finding.',
            'actions' => [['Enforce MFA on all privileged AD accounts', 'KHB-CHG-0006', 'done', 10], ['Route privileged account creation through PAM only', null, 'in_progress', -5], ['Add quarterly privileged access review to the CCM schedule', 'KHB-CHG-0028', 'planned', 20]],
        ],
        'Reduce exposure on KHB-RSK-002' => [
            'containment' => [['Manual NIP reconciliation every two hours until the root cause is fixed', null]],
            'fishbone' => ['people' => ['Reconciliation team not trained on the new NIBSS file format'], 'process' => ['No threshold for unmatched NIP items'], 'technology' => ['Switch-level limit misaligned with core limits'], 'governance' => ['Payments risk appetite not cascaded to IT metrics']],
            'summary' => 'Reconciliation metrics are not tied to the payments risk appetite, so variance grows without escalation (COBIT APO12).',
            'correlation' => 'Satisfies CBN Consumer Protection Regulations §6.0 by preventing recurrence of erroneous customer debits from NIP mismatches.',
            'actions' => [['Align switch-level NIP limits with core banking limits', 'KHB-CHG-0018', 'in_progress', 10], ['Set unmatched-item threshold and escalation KRI', null, 'planned', 20]],
        ],
        'Backup integrity test failure' => [
            'containment' => [['Finacle backup re-run to the secondary target and restore-tested', null]],
            'whys' => ['The Finacle backup failed its integrity test', 'The SAN controller in Lagos DR was throwing write errors', 'Controller alerts went to a retired mailbox', 'Monitoring targets were not reviewed after the DR migration'],
            'summary' => 'DR storage alerts were not routed to the operations queue after the DR migration (ITIL problem: monitoring baseline).',
            'correlation' => 'Fixes the CBN IT Standards Blueprint §3.5 requirement for RCA of persistent system downtime on the core banking platform.',
            'actions' => [['Replace the failing SAN controller in Lagos DR', 'KHB-CHG-0014', 'done', 12], ['Re-point storage alerting to the NOC queue', null, 'done', 14]],
            'implemented' => 10,
        ],
        'Corrective action from KHB-INC-003' => [
            'containment' => [['Affected laptop isolated and reimaged; IOC sweep across the fleet', 'No lateral movement found']],
            'whys' => ['Ransomware reached a staff laptop', 'The laptop missed two monthly patch cycles', 'It was off-network during the patch windows', 'Patch compliance reporting excludes devices offline for over 7 days'],
            'summary' => 'Endpoint patch compliance ignores long-offline devices, leaving a blind spot in the patch control (ISO 27001 A.8.8).',
            'correlation' => 'Fixes CBN Cyber Security Guideline §3.1.5 on patching latency after the security incident.',
            'actions' => [['Include offline devices in patch compliance reporting', 'KHB-CHG-0005', 'done', 20], ['Force patch-on-connect for devices offline over 7 days', null, 'done', 25]],
            'implemented' => 40,
        ],
        'Outstanding critical CVE on perimeter' => [
            'containment' => [['Virtual patch (IPS signature) applied on the perimeter firewall', null]],
            'whys' => ['A critical CVE stayed open on the perimeter firewall', 'The vendor fix needed a new major version', 'The appliance was end of support for that version', 'Hardware refresh was not in the budget cycle'],
            'summary' => 'Perimeter appliances had no lifecycle plan, so a critical fix could not be applied (ISO 27001 A.8.8).',
            'correlation' => 'Fixes CBN Risk-Based Cyber Security Framework §3.1.5 remedial action following the penetration testing exception.',
            'actions' => [['Replace the HQ perimeter firewall HA pair', 'KHB-CHG-0002', 'done', 18]],
            'implemented' => 280, 'closed' => 240,
        ],
        'Corrective action from KHB-INC-004' => [
            'containment' => [['NIP reconciliation run manually and customer accounts reversed', 'Customer advisory issued']],
            'whys' => ['NIP transactions failed to reconcile', 'The settlement file arrived late', 'The NIBSS file transfer job retried silently', 'Job failure alerts were not configured'],
            'summary' => 'The NIBSS settlement transfer had no failure alerting, so late files went unnoticed (ITIL problem: monitoring gap).',
            'correlation' => 'Satisfies CBN Consumer Protection Regulations §6.0 for the erroneous customer debits.',
            'actions' => [['Alert on NIBSS settlement transfer failure and lateness', null, 'done', 10]],
            'implemented' => 185, 'closed' => 150,
        ],
    ];

    public function run(): void
    {
        $org = Organization::where('slug', self::BANK)->first();
        if (! $org) {
            return;
        }
        // Tenant-scoped guard: a handful of hand-raised CAPAs must not stop the demo estate seeding.
        if (Issue::withoutGlobalScopes()->where('organization_id', $org->id)->whereNotNull('capa_framework')->count() >= 5) {
            return;
        }

        $users = User::where('organization_id', $org->id)->get();
        $verifier = $users->firstWhere('job_title', self::VERIFIER);
        $governance = $users->firstWhere('job_title', self::GOVERNANCE);
        $changes = ChangeRequest::withoutGlobalScopes()->where('organization_id', $org->id)->pluck('id', 'change_code');

        $seeded = [];
        foreach (self::PICKS as $prefix => [$stage, $framework, $classification, $reference, $provisions, $daysAgo]) {
            $issue = Issue::withoutGlobalScopes()->where('organization_id', $org->id)->whereNull('capa_framework')
                ->where('title', 'like', $prefix.'%')->orderBy('id')->first();
            if (! $issue) {
                continue;
            }
            $seeded[$prefix] = $this->seedCapa($issue, $stage, $framework, $classification, $reference, $provisions, $daysAgo,
                self::CONTENT[$prefix] ?? [], $verifier, $governance, $changes);
        }

        // The NIP gateway problem came back after the NIP reconciliation CAPA was closed (§6 recurrence rate).
        if (isset($seeded['Core banking findings: NIBSS NIP Gateway'], $seeded['Corrective action from KHB-INC-004'])) {
            $seeded['Core banking findings: NIBSS NIP Gateway']->update(['recurrence_of_id' => $seeded['Corrective action from KHB-INC-004']->id]);
        }

        // CBN CPR §6.0: a customer-impacting incident (SIM-swap fraud on five accounts) opens its own CAPA through the real auto-trigger listener.
        $incident = Incident::withoutGlobalScopes()->where('organization_id', $org->id)->where('incident_id_code', 'KHB-INC-010')->first();
        $incident?->update(['customer_impact' => true]);
    }

    private function seedCapa(Issue $issue, string $stage, string $framework, string $classification, string $reference, array $provisions,
        ?int $daysAgo, array $content, ?User $verifier, ?User $governance, $changes): Issue
    {
        $order = array_keys(config('capa.stage_status'));
        $reached = fn (string $s) => array_search($stage, $order, true) > array_search($s, $order, true);
        $raised = $daysAgo !== null ? now()->subDays($daysAgo)->setTime(9, 30) : $issue->created_at->copy();
        $at = fn (int $days) => $raised->copy()->addDays($days)->min(now());
        $owner = $issue->owner_id ? User::find($issue->owner_id) : null;
        $hours = (int) config("capa.sla_hours.{$issue->severity}", 720);

        $implemented = isset($content['implemented']) ? now()->subDays($content['implemented'])->setTime(16, 0) : null;
        $closed = isset($content['closed']) ? now()->subDays($content['closed'])->setTime(11, 0) : null;
        $settling = 30;
        $criteria = array_keys(config("capa.frameworks.{$framework}.verification_criteria"));

        $update = [
            'created_at' => $raised,
            'capa_framework' => $framework,
            'capa_classification' => $classification,
            'framework_reference' => $reference,
            'cbn_provisions' => $provisions,
            'cbn_correlation' => $content['correlation'] ?? null,
            'capa_stage' => $stage,
            'status' => config("capa.stage_status.{$stage}"),
            'blocked_reason' => null,
            'settling_days' => $settling,
            'stage_signoffs' => [],
            'sla_minutes' => $hours * 60,
            'sla_paused_minutes' => 0,
            'sla_paused_at' => null,
            'sla_due_at' => $raised->copy()->addHours($hours),
            'due_date' => $raised->copy()->addHours($hours)->toDateString(),
            'containment_completed_at' => $reached('containment') ? $at(self::MILESTONES['containment']) : null,
            'rca_completed_at' => $reached('rca') ? $at(self::MILESTONES['rca']) : null,
            'plan_approved_at' => $reached('action_planning') ? $at(self::MILESTONES['action_planning']) : null,
            'implemented_at' => $implemented,
            'remediated_at' => $implemented,
            'remediated_by_id' => $implemented ? $issue->owner_id : null,
            'verification_due_at' => $implemented?->copy()->addDays($settling),
            'verification_criteria' => null,
            'verified_at' => null,
            'verified_by_id' => null,
            'closed_at' => null,
            'root_cause' => $content['summary'] ?? $issue->root_cause,
        ];
        if ($reached('action_planning') && $governance) {
            $update['stage_signoffs'] = ['action_planning' => [
                'user_id' => $governance->id, 'name' => $governance->name, 'raci_role' => 'it_governance',
                'at' => $at(self::MILESTONES['action_planning'])->toIso8601String(), 'note' => 'Plan and CBN correlation approved.',
            ]];
        }
        if ($closed) {
            $update = array_merge($update, [
                'verification_criteria' => array_fill_keys($criteria, true),
                'verified_at' => $closed, 'verified_by_id' => $verifier?->id, 'closed_at' => $closed,
            ]);
        }
        // Backdating the raise moves the existing timeline with it, so "Issue raised" stays first.
        $shift = (int) $issue->created_at->diffInSeconds($raised, false);
        if ($shift !== 0) {
            DB::table('issue_events')->where('issue_id', $issue->id)->get(['id', 'created_at'])->each(fn ($e) => DB::table('issue_events')
                ->where('id', $e->id)->update(['created_at' => Carbon::parse($e->created_at)->addSeconds($shift)->min(now())]));
        }
        $issue->forceFill($update)->saveQuietly();

        foreach ($content['containment'] ?? [] as $i => [$action, $notes]) {
            ContainmentAction::withoutGlobalScopes()->create([
                'organization_id' => $issue->organization_id, 'issue_id' => $issue->id, 'action' => $action, 'notes' => $notes,
                'performed_by_id' => $issue->owner_id, 'performed_at' => $raised->copy()->addHours(3 + $i * 2)->min(now()),
            ]);
        }

        if (isset($content['whys']) || isset($content['fishbone'])) {
            $method = isset($content['whys'], $content['fishbone']) ? 'both' : (isset($content['whys']) ? 'five_whys' : 'fishbone');
            RootCause::withoutGlobalScopes()->create([
                'organization_id' => $issue->organization_id, 'issue_id' => $issue->id, 'method' => $method,
                'five_whys' => $content['whys'] ?? [],
                'fishbone' => $content['fishbone'] ?? array_fill_keys(RootCause::FISHBONE_CATEGORIES, []),
                'root_cause_summary' => $content['summary'] ?? null,
                'completed_by_id' => $issue->owner_id,
            ]);
        }

        foreach ($content['actions'] ?? [] as $i => [$title, $changeCode, $status, $dueOffset]) {
            $due = ($implemented ?? $at(self::MILESTONES['action_planning']))->copy()->addDays($dueOffset);
            if ($dueOffset < 0) {
                $due = now()->addDays($dueOffset); // deliberately overdue
            }
            $done = $status === 'done';
            CapaAction::withoutGlobalScopes()->create([
                'organization_id' => $issue->organization_id, 'issue_id' => $issue->id,
                'action_code' => $issue->issue_code.'-A'.($i + 1), 'title' => $title,
                'owner_id' => $issue->owner_id, 'due_date' => $due->toDateString(), 'status' => $status,
                'change_request_id' => $changeCode ? ($changes[$changeCode] ?? null) : null,
                'completed_at' => $done ? ($implemented ?? $due)->copy()->min(now()) : null,
            ]);
        }

        $this->timeline($issue, $stage, $raised, $at, $owner, $governance, $verifier, $implemented, $closed, $framework);

        return $issue;
    }

    private function timeline(Issue $issue, string $stage, Carbon $raised, callable $at, ?User $owner, ?User $governance, ?User $verifier,
        ?Carbon $implemented, ?Carbon $closed, string $framework): void
    {
        $label = config("capa.frameworks.{$framework}.label");
        $events = [[$raised->copy()->addMinutes(20), 'capa', "CAPA opened on the {$label} path.", $owner]];
        // Stage entered => [days after raising, timeline text].
        $steps = [
            'containment' => [0, 'Identification & classification complete; moved to containment & immediate mitigation.'],
            'rca' => [self::MILESTONES['containment'], 'Containment & immediate mitigation complete; moved to root cause analysis.'],
            'action_planning' => [self::MILESTONES['rca'], 'Root cause analysis complete; moved to action plan & CBN alignment.'],
        ];
        $order = array_keys(config('capa.stage_status'));
        foreach ($steps as $to => [$days, $text]) {
            if (array_search($stage, $order, true) >= array_search($to, $order, true)) {
                $events[] = [$at($days)->addHour(), 'capa_stage', $text, $owner];
            }
        }
        if (array_search($stage, $order, true) >= array_search('implementation', $order, true)) {
            $events[] = [$at(self::MILESTONES['action_planning']), 'capa_signoff', 'Action plan & CBN alignment signed off by IT Governance / GRC.', $governance];
            $events[] = [$at(self::MILESTONES['action_planning'])->addMinutes(30), 'capa_stage', 'Action plan approved; moved to implementation & deployment.', $owner];
        }
        if ($implemented) {
            $events[] = [$implemented, 'capa_stage', 'All corrective actions done; moved to independent effectiveness verification (30-day settling period).', $owner];
        }
        if ($closed) {
            $events[] = [$closed, 'capa_verification', "Effectiveness verified against the {$label} criteria; CAPA closed.", $verifier];
        }

        DB::table('issue_events')->insert(array_map(fn ($e) => [
            'issue_id' => $issue->id, 'actor_id' => $e[3]?->id, 'event_type' => $e[1], 'description' => $e[2],
            'payload' => json_encode(['seeded' => true]), 'created_at' => $e[0],
        ], $events));
    }
}
