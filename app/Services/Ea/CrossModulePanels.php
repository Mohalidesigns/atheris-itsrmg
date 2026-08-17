<?php

namespace App\Services\Ea;

use App\Models\Ea\ControlMapping;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\TechComponent;
use App\Models\Ea\ZoneAssignment;
use App\Models\Incident;

/**
 * CrossModulePanels — WS 2.6.
 *
 * §7.4 lists the changes other modules need: "Assets — EA panel on Asset detail
 * (capability, criticality, TIME, residency)"; "SecurityOps — Blast-radius
 * panel on Incident detail".
 *
 * The panels live here rather than in each module's controller so the EA
 * dependency stays in one direction. A module asks EA a question; it does not
 * grow its own understanding of the architecture graph. §7.1: "there is exactly
 * one architecture graph, EA owns it, and every other module reads from it."
 *
 * Every method degrades to null rather than throwing — an EA table being
 * unavailable must never take down the Asset or Incident page.
 */
class CrossModulePanels
{
    /**
     * The EA view of a physical asset (contract I-3).
     *
     * §7.2 splits ownership deliberately: "EA is master for the *logical*
     * application; `Asset` remains master for the *physical* instance; hard-link
     * via `asset_id`." This panel shows the logical side of that link.
     */
    public function forAsset(int $assetId): ?array
    {
        try {
            $application = EaApplication::where('asset_id', $assetId)->first();

            if (! $application) {
                return null;
            }

            $seal = QualitySeal::forEntity(EaApplication::class, $application->id)->first();

            $capabilities = \App\Models\Ea\Capability::query()
                ->whereIn('id', $application->capability_ids ?? [])
                ->get(['id', 'code', 'name']);

            $zone = ZoneAssignment::where('application_id', $application->id)->with('zone:id,code,name,trust_level')->first();

            $interfaces = EaInterface::where('source_app_id', $application->id)
                ->orWhere('target_app_id', $application->id)
                ->count();

            $techComponents = TechComponent::whereJsonContains('application_ids', $application->id)
                ->get(['id', 'code', 'name', 'eol_date', 'obsolescence_flag']);

            $coverage = (new ControlInheritanceService())->citableCoverage($application->id, '*');

            return [
                'application' => [
                    'id' => $application->id,
                    'code' => $application->code,
                    'name' => $application->name,
                    'criticality' => $application->criticality,
                    'lifecycle' => $application->lifecycle,
                    'time_score' => $application->time_score,
                    'business_fit' => $application->business_fit,
                    'technical_fit' => $application->technical_fit,
                    'annual_cost_ngn' => $application->annual_cost_ngn,
                    'href' => route('ea.applications.show', $application->id),
                ],
                'capabilities' => $capabilities,
                'zone' => $zone?->zone,
                'interface_count' => $interfaces,
                'tech_components' => $techComponents,
                'obsolete_components' => $techComponents->where('obsolescence_flag', true)->count(),
                'control_coverage' => $coverage,
                'seal' => $seal ? [
                    'state' => $seal->state,
                    'label' => $seal->stateLabel(),
                    'tone' => $seal->tone(),
                    'completeness' => $seal->completeness,
                ] : null,
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The blast radius for an incident (contract I-13).
     *
     * §7.3 frames this against the CBN 30-minute response window: a responder
     * must not have to open the EA module and run an analysis to find out what
     * else breaks.
     */
    public function forIncident(Incident $incident, int $depth = 2): ?array
    {
        try {
            $applicationId = $this->applicationIdFor($incident);

            if (! $applicationId) {
                return null;
            }

            $application = EaApplication::find($applicationId);

            if (! $application) {
                return null;
            }

            $impact = (new BlastRadiusService())->changeImpact($applicationId, $depth);

            $processes = \App\Models\Ea\Process::whereJsonContains('linked_applications', $applicationId)
                ->get(['id', 'code', 'name', 'criticality', 'rto_hours', 'rpo_hours']);

            return [
                'application' => [
                    'id' => $application->id,
                    'code' => $application->code,
                    'name' => $application->name,
                    'criticality' => $application->criticality,
                    'href' => route('ea.applications.show', $application->id),
                ],
                'summary' => $impact['summary'] ?? [],
                'by_type' => $impact['radius']['by_type'] ?? [],
                'node_count' => count($impact['radius']['nodes'] ?? []),
                'processes' => $processes,
                // The number the responder actually needs: the tightest
                // recovery objective any dependent process is held to.
                'tightest_rto_hours' => $processes->whereNotNull('rto_hours')->min('rto_hours'),
                'blast_radius_href' => route('ea.blast-radius', ['app_id' => $applicationId]),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Resolve the EA application an incident concerns.
     *
     * Prefers the tag AttachBlastRadius wrote on declaration; falls back to a
     * conservative name match. Returns null rather than guessing — showing the
     * wrong dependency picture during a live incident is worse than showing
     * none.
     */
    private function applicationIdFor(Incident $incident): ?int
    {
        $tagged = collect($incident->tags ?? [])
            ->first(fn ($t) => str_starts_with((string) $t, 'ea_application:'));

        if ($tagged) {
            return (int) str_replace('ea_application:', '', $tagged);
        }

        $names = collect($incident->affected_systems ?? [])
            ->map(fn ($s) => is_array($s) ? ($s['name'] ?? null) : $s)
            ->filter()
            ->map(fn ($s) => mb_strtolower(trim((string) $s)));

        foreach ($names as $name) {
            $matches = EaApplication::where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [$name])
                ->orWhereRaw('LOWER(code) = ?', [$name]))->get(['id']);

            if ($matches->count() === 1) {
                return (int) $matches->first()->id;
            }
        }

        return null;
    }

    /**
     * The reverse dependency view §7.3 asks for on the Vendor page (I-5):
     * "the TPRM officer's screen today shows nothing of this".
     */
    public function forVendor(int $vendorId): ?array
    {
        try {
            $applications = EaApplication::where('vendor_id', $vendorId)
                ->get(['id', 'code', 'name', 'criticality', 'lifecycle', 'annual_cost_ngn']);

            if ($applications->isEmpty()) {
                return null;
            }

            $capabilityIds = $applications->flatMap(fn ($a) => $a->capability_ids ?? [])->unique();

            return [
                'applications' => $applications,
                'critical_count' => $applications->whereIn('criticality', ['critical', 'high'])->count(),
                'annual_spend_ngn' => (float) $applications->sum('annual_cost_ngn'),
                'capabilities' => \App\Models\Ea\Capability::whereIn('id', $capabilityIds)
                    ->get(['id', 'code', 'name']),
            ];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
