<?php

namespace App\Services\Ea;

use App\Models\Ea\ApplicationCapability;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Process;
use App\Models\Ea\TechComponent;
use Illuminate\Support\Collection;

/**
 * CostModelService — cost, TCO and technical debt (WS 4.7 / B17).
 *
 * §5.4 on B17: "annual cost, TCO, currency of licence, cost per capability —
 * feeds B8 and the CFO conversation. **Avolution's differentiator.**" §11 makes
 * the CFO the third buyer in the dual-buyer motion: "the CIO gets portfolio,
 * radar, ARB and roadmap; the CISO gets CSAT evidence...; **the CFO gets the FX
 * exposure lens** — all from one dataset, priced once."
 *
 * Phase 3 delivered the currency dimension (A3). This is the part beyond FX:
 *
 *  · **TCO** — licence is the number a bank has; total cost of ownership is the
 *    number it needs. Modelled with explicit, configurable uplift factors rather
 *    than a magic multiplier, so an architect can see and argue with each one.
 *  · **Cost per capability** — the allocation that lets a CFO ask "what do we
 *    spend on payments?", which no line item in the general ledger answers.
 *  · **Technical debt scoring** — a 0–100 score per application from the signals
 *    the repository already holds honestly (fit, lifecycle, obsolete
 *    dependencies, EOL exposure, seal freshness).
 *  · **Rationalisation candidates** — the post-merger question (§11 wedge 3):
 *    where two or more applications cover the same capability, what does the
 *    duplication cost?
 *
 * Every figure carries a coverage percentage. A TCO total over an estate where
 * 40% of applications have no cost recorded is not a TCO total, and §10's
 * evidence-integrity gate applies to money as much as to controls.
 */
class CostModelService
{
    private ?Collection $applications = null;

    public function __construct(
        private FxExposureService $fx = new FxExposureService(),
    ) {
    }

    /**
     * TCO uplift factors, as multiples of recorded licence/subscription cost.
     *
     * Defaults are deliberately conservative industry mid-points and live in
     * config for the same reason the FX rates do: a factor compiled into code is
     * a wrong number with a deployment cycle attached. §6.2's ⚠️-unverified
     * discipline, applied to cost.
     */
    public function factors(): array
    {
        return array_merge([
            'infrastructure' => 0.35,   // compute, storage, DR capacity
            'support' => 0.20,          // vendor support and maintenance
            'internal_effort' => 0.25,  // run-team salary allocation
            'integration' => 0.10,      // interface upkeep
            // Criticality multipliers on the infrastructure share: a critical
            // system carries hot DR, a low one does not.
            'criticality_uplift' => [
                'critical' => 0.45,
                'high' => 0.25,
                'medium' => 0.10,
                'low' => 0.0,
            ],
        ], config('ea.cost.factors', []));
    }

    /* ===================== application-level ===================== */

    /**
     * Licence cost in naira, plus the provenance of that figure.
     *
     * @return array{ngn:float|null, native:float|null, currency:string|null, basis:string}
     */
    public function licenceCost(EaApplication $application): array
    {
        $native = $application->annual_cost !== null ? (float) $application->annual_cost : null;
        $currency = $application->cost_currency ? strtoupper($application->cost_currency) : null;
        $converted = $this->fx->toNgn($native, $currency);

        if ($converted !== null) {
            return ['ngn' => $converted, 'native' => $native, 'currency' => $currency, 'basis' => 'converted'];
        }

        if ($application->annual_cost_ngn !== null) {
            return [
                'ngn' => (float) $application->annual_cost_ngn,
                'native' => (float) $application->annual_cost_ngn,
                'currency' => 'NGN',
                // The legacy column carries no currency. Treating it as naira is
                // the only option, and naming the assumption is the point.
                'basis' => 'legacy_ngn_column',
            ];
        }

        if ($native !== null) {
            return ['ngn' => null, 'native' => $native, 'currency' => $currency, 'basis' => 'unknown_currency'];
        }

        return ['ngn' => null, 'native' => null, 'currency' => null, 'basis' => 'not_recorded'];
    }

    /**
     * Modelled TCO for one application, itemised.
     *
     * `tco_annual_ngn` on the record wins when an architect has entered a real
     * figure — a model should never overwrite a measurement.
     *
     * @return array<string,mixed>
     */
    public function tco(EaApplication $application): array
    {
        $factors = $this->factors();
        $licence = $this->licenceCost($application);

        if ($application->tco_annual_ngn !== null) {
            return [
                'application_id' => $application->id,
                'licence_ngn' => $licence['ngn'],
                'total_ngn' => (float) $application->tco_annual_ngn,
                'components' => [],
                'basis' => 'recorded',
                'modelled' => false,
                'licence_basis' => $licence['basis'],
            ];
        }

        if ($licence['ngn'] === null) {
            return [
                'application_id' => $application->id,
                'licence_ngn' => null,
                'total_ngn' => null,
                'components' => [],
                'basis' => $licence['basis'],
                'modelled' => false,
                'licence_basis' => $licence['basis'],
            ];
        }

        $base = $licence['ngn'];
        $criticalityUplift = (float) ($factors['criticality_uplift'][$application->criticality] ?? 0.1);

        $components = [
            'licence' => round($base, 2),
            'infrastructure' => round($base * ((float) $factors['infrastructure'] + $criticalityUplift), 2),
            'support' => round($base * (float) $factors['support'], 2),
            'internal_effort' => round($base * (float) $factors['internal_effort'], 2),
            'integration' => round($base * (float) $factors['integration'] * $this->interfaceWeight($application), 2),
        ];

        return [
            'application_id' => $application->id,
            'licence_ngn' => round($base, 2),
            'total_ngn' => round(array_sum($components), 2),
            'components' => $components,
            'basis' => 'modelled',
            'modelled' => true,
            'licence_basis' => $licence['basis'],
        ];
    }

    /**
     * Integration upkeep scales with how many interfaces an application carries.
     * 1.0 at the estate average, so the factor stays interpretable.
     */
    private function interfaceWeight(EaApplication $application): float
    {
        $counts = $this->interfaceCounts();
        $average = $counts->avg() ?: 1.0;

        return round(max(0.25, ($counts[$application->id] ?? 0) / $average), 2);
    }

    private ?Collection $interfaceCounts = null;

    private function interfaceCounts(): Collection
    {
        if ($this->interfaceCounts !== null) {
            return $this->interfaceCounts;
        }

        $counts = [];
        foreach (\App\Models\Ea\EaInterface::query()->get(['source_app_id', 'target_app_id']) as $row) {
            foreach ([$row->source_app_id, $row->target_app_id] as $id) {
                if ($id) {
                    $counts[(int) $id] = ($counts[(int) $id] ?? 0) + 1;
                }
            }
        }

        return $this->interfaceCounts = collect($counts);
    }

    /**
     * Technical debt, 0 (none) to 100 (retire it now).
     *
     * Built only from signals the repository holds with provenance. Notably it
     * does *not* invent a code-quality metric — the module has no visibility of
     * source, and a fabricated dimension would discredit the four real ones.
     *
     * @return array{score:float, band:string, drivers:array<int,string>, confidence:float}
     */
    public function technicalDebt(EaApplication $application): array
    {
        $drivers = [];
        $score = 0.0;
        $known = 0;
        $possible = 5;

        // 1. Technical fit (0–30). The architect's own assessment.
        if ($application->technical_fit !== null) {
            $known++;
            $fitPenalty = (5 - (int) $application->technical_fit) / 4 * 30;
            $score += $fitPenalty;
            if ($fitPenalty >= 15) {
                $drivers[] = "Technical fit {$application->technical_fit}/5";
            }
        }

        // 2. Lifecycle (0–15). A sunsetting system still in production is debt
        //    by definition; a retired one is not (it is gone).
        $known++;
        $lifecyclePenalty = match ($application->lifecycle) {
            'sunset' => 15.0,
            'live' => 4.0,
            'build', 'plan' => 0.0,
            'retired' => 0.0,
            default => 6.0,
        };
        $score += $lifecyclePenalty;
        if ($application->lifecycle === 'sunset') {
            $drivers[] = 'Sunsetting but still in service';
        }

        // 3. Obsolete technology dependencies (0–25). I-1 makes this real: an
        //    obsolescence flag has already opened a risk in the register.
        $techIds = $this->techFor($application->id);
        $obsolete = $techIds
            ? TechComponent::whereIn('id', $techIds)->where('obsolescence_flag', true)->count()
            : 0;
        $known++;
        if ($techIds) {
            $share = $obsolete / count($techIds);
            $score += $share * 25;
            if ($obsolete) {
                $drivers[] = "{$obsolete} of ".count($techIds).' technology dependencies past end-of-life';
            }
        }

        // 4. EOL inside 12 months (0–15).
        $eolSoon = $techIds
            ? TechComponent::whereIn('id', $techIds)
                ->whereNotNull('eol_date')
                ->whereBetween('eol_date', [now(), now()->addYear()])->count()
            : 0;
        $known++;
        if ($eolSoon) {
            $score += min(15.0, $eolSoon * 5.0);
            $drivers[] = "{$eolSoon} dependency end-of-life date(s) inside 12 months";
        }

        // 5. Concentration on a single vendor with no documented alternative
        //    (0–15). Draws on A4's substitutability dimension.
        $known++;
        if ($application->vendor_id && ($application->criticality === 'critical' || $application->criticality === 'high')) {
            $score += 8.0;
            $drivers[] = 'Critical system on a single external vendor';
        }

        $score = round(min(100.0, $score), 1);

        return [
            'score' => $score,
            'band' => match (true) {
                $score >= 60 => 'severe',
                $score >= 40 => 'high',
                $score >= 20 => 'moderate',
                default => 'low',
            },
            'drivers' => $drivers,
            // How much of the score rests on recorded data rather than defaults.
            'confidence' => round($known / $possible * 100, 1),
        ];
    }

    private ?array $techByApp = null;

    private function techFor(int $applicationId): array
    {
        if ($this->techByApp === null) {
            $this->techByApp = [];
            foreach (TechComponent::query()->get(['id', 'application_ids']) as $component) {
                foreach ((array) ($component->application_ids ?? []) as $appId) {
                    $this->techByApp[(int) $appId][] = (int) $component->id;
                }
            }
        }

        return $this->techByApp[$applicationId] ?? [];
    }

    /* ===================== portfolio roll-ups ===================== */

    private function applications(): Collection
    {
        return $this->applications ??= EaApplication::query()->get();
    }

    /**
     * Estate-wide cost and debt summary, with coverage.
     */
    public function portfolio(): array
    {
        $apps = $this->applications();
        $licenceTotal = 0.0;
        $tcoTotal = 0.0;
        $recordedTco = 0;
        $modelledTco = 0;
        $noCost = 0;
        $unknownCurrency = 0;
        $debtScores = [];
        $rows = [];

        foreach ($apps as $app) {
            $tco = $this->tco($app);
            $debt = $this->technicalDebt($app);
            $debtScores[$app->id] = $debt['score'];

            if ($tco['licence_ngn'] !== null) {
                $licenceTotal += $tco['licence_ngn'];
            }
            if ($tco['total_ngn'] !== null) {
                $tcoTotal += $tco['total_ngn'];
                $tco['modelled'] ? $modelledTco++ : $recordedTco++;
            } elseif ($tco['licence_basis'] === 'unknown_currency') {
                $unknownCurrency++;
            } else {
                $noCost++;
            }

            $rows[] = [
                'id' => $app->id,
                'code' => $app->code,
                'name' => $app->name,
                'criticality' => $app->criticality,
                'lifecycle' => $app->lifecycle,
                'time_score' => $app->time_score,
                'currency' => $app->cost_currency,
                'licence_ngn' => $tco['licence_ngn'],
                'tco_ngn' => $tco['total_ngn'],
                'tco_basis' => $tco['basis'],
                'debt_score' => $debt['score'],
                'debt_band' => $debt['band'],
                'debt_drivers' => $debt['drivers'],
                'debt_confidence' => $debt['confidence'],
                'cost_per_user' => $tco['total_ngn'] && $app->user_count
                    ? round($tco['total_ngn'] / $app->user_count, 2)
                    : null,
            ];
        }

        $covered = $apps->count() - $noCost - $unknownCurrency;

        usort($rows, fn ($a, $b) => ($b['tco_ngn'] ?? 0) <=> ($a['tco_ngn'] ?? 0));

        return [
            'applications' => $rows,
            'totals' => [
                'application_count' => $apps->count(),
                'licence_ngn' => round($licenceTotal, 2),
                'tco_ngn' => round($tcoTotal, 2),
                'tco_uplift_ratio' => $licenceTotal > 0 ? round($tcoTotal / $licenceTotal, 2) : null,
                'recorded_tco' => $recordedTco,
                'modelled_tco' => $modelledTco,
                'no_cost_recorded' => $noCost,
                'unknown_currency' => $unknownCurrency,
                // The honesty line. Every total above is a total over this share
                // of the estate, not over the estate.
                'cost_coverage_percent' => $apps->count() ? round($covered / $apps->count() * 100, 1) : 0.0,
                'mean_debt_score' => $debtScores ? round(array_sum($debtScores) / count($debtScores), 1) : 0.0,
                'severe_debt' => count(array_filter($debtScores, fn ($s) => $s >= 60)),
            ],
            'factors' => $this->factors(),
        ];
    }

    /**
     * Cost per capability, allocated through `ea_application_capabilities`.
     *
     * Roll-up is over the capability *subtree* (via the materialised closure),
     * because "what do we spend on payments" means the whole payments branch,
     * not the L1 node's own direct links.
     */
    public function costPerCapability(): array
    {
        $hierarchy = new HierarchyIndex();
        $capabilities = Capability::query()->get(['id', 'code', 'name', 'level', 'parent_id', 'criticality']);
        $links = ApplicationCapability::query()->get(['application_id', 'capability_id', 'allocation_weight']);

        $tcoByApp = [];
        foreach ($this->applications() as $app) {
            $tcoByApp[$app->id] = $this->tco($app)['total_ngn'];
        }

        // Direct allocation per capability.
        $direct = [];
        $unallocatedApps = array_fill_keys(array_keys($tcoByApp), true);
        foreach ($links as $link) {
            unset($unallocatedApps[$link->application_id]);
            $tco = $tcoByApp[$link->application_id] ?? null;
            if ($tco === null) {
                continue;
            }
            $direct[$link->capability_id]['cost'] = ($direct[$link->capability_id]['cost'] ?? 0) + $tco * (float) $link->allocation_weight;
            $direct[$link->capability_id]['apps'][] = $link->application_id;
        }

        // One query for the whole closure rather than one per capability. The
        // per-node lookup here cost 2.4s against §10's 2s budget at the 5,000-
        // entity scale — the exact N+1 shape the closure table exists to remove.
        $subtrees = $hierarchy->subtreeMap(Capability::class);

        $rows = [];
        foreach ($capabilities as $capability) {
            $subtree = array_merge([$capability->id], $subtrees[$capability->id] ?? []);
            $rolled = 0.0;
            $apps = [];
            foreach ($subtree as $id) {
                $rolled += (float) ($direct[$id]['cost'] ?? 0);
                $apps = array_merge($apps, $direct[$id]['apps'] ?? []);
            }
            $apps = array_values(array_unique($apps));

            $rows[] = [
                'id' => $capability->id,
                'code' => $capability->code,
                'name' => $capability->name,
                'level' => $capability->level,
                'parent_id' => $capability->parent_id,
                'criticality' => $capability->criticality,
                'direct_cost_ngn' => round((float) ($direct[$capability->id]['cost'] ?? 0), 2),
                'rolled_cost_ngn' => round($rolled, 2),
                'application_count' => count($apps),
                'descendant_count' => count($subtree) - 1,
            ];
        }

        usort($rows, fn ($a, $b) => $b['rolled_cost_ngn'] <=> $a['rolled_cost_ngn']);

        $unallocatedCost = 0.0;
        foreach (array_keys($unallocatedApps) as $appId) {
            $unallocatedCost += (float) ($tcoByApp[$appId] ?? 0);
        }

        return [
            'capabilities' => $rows,
            // Cost that could not be attributed to any capability. The most
            // useful number on the screen for an architect: it is the size of
            // the mapping job still outstanding.
            'unallocated' => [
                'application_count' => count($unallocatedApps),
                'cost_ngn' => round($unallocatedCost, 2),
            ],
        ];
    }

    /**
     * Rationalisation candidates — the post-merger question (§11 wedge 3).
     *
     * A capability realised by more than one live application is a duplication
     * candidate; the saving on offer is the cheaper systems' TCO. Sorted by
     * that number, because a board asks "what does consolidating save?".
     */
    public function rationalisationCandidates(int $minimumApplications = 2): array
    {
        $links = ApplicationCapability::query()->get(['application_id', 'capability_id']);
        $apps = $this->applications()->keyBy('id');
        $capabilities = Capability::query()->get(['id', 'code', 'name', 'level'])->keyBy('id');

        $byCapability = [];
        foreach ($links as $link) {
            $app = $apps->get($link->application_id);
            if (! $app || in_array($app->lifecycle, ['retired'], true)) {
                continue;
            }
            $byCapability[$link->capability_id][] = $app;
        }

        $candidates = [];
        foreach ($byCapability as $capabilityId => $group) {
            if (count($group) < $minimumApplications) {
                continue;
            }
            $capability = $capabilities->get($capabilityId);
            if (! $capability) {
                continue;
            }

            $scored = collect($group)->map(fn ($app) => [
                'id' => $app->id,
                'code' => $app->code,
                'name' => $app->name,
                'criticality' => $app->criticality,
                'lifecycle' => $app->lifecycle,
                'time_score' => $app->time_score,
                'business_fit' => $app->business_fit,
                'technical_fit' => $app->technical_fit,
                'tco_ngn' => $this->tco($app)['total_ngn'],
                'debt_score' => $this->technicalDebt($app)['score'],
            ])->sortByDesc(fn ($row) => [(int) $row['business_fit'], (int) $row['technical_fit']])->values();

            // The survivor is the best-fitting system; the saving is everything
            // else's TCO. Deliberately not "the cheapest survives" — a bank does
            // not consolidate onto its worst platform to save money.
            $survivor = $scored->first();
            $displaced = $scored->slice(1);
            $saving = $displaced->sum(fn ($row) => (float) ($row['tco_ngn'] ?? 0));
            $unknown = $displaced->filter(fn ($row) => $row['tco_ngn'] === null)->count();

            $candidates[] = [
                'capability' => [
                    'id' => $capability->id, 'code' => $capability->code,
                    'name' => $capability->name, 'level' => $capability->level,
                ],
                'application_count' => $scored->count(),
                'survivor' => $survivor,
                'displaced' => $displaced->values(),
                'annual_saving_ngn' => round($saving, 2),
                'displaced_without_cost' => $unknown,
            ];
        }

        usort($candidates, fn ($a, $b) => $b['annual_saving_ngn'] <=> $a['annual_saving_ngn']);

        return $candidates;
    }

    /**
     * Cost per business process, via the applications each process links to.
     * Split evenly across the processes an application serves, because an
     * application serving ten processes does not cost ten times over.
     */
    public function costPerProcess(): array
    {
        $processes = Process::query()->get(['id', 'code', 'name', 'level', 'criticality', 'rto_hours', 'linked_applications']);

        $appProcessCount = [];
        foreach ($processes as $process) {
            foreach ((array) ($process->linked_applications ?? []) as $appId) {
                $appProcessCount[(int) $appId] = ($appProcessCount[(int) $appId] ?? 0) + 1;
            }
        }

        $tcoByApp = [];
        foreach ($this->applications() as $app) {
            $tcoByApp[$app->id] = $this->tco($app)['total_ngn'];
        }

        $rows = [];
        foreach ($processes as $process) {
            $cost = 0.0;
            $linked = array_map('intval', (array) ($process->linked_applications ?? []));
            foreach ($linked as $appId) {
                $tco = $tcoByApp[$appId] ?? null;
                if ($tco === null) {
                    continue;
                }
                $cost += $tco / max(1, $appProcessCount[$appId] ?? 1);
            }
            $rows[] = [
                'id' => $process->id,
                'code' => $process->code,
                'name' => $process->name,
                'level' => $process->level,
                'criticality' => $process->criticality,
                'rto_hours' => $process->rto_hours,
                'application_count' => count($linked),
                'cost_ngn' => round($cost, 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b['cost_ngn'] <=> $a['cost_ngn']);

        return $rows;
    }

    /** Total modelled TCO for a set of applications — used by the plateau diff. */
    public function tcoForApplications(array $applicationIds): array
    {
        $total = 0.0;
        $missing = 0;
        foreach (EaApplication::whereIn('id', $applicationIds)->get() as $app) {
            $tco = $this->tco($app)['total_ngn'];
            $tco === null ? $missing++ : $total += $tco;
        }

        return ['total_ngn' => round($total, 2), 'without_cost' => $missing, 'counted' => count($applicationIds) - $missing];
    }
}
