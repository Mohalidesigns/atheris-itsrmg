<?php

namespace App\Listeners\Core;

use App\Events\Core\IncidentDeclared;
use App\Models\Ea\EaApplication;
use App\Models\Incident;
use App\Services\Ea\AuditLogger;
use App\Services\Ea\BlastRadiusService;

/**
 * Contract I-13 — Incident → EA impact.
 *
 * §7.3: "Incident detail shows blast radius, dependent business services, RTO
 * exposure and third parties — inside the **CBN 30-minute response window**."
 * Status before this: "Absent — blast radius is only reachable from EA."
 *
 * The 30-minute window is the point. A responder does not have time to open a
 * second module, find the right application and run an analysis; the impact
 * picture has to be waiting on the incident when they open it.
 */
class AttachBlastRadius
{
    public function handle(IncidentDeclared $event): void
    {
        $incident = $event->incident;

        $application = $this->matchApplication($incident);

        if (! $application) {
            return;
        }

        try {
            $impact = (new BlastRadiusService())->changeImpact($application->id, 2);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        // Stored on the incident so it survives the EA module being slow or
        // unavailable at exactly the moment it is needed.
        $tags = collect($incident->tags ?? [])
            ->reject(fn ($t) => str_starts_with((string) $t, 'ea_application:'))
            ->push('ea_application:'.$application->id)
            ->unique()->values()->all();

        $incident->forceFill([
            'tags' => $tags,
            'affected_systems' => $this->affectedSystems($incident, $impact, $application),
        ])->save();

        AuditLogger::logBare('contract.i13.blast_radius', Incident::class, $incident->id, [
            'application_id' => $application->id,
            'nodes' => count($impact['radius']['nodes'] ?? []),
        ]);
    }

    /**
     * Incidents name affected systems as free text. Match conservatively —
     * attaching the wrong blast radius during a live incident is worse than
     * attaching none.
     */
    private function matchApplication(Incident $incident): ?EaApplication
    {
        $candidates = collect($incident->affected_systems ?? [])
            ->map(fn ($s) => is_array($s) ? ($s['name'] ?? null) : $s)
            ->filter()
            ->map(fn ($s) => mb_strtolower(trim((string) $s)));

        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($candidates as $name) {
            $matches = EaApplication::withoutGlobalScopes()
                ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$name])
                    ->orWhereRaw('LOWER(code) = ?', [$name]))
                ->get();

            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    private function affectedSystems(Incident $incident, array $impact, EaApplication $application): array
    {
        $existing = collect($incident->affected_systems ?? [])
            ->reject(fn ($s) => is_array($s) && ($s['source'] ?? null) === 'ea.blast_radius');

        $dependents = collect($impact['radius']['nodes'] ?? [])
            ->reject(fn ($n) => ($n['id'] ?? null) === $application->id && ($n['type'] ?? '') === 'application')
            ->take(25)
            ->map(fn ($n) => [
                'name' => $n['label'] ?? $n['name'] ?? 'Unknown',
                'type' => $n['type'] ?? 'node',
                'source' => 'ea.blast_radius',
            ]);

        return $existing->merge($dependents)->values()->all();
    }
}
