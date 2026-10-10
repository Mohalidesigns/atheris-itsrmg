<?php

namespace Database\Seeders;

use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\IncidentNotification;
use App\Models\IncidentResponseProcedure;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\SecurityAlert;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\VulnerabilityTicket;
use App\Services\SecurityOps\NotificationDrafter;
use App\Services\SecurityOps\SiemIngestService;
use App\Services\SecurityOps\VulnerabilityPrioritiser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Security Operations demo estate (ATH-VAL-005), per bank. Runs after the bank seeders and
 * DemoCrossLinkSeeder, and before RegulatoryIntelligenceDemoSeeder so Returns Centre runs count
 * breaches by their real discovery dates.
 *
 * The bank seeders generate incidents, breaches and alerts at random; this seeder makes them
 * coherent (deterministically, via mt_srand):
 * - incident response timestamps follow the status (no "responded" before "detected", no
 *   closed_at on an open incident) and incidents follow a matching response procedure;
 * - each data breach is tied to a breach incident, discovered when that incident was detected, with
 *   its NDPA 72-hour deadline computed from discovery — some notified in time, some late, two
 *   still running;
 * - alerts get real titles, and a share are linked to incidents;
 * - SIEM connectors per bank, with signals that raised alerts and some still untriaged;
 * - regulatory notifications across the lifecycle (prepared by the incident responder so the
 *   bank admin can approve), including one overdue;
 * - remediation tickets on the highest-priority findings, with SLAs from the bank's policy.
 */
class SecurityOperationsDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['admin' => 'admin@kanoheritage.ng', 'responder' => 'incident@kanoheritage.ng', 'seed' => 5102],
        'first-bank-nigeria' => ['admin' => 'ciso@firstbanknigeria.ng', 'responder' => 'incident@firstbanknigeria.ng', 'seed' => 5103],
    ];

    private const ALERT_TITLES = [
        ['Impossible travel: staff sign-in from Lagos then Accra within 20 minutes', 'Sentinel', 'high'],
        ['Brute force against Internet Banking admin portal', 'Splunk', 'high'],
        ['Mimikatz-like LSASS access on branch workstation', 'Defender', 'critical'],
        ['Outbound beaconing to known C2 infrastructure', 'Sentinel', 'critical'],
        ['Mass mailbox forwarding rule created', 'Defender', 'high'],
        ['Unusual volume of NIP transfers from a single agent', 'Splunk', 'high'],
        ['DNS tunnelling pattern from core-banking subnet', 'Wazuh', 'high'],
        ['Disabled EDR sensor on domain controller', 'Defender', 'critical'],
        ['Phishing URL clicked by 14 users', 'Defender', 'medium'],
        ['New local admin account on ATM switch server', 'Wazuh', 'high'],
        ['TLS certificate expiring in 7 days on USSD gateway', 'Wazuh', 'low'],
        ['Port scan from internal host to PCI segment', 'Splunk', 'medium'],
        ['ngCERT IOC match on firewall egress logs', 'ngCERT', 'high'],
        ['Privileged session outside change window (CyberArk)', 'Splunk', 'medium'],
        ['Large export from customer data warehouse', 'Sentinel', 'high'],
        ['Failed MFA push flood against treasury dealer', 'Sentinel', 'medium'],
    ];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $admin = User::where('email', $bank['admin'])->first();
            if (! $org || ! $admin) {
                continue;
            }
            $responder = User::where('email', $bank['responder'])->first() ?? $admin;

            Auth::setUser($admin);
            mt_srand($bank['seed']);

            $this->slaPolicy($org->id);
            $this->findings($org->id);
            $this->incidents($org->id);
            $this->breaches($org->id);
            $this->alerts($org->id);
            $this->siem($org->id);
            $this->notifications($org->id, $responder, $admin);
            $this->tickets($org->id);
        }

        mt_srand();
        Auth::forgetUser();
    }

    private function slaPolicy(int $orgId): void
    {
        foreach (VulnerabilityPrioritiser::DEFAULT_SLA_HOURS as $severity => $hours) {
            if ($severity === 'info') {
                continue;
            }
            DB::table('vulnerability_sla_policies')->updateOrInsert(
                ['organization_id' => $orgId, 'severity' => $severity],
                ['hours_to_remediate' => $hours, 'escalation_rules' => json_encode(['chain' => ['owner', 'CISO', 'CRO']]), 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    /**
     * Outstanding findings were discovered recently enough for the SLA picture to be mixed: most
     * criticals overdue, highs split, mediums and lows mostly within SLA. Due dates follow the
     * policy (KEV-listed CVEs take the critical SLA).
     */
    private function findings(int $orgId): void
    {
        $prioritiser = app(VulnerabilityPrioritiser::class);
        foreach (Vulnerability::outstanding()->orderBy('id')->get() as $v) {
            $discovered = now()->subDays(mt_rand(1, 40))->subHours(mt_rand(0, 23));
            $v->forceFill([
                'discovered_at' => $discovered,
                'due_date' => $prioritiser->dueDateFor($v->severity, $discovered, $orgId, $prioritiser->isKev($v->cve_id))->toDateString(),
            ])->saveQuietly();
        }
    }

    /** Response timestamps consistent with status, and a procedure where one covers the type. */
    private function incidents(int $orgId): void
    {
        $order = array_flip(Incident::STATUSES);
        $procedures = IncidentResponseProcedure::where('is_active', true)->get();

        foreach (Incident::orderBy('id')->get() as $incident) {
            $status = $incident->status;
            $detected = Carbon::parse($incident->detected_at ?? now()->subDays(mt_rand(5, 80)));
            $reached = fn (string $stage) => ($order[$status] ?? 0) >= ($order[$stage] ?? 99);

            $responded = $reached('triaged') ? $detected->copy()->addMinutes(mt_rand(15, 360)) : null;
            $contained = $reached('containing') ? ($responded ?? $detected)->copy()->addHours(mt_rand(2, 48)) : null;
            $resolved = $reached('resolved') || $status === 'closed' ? ($contained ?? $detected)->copy()->addHours(mt_rand(6, 120)) : null;
            $closed = $status === 'closed' ? ($resolved ?? $detected)->copy()->addDays(mt_rand(1, 10)) : null;
            // Nothing in the future.
            foreach (['responded', 'contained', 'resolved', 'closed'] as $v) {
                if ($$v && $$v->isFuture()) {
                    $$v = now()->subMinutes(mt_rand(5, 120));
                }
            }

            $procedure = $procedures->first(fn ($p) => in_array($incident->type, $p->incident_types ?? [], true));

            $incident->forceFill([
                'detected_at' => $detected,
                'responded_at' => $responded,
                'contained_at' => $contained,
                'resolved_at' => $resolved,
                'closed_at' => $closed,
                'root_cause' => $closed || $resolved ? $incident->root_cause : null,
                'response_procedure_id' => $procedure && mt_rand(0, 3) > 0 ? $procedure->id : null,
                'affected_systems' => $incident->affected_systems ?: [['Internet Banking', 'Active Directory', 'Finacle core', 'Email gateway', 'ATM switch'][mt_rand(0, 4)]],
            ])->saveQuietly();
        }
    }

    /**
     * Each breach is tied to a breach incident and discovered when it was detected. The NDPA clock
     * runs 72h from discovery. The two newest are re-dated so their clocks are still running.
     */
    private function breaches(int $orgId): void
    {
        $breaches = DataBreach::orderBy('id')->get()->values();
        // One incident per breach: the flagged breach incidents first, then incidents whose type
        // plausibly involves personal data. Breaches beyond that stand alone (no incident).
        $breachIncidents = Incident::where('is_data_breach', true)->orderBy('detected_at')->get()
            ->concat(Incident::where('is_data_breach', false)->whereIn('type', ['data_breach', 'unauthorized_access', 'insider_threat'])->orderBy('detected_at')->get())
            ->take($breaches->count())->values();
        Incident::whereIn('id', $breachIncidents->pluck('id'))->update(['is_data_breach' => true]);
        $titles = [
            'Customer PII exposed via misconfigured storage bucket', 'BVN and phone numbers in exfiltrated CSV',
            'Account statements emailed to the wrong customers', 'Card data visible in application logs',
            'KYC documents accessible without authentication', 'Insider copied customer list to USB',
            'Third-party call centre credentials abused', 'Loan applicants\' data on unsecured FTP',
        ];
        $hours = (int) config('secops.notification_deadline_hours.NDPC', 72);

        foreach ($breaches as $i => $breach) {
            $incident = $breachIncidents[$i] ?? null;
            $running = $i >= $breaches->count() - 2; // the newest two: clock still running
            $discovered = $running
                ? now()->subHours(mt_rand(6, 40))
                : Carbon::parse($incident?->detected_at ?? now()->subDays(mt_rand(10, 120)))->addHours(mt_rand(0, 12));
            if ($discovered->isFuture()) {
                $discovered = now()->subHours(6);
            }
            $deadline = $discovered->copy()->addHours($hours);

            // Older breaches: mostly notified in time, some late. Running clocks: not yet notified.
            $notified = $running ? null : match (true) {
                $i % 5 === 3 => $deadline->copy()->addHours(mt_rand(6, 48)),      // late
                $i % 5 === 4 => null,                                           // missed entirely
                default => $discovered->copy()->addHours(mt_rand(12, 66)),      // in time
            };
            if ($notified && $notified->isFuture()) {
                $notified = now()->subHour();
            }

            $breach->forceFill([
                'incident_id' => $incident?->id,
                'title' => $titles[$i % count($titles)],
                'description' => 'Personal data breach under NDPA 2023. '.($incident ? "Arising from {$incident->incident_id_code}." : ''),
                'discovered_at' => $discovered,
                'ndpa_notification_required' => true,
                'ndpa_notification_deadline' => $deadline,
                'ndpa_notified_at' => $notified,
                'regulatory_body_notified' => (bool) $notified,
                'individuals_notified' => (bool) $notified && $i % 2 === 0,
                'individuals_notified_at' => $notified && $i % 2 === 0 ? $notified->copy()->addDay()->min(now()) : null,
                'status' => $running ? ['identified', 'investigating'][$i % 2] : ($notified ? ['notified', 'resolved', 'closed'][$i % 3] : 'contained'),
                'created_at' => $discovered,
            ])->saveQuietly();
        }
    }

    private function alerts(int $orgId): void
    {
        $active = Incident::whereIn('status', Incident::ACTIVE_STATUSES)->pluck('id')->values();

        foreach (SecurityAlert::orderBy('id')->get()->values() as $i => $alert) {
            [$title, $source, $severity] = self::ALERT_TITLES[$i % count(self::ALERT_TITLES)];
            $status = $alert->status;
            $link = in_array($status, ['investigating', 'resolved'], true) && $active->isNotEmpty() && $i % 2 === 0;
            $received = Carbon::parse($alert->received_at ?? now()->subHours(mt_rand(1, 700)));

            $alert->forceFill([
                'title' => $title,
                'source' => $source,
                'severity' => $severity,
                'description' => "{$source} detection. Analyst triage notes are kept on the linked incident.",
                'incident_id' => $link ? $active[$i % $active->count()] : null,
                'acknowledged_at' => $status === 'new' ? null : $received->copy()->addMinutes(mt_rand(5, 240))->min(now()),
                'acknowledged_by' => $status === 'new' ? null : $alert->acknowledged_by,
            ])->saveQuietly();
        }
    }

    private function siem(int $orgId): void
    {
        if (SiemIntegration::exists()) {
            return;
        }
        $connectors = [
            ['sentinel', 'active', 8, ['workspace' => 'soc-law-prod']],
            ['splunk_es', 'active', 21, ['host' => 'splunk.soc.internal']],
            ['wazuh', 'active', 3, ['manager' => 'wazuh-mgr-01']],
            ['qradar', 'degraded', 190, ['console' => 'qradar.dr.internal', 'note' => 'DR console unreachable since the last firewall change']],
        ];
        foreach ($connectors as [$provider, $status, $minsAgo, $config]) {
            SiemIntegration::create([
                'organization_id' => $orgId,
                'provider' => $provider,
                'config' => $config,
                'webhook_secret' => bin2hex(random_bytes(24)),
                'status' => $status,
                'last_signal_at' => now()->subMinutes($minsAgo),
            ]);
        }

        $ingest = app(SiemIngestService::class);
        $titles = self::ALERT_TITLES;
        $alerts = SecurityAlert::where('status', '!=', 'new')->orderByDesc('received_at')->take(20)->get()->values();
        for ($i = 0; $i < 24; $i++) {
            [$title, , $severity] = $titles[$i % count($titles)];
            $provider = ['sentinel', 'splunk_es', 'wazuh'][$i % 3];
            $signal = SiemSignal::create([
                'organization_id' => $orgId,
                'provider' => $provider,
                'external_id' => strtoupper(substr($provider, 0, 3)).'-'.(48210 + $i * 7),
                'severity' => $severity,
                'title' => $title,
                'payload' => $ingest->redact([
                    'src_ip' => '10.20.'.mt_rand(1, 40).'.'.mt_rand(2, 250),
                    'user' => ['svc_finacle', 'a.okafor', 'treasury.dealer3', 'branch-ws-114'][$i % 4],
                    'phone' => '0803'.mt_rand(1000000, 9999999),
                    'rule' => 'R-'.(300 + $i),
                    'tactic' => ['Credential Access', 'Command and Control', 'Exfiltration', 'Initial Access'][$i % 4],
                ]),
                'received_at' => now()->subMinutes(mt_rand(10, 60 * 30)),
            ]);
            // Older signals were triaged into the existing alerts; the newest high/critical ones are not yet.
            if ($i >= 4 && isset($alerts[$i - 4])) {
                $signal->forceFill(['security_alert_id' => $alerts[$i - 4]->id, 'incident_id' => $alerts[$i - 4]->incident_id])->save();
            }
        }
    }

    private function notifications(int $orgId, User $responder, User $admin): void
    {
        if (IncidentNotification::exists()) {
            return;
        }
        $drafter = app(NotificationDrafter::class);
        $templates = NotificationTemplate::get()->keyBy('template_key');
        if (! $templates->has('cbn_24h')) {
            return;
        }

        $serious = Incident::whereIn('severity', ['critical', 'high'])->orderByDesc('detected_at')->get()->values();
        $breachIncidents = Incident::where('is_data_breach', true)->has('breach')->orderByDesc('detected_at')->get()->values();

        // [incident, template, final state, submitted late?]
        $plan = [];
        foreach ($serious->take(5) as $i => $incident) {
            $plan[] = [$incident, 'cbn_24h', ['submitted', 'submitted', 'approved', 'in_review', 'draft'][$i], $i === 1];
        }
        foreach ($breachIncidents->take(2) as $i => $incident) {
            $plan[] = [$incident, 'ndpc_72h', ['submitted', 'in_review'][$i], false];
        }
        if ($fraud = Incident::where(fn ($q) => $q->where('title', 'like', '%wire%')->orWhere('title', 'like', '%cash-out%'))->first()) {
            $plan[] = [$fraud, 'nfiu_sar', 'draft', false];
        }

        foreach ($plan as [$incident, $key, $state, $late]) {
            if (! $templates->has($key) || IncidentNotification::where('incident_id', $incident->id)->where('template_key', $key)->exists()) {
                continue;
            }
            // Live notifications sit on fresh incidents so their clocks are still running; the CBN
            // draft is left on an older incident as the overdue example.
            if (in_array($state, ['in_review', 'approved'], true) || ($state === 'draft' && $key !== 'cbn_24h')) {
                $this->redetect($incident, now()->subHours(mt_rand(3, 14)));
            }
            $n = $drafter->draft($incident, $templates[$key], $responder->id);
            $deadline = $n->deadline_at;
            $created = Carbon::parse($incident->detected_at)->addHours(2)->min(now());

            $fill = ['created_at' => $created];
            if (in_array($state, ['in_review', 'approved', 'submitted'], true)) {
                $fill['delivery_status'] = 'in_review';
            }
            if (in_array($state, ['approved', 'submitted'], true)) {
                $signed = $created->copy()->addHours(mt_rand(3, 12))->min(now());
                $fill = ['delivery_status' => 'approved', 'approved_by_id' => $admin->id, 'signed_at' => $signed] + $fill;
            }
            if ($state === 'submitted') {
                $at = $late ? $deadline->copy()->addHours(mt_rand(4, 30)) : $deadline->copy()->subHours(mt_rand(2, 10));
                $at = $at->min(now());
                $ref = ($key === 'ndpc_72h' ? 'NDPC/BR/' : 'CBN/BSD/ITS/').now()->year.'/'.mt_rand(1000, 9999);
                $fill = ['delivery_status' => 'submitted'] + $fill + [
                    'submitted_at' => $at, 'submitted_by_id' => $responder->id, 'submission_reference' => $ref,
                    'delivery_receipt' => ['reference' => $ref, 'recorded_at' => $at->toIso8601String(), 'by' => $responder->name],
                ];
                IncidentEvent::create([
                    'incident_id' => $incident->id, 'event_type' => 'communication',
                    'description' => "{$n->regulator_code} notification submitted (ref {$ref})".($late ? ' after the deadline.' : ' within the deadline.'),
                    'performed_by' => $responder->id, 'occurred_at' => $at,
                ]);
            }
            $n->forceFill($fill)->save();
        }
    }

    /** Move an incident's detection, shifting its whole response timeline with it. */
    private function redetect(Incident $incident, Carbon $detected): void
    {
        $delta = Carbon::parse($incident->detected_at)->diffInSeconds($detected, false);
        $fill = ['detected_at' => $detected];
        foreach (['responded_at', 'contained_at', 'resolved_at', 'closed_at'] as $field) {
            if ($incident->{$field}) {
                $fill[$field] = Carbon::parse($incident->{$field})->addSeconds($delta)->min(now());
            }
        }
        $incident->forceFill($fill)->saveQuietly();
        if ($incident->breach) {
            $incident->breach->forceFill([
                'discovered_at' => $detected,
                'ndpa_notification_deadline' => $detected->copy()->addHours((int) config('secops.notification_deadline_hours.NDPC', 72)),
                'ndpa_notified_at' => null,
                'regulatory_body_notified' => false,
                'status' => 'investigating',
            ])->saveQuietly();
        }
    }

    /** Tickets on the highest-priority outstanding findings, priority and SLA from the policy. */
    private function tickets(int $orgId): void
    {
        $prioritiser = app(VulnerabilityPrioritiser::class);
        $sla = $prioritiser->slaHours($orgId);
        $ranked = $prioritiser->outstanding()->take(14)->values();

        VulnerabilityTicket::query()->delete();
        $priority = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4, 'info' => 5];

        foreach ($ranked as $i => $row) {
            $vuln = Vulnerability::find($row['id']);
            $hours = $sla[$row['kev'] ? 'critical' : $vuln->severity] ?? 720;
            $opened = Carbon::parse($vuln->discovered_at ?? now()->subDays(10))->addDay()->min(now()->subHours(2));
            $status = $i < 10 ? ['open', 'in_progress', 'in_progress', 'resolved'][$i % 4] : null;
            if (! $status) {
                continue; // leave a few P1/P2 findings without a ticket: the prioritiser flags them
            }
            $ticket = VulnerabilityTicket::create([
                'organization_id' => $orgId,
                'vulnerability_id' => $vuln->id,
                'ticket_code' => VulnerabilityTicket::generateNextCode($orgId),
                'title' => 'Remediate '.($vuln->cve_id ?: $vuln->vuln_id_code).': '.$vuln->title,
                'description' => "Patch or mitigate {$vuln->vuln_id_code} on the affected assets and attach evidence of the fix.",
                'status' => $status,
                'priority' => $row['kev'] ? 1 : ($priority[$vuln->severity] ?? 3),
                'assigned_to' => $vuln->assigned_to,
                'sla_hours' => $hours,
                'sla_due_at' => $opened->copy()->addHours($hours),
                'resolved_at' => $status === 'resolved' ? now()->subDays(mt_rand(1, 5)) : null,
                'resolution_notes' => $status === 'resolved' ? 'Vendor fix applied; rescan clean. Awaiting verification before the finding is closed.' : null,
            ]);
            $ticket->forceFill(['created_at' => $opened])->saveQuietly();
            if ($vuln->status === 'open') {
                $vuln->forceFill(['status' => 'in_progress'])->saveQuietly();
            }
        }
    }
}
