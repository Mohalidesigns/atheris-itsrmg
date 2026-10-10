<?php

namespace App\Services\SecurityOps;

use App\Models\DataBreach;
use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\SecurityAlert;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use App\Models\Vulnerability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Security Operations KPIs for the signed-in bank. Shared by the SecOps dashboard and the
 * executive dashboard so the two never disagree. All queries rely on the models' tenant scopes.
 */
class SecurityOpsMetrics
{
    private ?Collection $ranked = null;

    public function __construct(private VulnerabilityPrioritiser $prioritiser) {}

    private function ranked(): Collection
    {
        return $this->ranked ??= $this->prioritiser->outstanding();
    }

    public function summary(): array
    {
        $ranked = $this->ranked();

        return [
            'vulns_outstanding' => $ranked->count(),
            'vulns_critical' => $ranked->where('severity', 'critical')->count(),
            'vulns_high' => $ranked->where('severity', 'high')->count(),
            'vulns_kev' => $ranked->where('kev', true)->count(),
            'vulns_p1' => $ranked->where('band', 'P1')->count(),
            'vulns_sla_breached' => $ranked->where('sla_breached', true)->count(),
            'incidents_active' => Incident::whereIn('status', Incident::ACTIVE_STATUSES)->count(),
            'incidents_critical' => Incident::whereIn('status', Incident::ACTIVE_STATUSES)->where('severity', 'critical')->count(),
            'alerts_new' => SecurityAlert::where('status', 'new')->count(),
            'alerts_new_high' => SecurityAlert::where('status', 'new')->whereIn('severity', ['critical', 'high'])->count(),
            'breaches_active' => DataBreach::whereNotIn('status', ['resolved', 'closed'])->count(),
            'ndpa_pending' => DataBreach::where('ndpa_notification_required', true)->whereNull('ndpa_notified_at')->count(),
            'notifications_open' => IncidentNotification::where('delivery_status', '!=', 'submitted')->count(),
            'notifications_overdue' => IncidentNotification::where('delivery_status', '!=', 'submitted')->where('deadline_at', '<', now())->count(),
            'siem_degraded' => SiemIntegration::where('status', '!=', 'active')->count(),
            'siem_untriaged' => SiemSignal::whereNull('security_alert_id')->whereNull('incident_id')
                ->whereIn('severity', ['critical', 'high'])->count(),
        ];
    }

    /** Mean hours from detection to containment / resolution, over incidents detected in the window. */
    public function responseTimes(int $days = 180): array
    {
        $incidents = Incident::where('detected_at', '>=', now()->subDays($days))
            ->get(['detected_at', 'responded_at', 'contained_at', 'resolved_at', 'closed_at']);

        $mean = function (string $field) use ($incidents) {
            $hours = $incidents->filter(fn ($i) => $i->detected_at && $i->{$field})
                ->map(fn ($i) => max(0, $i->detected_at->diffInMinutes($i->{$field})) / 60);

            return $hours->isEmpty() ? null : round($hours->avg(), 1);
        };

        $remediated = Vulnerability::where('status', 'remediated')
            ->whereNotNull('remediated_at')->whereNotNull('discovered_at')
            ->where('remediated_at', '>=', now()->subDays($days))
            ->get(['discovered_at', 'remediated_at']);

        return [
            'mttr_respond_h' => $mean('responded_at'),
            'mttr_contain_h' => $mean('contained_at'),
            'mttr_resolve_h' => $mean('resolved_at'),
            'vuln_mttr_days' => $remediated->isEmpty() ? null
                : round($remediated->avg(fn ($v) => max(0, $v->discovered_at->diffInHours($v->remediated_at)) / 24), 1),
            'window_days' => $days,
        ];
    }

    /**
     * Every running regulatory clock: unsubmitted notifications and NDPA breach deadlines not yet met,
     * soonest first.
     */
    public function regulatoryClocks(int $limit = 8): Collection
    {
        $notifications = IncidentNotification::with('incident:id,incident_id_code,title')
            ->where('delivery_status', '!=', 'submitted')
            ->get()
            ->map(fn (IncidentNotification $n) => [
                'kind' => 'notification',
                'id' => $n->id,
                'label' => "{$n->regulator_code} notification",
                'subject' => $n->incident?->incident_id_code.' · '.$n->incident?->title,
                'status' => $n->delivery_status,
                'deadline_at' => $n->deadline_at?->toIso8601String(),
                'overdue' => $n->deadline_at?->isPast() ?? false,
                'href' => route('notifications.show', $n->id),
            ]);

        $breaches = DataBreach::where('ndpa_notification_required', true)->whereNull('ndpa_notified_at')
            ->get(['id', 'breach_id_code', 'title', 'status', 'ndpa_notification_deadline'])
            ->map(fn (DataBreach $b) => [
                'kind' => 'breach',
                'id' => $b->id,
                'label' => 'NDPA 72h breach notice',
                'subject' => "{$b->breach_id_code} · {$b->title}",
                'status' => $b->status,
                'deadline_at' => $b->ndpa_notification_deadline?->toIso8601String(),
                'overdue' => $b->ndpa_notification_deadline?->isPast() ?? false,
                'href' => route('data-breaches.show', $b->id),
            ]);

        return $notifications->concat($breaches)
            ->sortBy(fn ($c) => $c['deadline_at'] ? Carbon::parse($c['deadline_at'])->timestamp : PHP_INT_MAX)
            ->take($limit)->values();
    }

    public function topPriorities(int $limit = 5): Collection
    {
        return $this->ranked()->take($limit)->values();
    }
}
