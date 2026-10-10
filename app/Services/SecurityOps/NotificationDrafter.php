<?php

namespace App\Services\SecurityOps;

use App\Models\Incident;
use App\Models\IncidentNotification;
use App\Models\NotificationTemplate;
use Illuminate\Support\Carbon;

/**
 * Drafts a regulatory notification from a template and the incident record. Templates may use
 * {{tokens}} (see tokens()); a template without tokens gets the incident facts appended so the
 * preparer never starts from a blank form. No LLM is involved: the draft is deterministic.
 */
class NotificationDrafter
{
    public function draft(Incident $incident, NotificationTemplate $template, int $preparerId): IncidentNotification
    {
        $incident->loadMissing(['breach', 'assignee:id,name', 'organization:id,name']);

        return IncidentNotification::create([
            'organization_id' => $incident->organization_id,
            'incident_id' => $incident->id,
            'template_key' => $template->template_key,
            'regulator_code' => $template->regulator_code,
            'draft_body' => $this->render($incident, $template),
            'prepared_by_id' => $preparerId,
            'delivery_status' => 'draft',
            'deadline_at' => $this->deadline($incident, $template->regulator_code),
        ]);
    }

    /** The clock runs from awareness: a linked breach's discovery, else the incident's detection. */
    public function deadline(Incident $incident, ?string $regulator): Carbon
    {
        $hours = config("secops.notification_deadline_hours.{$regulator}", 72);
        $aware = $regulator === 'NDPC' && $incident->breach?->discovered_at
            ? $incident->breach->discovered_at
            : ($incident->detected_at ?? $incident->created_at ?? now());

        return Carbon::parse($aware)->copy()->addHours($hours);
    }

    public function render(Incident $incident, NotificationTemplate $template): string
    {
        $tokens = $this->tokens($incident);
        $body = (string) $template->body_template;

        if (str_contains($body, '{{')) {
            return strtr($body, $tokens);
        }

        $facts = collect([
            'Bank' => $tokens['{{bank}}'],
            'Incident reference' => $tokens['{{incident.code}}'],
            'Title' => $tokens['{{incident.title}}'],
            'Type / severity' => $tokens['{{incident.type}}'].' / '.$tokens['{{incident.severity}}'],
            'Detected' => $tokens['{{incident.detected_at}}'],
            'Current status' => $tokens['{{incident.status}}'],
            'Affected systems' => $tokens['{{incident.affected_systems}}'],
            'Personal data records affected' => $tokens['{{breach.records}}'],
            'Summary' => $tokens['{{incident.description}}'],
        ])->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");

        return rtrim($body)."\n\n--- Incident facts (from {$tokens['{{incident.code}}']}) ---\n".$facts."\n";
    }

    public function tokens(Incident $incident): array
    {
        $systems = collect($incident->affected_systems ?? [])->filter()->implode(', ');

        return [
            '{{bank}}' => $incident->organization?->name ?? '—',
            '{{incident.code}}' => $incident->incident_id_code,
            '{{incident.title}}' => $incident->title,
            '{{incident.type}}' => str_replace('_', ' ', (string) $incident->type),
            '{{incident.severity}}' => (string) $incident->severity,
            '{{incident.status}}' => str_replace('_', ' ', (string) $incident->status),
            // Regulators read West Africa Time.
            '{{incident.detected_at}}' => $incident->detected_at ? $incident->detected_at->copy()->timezone('Africa/Lagos')->format('d M Y H:i').' WAT' : '—',
            '{{incident.description}}' => $incident->description ?: '—',
            '{{incident.affected_systems}}' => $systems !== '' ? $systems : '—',
            '{{incident.owner}}' => $incident->assignee?->name ?? '—',
            '{{breach.records}}' => $incident->breach ? number_format((int) $incident->breach->records_affected) : 'n/a',
            '{{breach.data_types}}' => $incident->breach ? collect($incident->breach->data_types_affected ?? [])->implode(', ') : 'n/a',
        ];
    }
}
