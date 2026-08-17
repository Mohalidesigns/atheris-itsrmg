<?php

namespace App\Services\Ea;

use App\Models\Ea\ApplicationInstance;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Process;
use App\Models\Ea\Site;
use Illuminate\Support\Collection;

/**
 * SiteResilienceService — ATH-EAR-002 A5, §8.3: "Geographic concentration
 * detection; resilience posture vs BIA."
 *
 * §6.3: "Nigeria has **28 data centres, 21 of them in Lagos** — severe
 * geographic concentration for DR purposes. Power is an under-acknowledged
 * cause of payment failure and was **entirely absent from stakeholder
 * checklists** for achieving PSV 2028 targets… A DR architecture that ignores
 * diesel is fiction in this market."
 *
 * The three reports §6.3 A5 specifies:
 *   1. Geographic concentration alert — primary and DR both in Lagos, or in the
 *      same grid/flood zone.
 *   2. Resilience posture by business service — RTO/RPO from BIA versus the
 *      actual site tier and generator autonomy supporting it.
 *   3. Single-site dependency register for critical business services.
 */
class SiteResilienceService
{
    /** Portfolio-level site posture for the control tower header. */
    public function overview(): array
    {
        $sites = Site::all();

        $byCity = $sites->groupBy(fn ($s) => $s->city ?: 'Unknown')->map->count()->sortDesc();
        $nigerian = $sites->where('country', 'NG');

        return [
            'total_sites' => $sites->count(),
            'nigerian_sites' => $nigerian->count(),
            'offshore_sites' => $sites->count() - $nigerian->count(),
            'by_city' => $byCity->all(),
            'lagos_share' => $sites->count()
                ? round(($byCity['Lagos'] ?? 0) / $sites->count() * 100, 1)
                : 0.0,
            'tier_iii_plus' => $sites->filter(fn ($s) => (int) $s->tia942_tier >= 3)->count(),
            'median_generator_hours' => (int) round((float) $sites->median('generator_autonomy_hours')),
            'weak_sites' => $sites->filter(fn ($s) => in_array($s->resilienceBand(), ['weak', 'inadequate'], true))->count(),
        ];
    }

    /**
     * Report 1 — geographic concentration alerts.
     *
     * Flags primary/DR pairs that share a city, a grid zone or a flood
     * exposure. Twenty-one of twenty-eight Nigerian data centres are in Lagos,
     * so "we have a DR site" says very little on its own.
     */
    public function concentrationAlerts(): array
    {
        $alerts = [];

        foreach ($this->deployments() as $deployment) {
            $primary = $deployment['primary_site'];
            $dr = $deployment['dr_site'];

            if (! $primary) {
                $alerts[] = $this->alert($deployment, 'no_primary_site', 'high',
                    'No hosting site recorded, so its resilience cannot be assessed at all.');

                continue;
            }

            if (! $dr) {
                if (in_array($deployment['criticality'], ['critical', 'high'], true)) {
                    $alerts[] = $this->alert($deployment, 'no_dr_site', 'critical',
                        'A '.$deployment['criticality'].'-criticality workload with no recorded DR site.');
                }

                continue;
            }

            if ($primary->city && $primary->city === $dr->city) {
                $alerts[] = $this->alert($deployment, 'same_city', 'critical',
                    "Primary and DR are both in {$primary->city}. A city-wide event takes both.");
            } elseif ($primary->grid_zone && $primary->grid_zone === $dr->grid_zone) {
                $alerts[] = $this->alert($deployment, 'same_grid_zone', 'high',
                    "Primary and DR share grid zone {$primary->grid_zone}. A grid failure takes both.");
            }

            if ($primary->flood_risk === 'high' && $dr->flood_risk === 'high') {
                $alerts[] = $this->alert($deployment, 'both_flood_exposed', 'high',
                    'Both sites carry high flood risk.');
            }

            if ($dr->outageAutonomyHours() < 24 && in_array($deployment['criticality'], ['critical', 'high'], true)) {
                $alerts[] = $this->alert($deployment, 'dr_thin_autonomy', 'medium',
                    'The DR site holds under 24 hours of power autonomy — thin cover for a sustained grid event.');
            }
        }

        return $alerts;
    }

    private function alert(array $deployment, string $type, string $severity, string $message): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'application_id' => $deployment['application_id'],
            'label' => $deployment['label'],
            'criticality' => $deployment['criticality'],
            'primary_site' => $deployment['primary_site']?->name,
            'dr_site' => $deployment['dr_site']?->name,
        ];
    }

    /**
     * Report 2 — resilience posture by business service.
     *
     * §6.3 A5: "RTO/RPO from BIA versus the actual site tier and generator
     * autonomy supporting it." Contract I-6 is what makes the RTO real rather
     * than seeded, which is why this could not have been built before Phase 2.
     */
    public function postureAgainstBia(): array
    {
        $rows = [];

        $processes = Process::whereNotNull('rto_hours')->get();

        foreach ($processes as $process) {
            $applicationIds = $process->linked_applications ?? [];

            if (empty($applicationIds)) {
                continue;
            }

            $applications = EaApplication::whereIn('id', $applicationIds)
                ->with(['hostingSite', 'drSite'])
                ->get();

            foreach ($applications as $application) {
                $site = $application->hostingSite;
                $autonomy = $site?->outageAutonomyHours() ?? 0;
                $rto = (float) $process->rto_hours;

                // The question that matters: can the site stay up long enough
                // to meet the recovery objective the business signed off?
                $meets = $site !== null && $autonomy >= $rto;

                $rows[] = [
                    'process' => $process->name,
                    'process_code' => $process->code,
                    'criticality' => $process->criticality,
                    'rto_hours' => $rto,
                    'rpo_hours' => $process->rpo_hours,
                    'application' => $application->name,
                    'application_id' => $application->id,
                    'site' => $site?->name,
                    'site_tier' => $site?->tia942_tier,
                    'autonomy_hours' => $autonomy,
                    'dr_site' => $application->drSite?->name,
                    'meets_rto' => $meets,
                    'shortfall_hours' => $meets ? 0 : round($rto - $autonomy, 2),
                    'resilience_band' => $site?->resilienceBand() ?? 'unknown',
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['shortfall_hours'] <=> $a['shortfall_hours']);

        return $rows;
    }

    /**
     * Report 3 — single-site dependency register.
     *
     * Sites carrying critical workloads with no DR anywhere else.
     */
    public function singleSiteDependencies(): array
    {
        $register = [];

        foreach (Site::all() as $site) {
            $exposed = $this->deployments()
                ->filter(fn ($d) => $d['primary_site']?->id === $site->id)
                ->filter(fn ($d) => $d['dr_site'] === null || $d['dr_site']->id === $site->id)
                ->filter(fn ($d) => in_array($d['criticality'], ['critical', 'high'], true));

            if ($exposed->isEmpty()) {
                continue;
            }

            $register[] = [
                'site_id' => $site->id,
                'site' => $site->name,
                'city' => $site->city,
                'tier' => $site->tia942_tier,
                'autonomy_hours' => $site->outageAutonomyHours(),
                'resilience_band' => $site->resilienceBand(),
                'exposed_count' => $exposed->count(),
                'exposed' => $exposed->pluck('label')->take(10)->values()->all(),
            ];
        }

        usort($register, fn ($a, $b) => $b['exposed_count'] <=> $a['exposed_count']);

        return $register;
    }

    /**
     * Every deployment, whether modelled as an application (single-entity) or
     * an instance (multi-entity, A6). Memoised — three reports traverse it.
     *
     * @return Collection<int, array>
     */
    private function deployments(): Collection
    {
        return $this->deployments ??= $this->buildDeployments();
    }

    private ?Collection $deployments = null;

    private function buildDeployments(): Collection
    {
        $sites = Site::all()->keyBy('id');

        // Base collection, not Eloquent: see the note in ResidencyService.
        $fromApplications = collect(EaApplication::query()
            ->get(['id', 'code', 'name', 'criticality', 'hosting_site_id', 'dr_site_id'])
            ->all())
            ->map(fn ($a) => [
                'application_id' => $a->id,
                'label' => "{$a->code} — {$a->name}",
                'criticality' => $a->criticality,
                'primary_site' => $sites->get($a->hosting_site_id),
                'dr_site' => $sites->get($a->dr_site_id),
                'source' => 'application',
            ]);

        $fromInstances = collect(ApplicationInstance::query()
            ->with('application:id,code,name')
            ->get()
            ->all())
            ->map(fn ($i) => [
                'application_id' => $i->application_id,
                'label' => $i->name ?: ($i->application?->name.' — '.$i->code),
                'criticality' => $i->criticality,
                'primary_site' => $sites->get($i->hosting_site_id),
                'dr_site' => $sites->get($i->dr_site_id),
                'source' => 'instance',
            ]);

        // An application with instances is represented by its instances; the
        // instance carries the residency and the site, so counting both would
        // double-count the estate.
        $instanceApplicationIds = $fromInstances->pluck('application_id')->unique()->flip();

        return $fromApplications
            ->reject(fn ($d) => $instanceApplicationIds->has($d['application_id']))
            ->merge($fromInstances)
            ->values();
    }
}
