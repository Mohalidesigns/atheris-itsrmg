<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\NotificationTemplate;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use App\Models\ThreatAdvisory;
use App\Models\Vulnerability;
use App\Services\SecurityOps\NotificationDrafter;
use App\Services\SecurityOps\SiemIngestService;
use App\Services\SecurityOps\VulnerabilityPrioritiser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Security Operations pages that were PlatformController stubs: the vulnerability prioritiser,
 * threat advisories, SIEM integrations (incl. the signed webhook ingest) and regulatory
 * notifications. The register-style pages (vulnerabilities, incidents, alerts...) keep their own
 * resource controllers.
 */
class SecurityOperationsController extends Controller
{
    /* ========================= Vulnerability prioritiser ========================= */

    public function prioritiser(Request $r, VulnerabilityPrioritiser $prioritiser)
    {
        $rows = $prioritiser->outstanding();
        $band = $r->query('band');
        $kevOnly = $r->boolean('kev');

        return Inertia::render('Vuln/Prioritiser', [
            'rows' => $rows
                ->when($band, fn ($c) => $c->where('band', $band))
                ->when($kevOnly, fn ($c) => $c->where('kev', true))
                ->values(),
            'stats' => [
                'outstanding' => $rows->count(),
                'p1' => $rows->where('band', 'P1')->count(),
                'kev' => $rows->where('kev', true)->count(),
                'sla_breached' => $rows->where('sla_breached', true)->count(),
                'no_ticket' => $rows->whereIn('band', ['P1', 'P2'])->where('open_tickets', 0)->count(),
            ],
            'slaHours' => $prioritiser->slaHours($r->user()->organization_id),
            'filters' => ['band' => $band, 'kev' => $kevOnly],
        ]);
    }

    /* ============================== Threat advisories ============================= */

    public function advisories(Request $r)
    {
        $advisories = ThreatAdvisory::orderByDesc('published_at')->take(100)->get();

        // "Am I affected?": match every advisory CVE against this bank's own findings and their assets.
        $cves = $advisories->pluck('cves')->flatten()->filter()->unique()->values();
        $findings = Vulnerability::whereIn('cve_id', $cves)
            ->with('assets:id,name,asset_id_code,criticality')
            ->get(['id', 'vuln_id_code', 'cve_id', 'title', 'severity', 'status'])
            ->groupBy('cve_id');

        $source = $r->query('source');

        return Inertia::render('Threats/Advisories', [
            'advisories' => $advisories
                ->when($source, fn ($c) => $c->where('source', $source))
                ->map(function (ThreatAdvisory $a) use ($findings) {
                    $matches = collect($a->cves ?? [])->flatMap(fn ($cve) => $findings->get($cve, collect()));

                    return [
                        'id' => $a->id,
                        'source' => $a->source,
                        'advisory_id' => $a->advisory_id,
                        'title' => $a->title,
                        'body' => $a->body,
                        'published_at' => $a->published_at?->toIso8601String(),
                        'cves' => $a->cves ?? [],
                        'iocs' => $a->iocs ?? [],
                        'affected' => [
                            'findings' => $matches->map(fn ($v) => [
                                'id' => $v->id, 'code' => $v->vuln_id_code, 'cve_id' => $v->cve_id,
                                'title' => $v->title, 'severity' => $v->severity, 'status' => $v->status,
                            ])->values(),
                            'open' => $matches->whereIn('status', Vulnerability::OPEN_STATUSES)->count(),
                            'assets' => $matches->flatMap->assets->unique('id')->map(fn ($a) => [
                                'id' => $a->id, 'name' => $a->name, 'code' => $a->asset_id_code, 'criticality' => $a->criticality,
                            ])->values(),
                        ],
                    ];
                })->values(),
            'sources' => $advisories->pluck('source')->unique()->values(),
            'filters' => ['source' => $source],
        ]);
    }

    /* ============================== SIEM integrations ============================= */

    public function siem(Request $r)
    {
        $integrations = SiemIntegration::orderBy('provider')->get()->map(fn (SiemIntegration $i) => [
            'id' => $i->id,
            'provider' => $i->provider,
            'label' => SiemIntegration::PROVIDERS[$i->provider] ?? Str::headline($i->provider),
            'status' => $i->status,
            'last_signal_at' => $i->last_signal_at?->toIso8601String(),
            'has_secret' => (bool) $i->webhook_secret,
            'ingest_url' => route('siem.ingest', $i),
            'signals_24h' => SiemSignal::where('provider', $i->provider)->where('received_at', '>=', now()->subDay())->count(),
        ]);

        $severity = $r->query('severity');
        $state = $r->query('state');
        $signals = SiemSignal::with(['alert:id,alert_id_code,status,incident_id', 'incident:id,incident_id_code'])
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->when($state === 'untriaged', fn ($q) => $q->whereNull('security_alert_id')->whereNull('incident_id'))
            ->when($state === 'alerted', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('security_alert_id')->orWhereNotNull('incident_id')))
            ->orderByDesc('received_at')->take(100)->get();

        return Inertia::render('Siem/Index', [
            'integrations' => $integrations,
            'signals' => $signals->map(fn (SiemSignal $s) => [
                'id' => $s->id,
                'provider' => SiemIntegration::PROVIDERS[$s->provider] ?? Str::headline($s->provider),
                'external_id' => $s->external_id,
                'title' => $s->title,
                'severity' => $s->severity,
                'received_at' => $s->received_at?->toIso8601String(),
                'payload' => $s->payload,
                'alert' => $s->alert ? ['id' => $s->alert->id, 'code' => $s->alert->alert_id_code, 'status' => $s->alert->status] : null,
                'incident' => $s->incident ? ['id' => $s->incident->id, 'code' => $s->incident->incident_id_code] : null,
            ]),
            'stats' => [
                'connectors' => $integrations->count(),
                'degraded' => $integrations->where('status', '!=', 'active')->count(),
                'signals_24h' => SiemSignal::where('received_at', '>=', now()->subDay())->count(),
                'untriaged' => SiemSignal::whereNull('security_alert_id')->whereNull('incident_id')
                    ->whereIn('severity', ['critical', 'high'])->count(),
            ],
            'newSecret' => session('siem_secret'),
            'filters' => ['severity' => $severity, 'state' => $state],
        ]);
    }

    public function siemRaiseAlert(SiemSignal $signal, SiemIngestService $ingest)
    {
        if ($signal->security_alert_id || $signal->incident_id) {
            return back()->with('error', 'This signal has already been triaged.');
        }
        $label = SiemIntegration::PROVIDERS[$signal->provider] ?? Str::headline($signal->provider);
        $alert = $ingest->raiseAlert($signal, $label);

        return redirect()->route('security-alerts.show', $alert)
            ->with('success', "Alert {$alert->alert_id_code} raised from the {$label} signal.");
    }

    /** Issue a new webhook secret. It is shown once; only the encrypted value is stored. */
    public function siemRotateSecret(SiemIntegration $integration)
    {
        $secret = Str::random(48);
        $integration->forceFill(['webhook_secret' => $secret])->save();

        return back()->with('siem_secret', ['integration_id' => $integration->id, 'secret' => $secret])
            ->with('success', 'New webhook secret issued. Copy it now: it will not be shown again.');
    }

    /** Public, HMAC-authenticated webhook (outside auth/CSRF; see SiemIngestService). */
    public function siemIngest(Request $r, int $integration, SiemIngestService $ingest): JsonResponse
    {
        $connector = SiemIntegration::withoutGlobalScopes()->find($integration);
        $raw = $r->getContent();

        if (! $connector || ! $ingest->verify($connector, $raw, $r->header('X-Atheris-Timestamp'), $r->header('X-Atheris-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! isset($payload['title'])) {
            return response()->json(['error' => 'payload must be a JSON object with a title'], 422);
        }

        $result = $ingest->ingest($connector, $payload);

        return response()->json([
            'signal_id' => $result['signal']->id,
            'duplicate' => $result['duplicate'],
            'alert' => $result['alert']?->alert_id_code,
        ], $result['duplicate'] ? 200 : 201);
    }

    /* ========================== Regulatory notifications ========================== */

    public function notifications(Request $r)
    {
        $status = $r->query('status');
        $base = IncidentNotification::query();

        return Inertia::render('Notifications/Index', [
            'notifications' => (clone $base)
                ->with(['incident:id,incident_id_code,title,severity', 'template:id,template_key,title,regulator_code', 'preparer:id,name', 'approver:id,name'])
                ->when($status === 'overdue', fn ($q) => $q->where('delivery_status', '!=', 'submitted')->where('deadline_at', '<', now()))
                ->when($status && $status !== 'overdue', fn ($q) => $q->where('delivery_status', $status))
                ->orderByRaw("case when delivery_status = 'submitted' then 1 else 0 end")
                ->orderBy('deadline_at')
                ->get(),
            'templates' => NotificationTemplate::orderBy('regulator_code')->get(['id', 'regulator_code', 'template_key', 'title']),
            'incidents' => Incident::where(fn ($q) => $q->whereIn('status', Incident::ACTIVE_STATUSES)->orWhere('detected_at', '>=', now()->subDays(30)))
                ->orderByDesc('detected_at')
                ->get(['id', 'incident_id_code', 'title', 'severity', 'status', 'is_data_breach']),
            'counts' => [
                'open' => (clone $base)->where('delivery_status', '!=', 'submitted')->count(),
                'overdue' => (clone $base)->where('delivery_status', '!=', 'submitted')->where('deadline_at', '<', now())->count(),
                'due_24h' => (clone $base)->where('delivery_status', '!=', 'submitted')->whereBetween('deadline_at', [now(), now()->addDay()])->count(),
                'submitted' => (clone $base)->where('delivery_status', 'submitted')->count(),
            ],
            'deadlineHours' => config('secops.notification_deadline_hours'),
            'filters' => ['status' => $status],
        ]);
    }

    public function notificationDraft(Request $r, NotificationDrafter $drafter)
    {
        $data = $r->validate([
            'incident_id' => ['required', 'integer', Rule::exists('incidents', 'id')
                ->where('organization_id', $r->user()->organization_id)->whereNull('deleted_at')],
            'template_key' => ['required', 'string', Rule::exists('notification_templates', 'template_key')],
        ]);
        $incident = Incident::findOrFail($data['incident_id']);
        $template = NotificationTemplate::where('template_key', $data['template_key'])->firstOrFail();

        if (IncidentNotification::where('incident_id', $incident->id)->where('template_key', $template->template_key)->exists()) {
            return back()->with('error', "{$incident->incident_id_code} already has a {$template->title}.");
        }

        $notification = $drafter->draft($incident, $template, $r->user()->id);

        return redirect()->route('notifications.show', $notification)
            ->with('success', "{$template->title} drafted for {$incident->incident_id_code}. Due ".$notification->deadline_at->format('d M Y H:i').'.');
    }

    public function notificationShow(IncidentNotification $notification)
    {
        $notification->load([
            'incident:id,incident_id_code,title,severity,status,detected_at,is_data_breach',
            'incident.breach:id,incident_id,breach_id_code,discovered_at',
            'template', 'preparer:id,name', 'approver:id,name', 'submitter:id,name',
        ]);

        return Inertia::render('Notifications/Show', [
            'notification' => $notification,
            'canApprove' => request()->user()->can('approve incidents') && $notification->prepared_by_id !== request()->user()->id,
        ]);
    }

    public function notificationUpdate(Request $r, IncidentNotification $notification)
    {
        if (! in_array($notification->delivery_status, ['draft', 'in_review'], true)) {
            return back()->with('error', 'An approved notification is locked. Return it to draft to edit.');
        }
        $data = $r->validate(['draft_body' => 'required|string|max:20000']);
        $notification->update($data + ['delivery_status' => 'draft']);

        return back()->with('success', 'Draft saved.');
    }

    public function notificationTransition(Request $r, IncidentNotification $notification)
    {
        $data = $r->validate([
            'action' => 'required|in:submit_review,approve,return,submit',
            'submission_reference' => 'nullable|required_if:action,submit|string|max:120',
        ]);
        $user = $r->user();
        $from = $notification->delivery_status;

        $allowed = [
            'submit_review' => ['draft'],
            'approve' => ['in_review'],
            'return' => ['in_review', 'approved'],
            'submit' => ['approved'],
        ][$data['action']];
        if (! in_array($from, $allowed, true)) {
            return back()->with('error', 'That step is not available from '.str_replace('_', ' ', $from).'.');
        }

        switch ($data['action']) {
            case 'submit_review':
                $notification->update(['delivery_status' => 'in_review']);
                $msg = 'Sent for approval.';
                break;
            case 'approve':
                abort_unless($user->can('approve incidents'), 403);
                if ($notification->prepared_by_id === $user->id) {
                    return back()->with('error', 'The preparer cannot approve their own notification.');
                }
                $notification->update(['delivery_status' => 'approved', 'approved_by_id' => $user->id, 'signed_at' => now()]);
                $msg = 'Approved and signed.';
                break;
            case 'return':
                $notification->update(['delivery_status' => 'draft', 'approved_by_id' => null, 'signed_at' => null]);
                $msg = 'Returned to draft.';
                break;
            default:
                $notification->update([
                    'delivery_status' => 'submitted',
                    'submitted_at' => now(),
                    'submitted_by_id' => $user->id,
                    'submission_reference' => $data['submission_reference'],
                    'delivery_receipt' => ['reference' => $data['submission_reference'], 'recorded_at' => now()->toIso8601String(), 'by' => $user->name],
                ]);
                $notification->incident?->events()->create([
                    'event_type' => 'communication',
                    'description' => "{$notification->regulator_code} notification submitted (ref {$data['submission_reference']})"
                        .($notification->deadline_at && now()->gt($notification->deadline_at) ? ' after the deadline.' : ' within the deadline.'),
                    'performed_by' => $user->id,
                    'occurred_at' => now(),
                ]);
                $this->syncBreachNotified($notification);
                $msg = 'Recorded as submitted to the regulator.';
        }

        return back()->with('success', $msg);
    }

    /** An NDPC submission is the NDPA notification for the incident's data breach. */
    private function syncBreachNotified(IncidentNotification $notification): void
    {
        if ($notification->regulator_code !== 'NDPC') {
            return;
        }
        $breach = $notification->incident?->breach;
        if ($breach && ! $breach->ndpa_notified_at) {
            $breach->update(['ndpa_notified_at' => now(), 'regulatory_body_notified' => true]);
        }
    }
}
