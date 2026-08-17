<?php

namespace App\Services\Ea;

use App\Models\Ea\ApplicationInstance;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ResidencyService — ATH-EAR-002 A2, §8.3: "Localisation gap computation;
 * cross-border register; sovereign readiness."
 *
 * §6.3 A2 market condition: the circular of **15 June 2026** requires all
 * institutions facilitating Nigerian payments to store and manage domestically
 * generated transaction data **within Nigeria**, with compliance by **1 January
 * 2027**. "It affects **primary and disaster recovery infrastructure**, forces
 * revision of outsourcing and cloud contracts, and prohibits cross-border
 * transfer of Nigerian payment transaction data."
 *
 * Plus NDPA/GAID, which requires Commission approval for SCCs/BCRs and permits
 * consent-based transfer "only where connected to jural or fiduciary
 * obligations — materially stricter than GDPR practice. Two independent regimes
 * constraining where data may sit."
 *
 * "Why nobody else builds it. LeanIX and Ardoq model 'region' as a tag. Neither
 * models a *legal* residency obligation with a deadline, a basis and an
 * approval reference."
 */
class ResidencyService
{
    /**
     * The localisation deadline. Configurable rather than hard-coded: §12.1 R3
     * flags that the enforcement picture may shift, and a date compiled into
     * code is a date nobody can correct without a deploy.
     */
    public function deadline(): Carbon
    {
        return Carbon::parse(config('ea.localisation.deadline', '2027-01-01'));
    }

    public function daysToDeadline(): int
    {
        return (int) round(now()->diffInDays($this->deadline(), false));
    }

    /**
     * Report 1 — the localisation gap register.
     *
     * §6.3 A2: "every system holding Nigerian payment transaction data whose
     * primary *or DR* site is outside Nigeria, with days-to-deadline,
     * remediation owner and linked migration initiative."
     *
     * The "or DR" is the clause that catches people out: a compliant primary
     * with an offshore DR site is still a breach.
     */
    public function gapRegister(): array
    {
        $rows = [];
        $ownership = new OwnershipService();
        $days = $this->daysToDeadline();

        foreach ($this->scopedInstances() as $deployment) {
            if (! $deployment['breaches']) {
                continue;
            }

            $owner = $ownership
                ->approvers(EaApplication::class, $deployment['application_id'])
                ->first();

            $rows[] = [
                'application_id' => $deployment['application_id'],
                'instance_id' => $deployment['instance_id'],
                'label' => $deployment['label'],
                'legal_entity' => $deployment['legal_entity'],
                'criticality' => $deployment['criticality'],
                'hosting_country' => $deployment['hosting_country'],
                'hosting_site' => $deployment['hosting_site'],
                'dr_country' => $deployment['dr_country'],
                'dr_site' => $deployment['dr_site'],
                'reason' => $deployment['reason'],
                'days_to_deadline' => $days,
                'owner' => $owner?->user?->name,
                'owner_email' => $owner?->user?->email,
                'remediation_initiative' => $this->linkedInitiative($deployment['application_id']),
                // Severity is a function of criticality and time remaining —
                // a critical system with 90 days left is a different
                // conversation from a low one with 400.
                'severity' => $this->severity($deployment['criticality'], $days),
            ];
        }

        usort($rows, fn ($a, $b) => [$this->severityRank($b['severity'])] <=> [$this->severityRank($a['severity'])]);

        return $rows;
    }

    private function severity(string $criticality, int $days): string
    {
        if ($days < 0) {
            return 'breach';
        }

        return match (true) {
            $days <= 90 && in_array($criticality, ['critical', 'high'], true) => 'critical',
            $days <= 180 && $criticality === 'critical' => 'critical',
            $days <= 180 => 'high',
            default => 'medium',
        };
    }

    private function severityRank(string $severity): int
    {
        return ['breach' => 4, 'critical' => 3, 'high' => 2, 'medium' => 1][$severity] ?? 0;
    }

    /**
     * Report 2 — the cross-border flow map.
     *
     * §6.3 A2: "every data flow crossing a national boundary, its basis, and
     * whether the basis is approved."
     *
     * NDPA/GAID requires Commission approval for SCCs and BCRs, so an
     * unapproved basis is not a technicality — it is the finding.
     */
    public function crossBorderFlows(): array
    {
        $flows = DataFlow::with(['source:id,code,name', 'target:id,code,name'])
            ->where(function ($q) {
                $q->where('cross_border', true)
                    ->orWhereColumn('source_country', '!=', 'destination_country');
            })
            ->get();

        return $flows->map(function ($flow) {
            $basis = $flow->transfer_basis;
            $needsApproval = in_array($basis, ['scc', 'bcr'], true);
            $approved = $flow->transfer_approval_reference !== null && $flow->transfer_approval_reference !== '';

            return [
                'id' => $flow->id,
                'name' => $flow->name,
                'source' => $flow->source?->name,
                'target' => $flow->target?->name,
                'source_country' => $flow->source_country,
                'destination_country' => $flow->destination_country,
                'classification' => $flow->classification,
                'contains_nigerian_payment_data' => (bool) $flow->contains_nigerian_payment_data,
                'transfer_basis' => $basis,
                'transfer_basis_label' => $this->basisLabel($basis),
                'approval_reference' => $flow->transfer_approval_reference,
                // Three distinct failure modes, deliberately not collapsed:
                // no basis at all, a basis needing approval that has none, and
                // a payment-data flow that the directive prohibits outright.
                'status' => match (true) {
                    (bool) $flow->contains_nigerian_payment_data => 'prohibited',
                    $basis === null || $basis === 'none' => 'no_basis',
                    $needsApproval && ! $approved => 'awaiting_approval',
                    default => 'documented',
                },
            ];
        })->sortBy(fn ($f) => ['prohibited' => 0, 'no_basis' => 1, 'awaiting_approval' => 2, 'documented' => 3][$f['status']])
            ->values()->all();
    }

    private function basisLabel(?string $basis): string
    {
        return match ($basis) {
            'adequacy' => 'Adequacy decision',
            'scc' => 'Standard contractual clauses (Commission approval required)',
            'bcr' => 'Binding corporate rules (Commission approval required)',
            'consent' => 'Consent — valid only where connected to a jural or fiduciary obligation',
            'jural_obligation' => 'Jural or fiduciary obligation',
            'none' => 'No basis recorded',
            default => 'Not recorded',
        };
    }

    /**
     * Report 3 — sovereign readiness.
     *
     * §6.3 A2: "which workloads can move to which in-country site, by capacity
     * and tier."
     *
     * ⚠️ §12.1 R3 flags the open question this depends on: whether any
     * hyperscaler operates an in-country Nigerian region. The report therefore
     * ranks the in-country sites actually recorded rather than assuming a cloud
     * target exists.
     */
    public function sovereignReadiness(): array
    {
        $candidates = Site::where('country', 'NG')->get()
            ->map(fn ($site) => [
                'site_id' => $site->id,
                'name' => $site->name,
                'operator' => $site->operator,
                'city' => $site->city,
                'tier' => $site->tia942_tier,
                'autonomy_hours' => $site->outageAutonomyHours(),
                'resilience_score' => $site->resilienceScore(),
                'resilience_band' => $site->resilienceBand(),
                'current_load' => EaApplication::where('hosting_site_id', $site->id)->count()
                    + ApplicationInstance::where('hosting_site_id', $site->id)->count(),
                'connectivity' => count($site->connectivity_providers ?? []),
            ])
            ->sortByDesc('resilience_score')
            ->values()->all();

        $needingRelocation = collect($this->gapRegister());

        return [
            'in_country_sites' => $candidates,
            'workloads_to_relocate' => $needingRelocation->count(),
            'critical_to_relocate' => $needingRelocation->whereIn('criticality', ['critical', 'high'])->count(),
            'tier_iii_plus_capacity' => collect($candidates)->filter(fn ($s) => (int) $s['tier'] >= 3)->count(),
            'days_to_deadline' => $this->daysToDeadline(),
        ];
    }

    /** Headline numbers for the control tower. */
    public function summary(): array
    {
        $deployments = $this->scopedInstances();
        $paymentData = $deployments->where('contains_payment_data', true);
        $gaps = $paymentData->where('breaches', true);
        $flows = collect($this->crossBorderFlows());

        return [
            'days_to_deadline' => $this->daysToDeadline(),
            'deadline' => $this->deadline()->toDateString(),
            'total_deployments' => $deployments->count(),
            'payment_data_systems' => $paymentData->count(),
            'localisation_gaps' => $gaps->count(),
            'critical_gaps' => $gaps->whereIn('criticality', ['critical', 'high'])->count(),
            'dr_only_gaps' => $gaps->filter(fn ($d) => $d['hosting_country'] === 'NG')->count(),
            'cross_border_flows' => $flows->count(),
            'flows_without_basis' => $flows->where('status', 'no_basis')->count(),
            'flows_awaiting_approval' => $flows->where('status', 'awaiting_approval')->count(),
            'prohibited_flows' => $flows->where('status', 'prohibited')->count(),
            'unknown_residency' => $deployments->whereNull('hosting_country')->count(),
        ];
    }

    /**
     * Every deployment with its residency, from instances where they exist and
     * applications otherwise.
     */
    private function scopedInstances(): Collection
    {
        return $this->cache ??= $this->buildScope();
    }

    private ?Collection $cache = null;

    private function buildScope(): Collection
    {
        $sites = Site::all()->keyBy('id');

        // collect(...) downgrades the Eloquent collection to a base one:
        // Eloquent\Collection::map preserves its own class, and merging plain
        // arrays into it makes merge() call getKey() on an array.
        $instances = collect(ApplicationInstance::with(['application:id,code,name', 'legalEntity:id,name,jurisdiction'])
            ->get()
            ->all())
            ->map(fn ($i) => [
                'application_id' => $i->application_id,
                'instance_id' => $i->id,
                'label' => $i->name ?: (($i->application?->code ?? '').' — '.$i->code),
                'legal_entity' => $i->legalEntity?->name,
                'criticality' => $i->criticality,
                'hosting_country' => $i->hosting_country,
                'hosting_site' => $sites->get($i->hosting_site_id)?->name,
                'dr_country' => $i->dr_country,
                'dr_site' => $sites->get($i->dr_site_id)?->name,
                'contains_payment_data' => (bool) $i->contains_nigerian_payment_data,
                'breaches' => $i->breachesLocalisation(),
                'reason' => $i->localisationBreachReason(),
            ]);

        $covered = $instances->pluck('application_id')->unique()->flip();

        $applications = collect(EaApplication::query()
            ->get(['id', 'code', 'name', 'criticality', 'hosting_country', 'hosting_site_id',
                'dr_country', 'dr_site_id', 'contains_nigerian_payment_data', 'legal_entity_id'])
            ->all())
            ->reject(fn ($a) => $covered->has($a->id))
            ->map(function ($a) use ($sites) {
                $breaches = $a->contains_nigerian_payment_data
                    && ($a->hosting_country !== 'NG' || ($a->dr_country !== null && $a->dr_country !== 'NG'));

                return [
                    'application_id' => $a->id,
                    'instance_id' => null,
                    'label' => "{$a->code} — {$a->name}",
                    'legal_entity' => null,
                    'criticality' => $a->criticality,
                    'hosting_country' => $a->hosting_country,
                    'hosting_site' => $sites->get($a->hosting_site_id)?->name,
                    'dr_country' => $a->dr_country,
                    'dr_site' => $sites->get($a->dr_site_id)?->name,
                    'contains_payment_data' => (bool) $a->contains_nigerian_payment_data,
                    'breaches' => $breaches,
                    'reason' => $breaches
                        ? ($a->hosting_country !== 'NG'
                            ? 'Primary is outside Nigeria ('.($a->hosting_country ?: 'unknown').')'
                            : 'DR is outside Nigeria ('.($a->dr_country ?: 'unknown').')')
                        : null,
                ];
            });

        return $instances->merge($applications)->values();
    }

    /** An in-flight initiative naming this application counts as remediation. */
    private function linkedInitiative(int $applicationId): ?string
    {
        $application = EaApplication::find($applicationId);

        if (! $application) {
            return null;
        }

        return \App\Models\Ea\Initiative::whereIn('status', ['approved', 'in_flight'])
            ->where(fn ($q) => $q->where('name', 'like', '%'.$application->name.'%')
                ->orWhere('description', 'like', '%'.$application->name.'%'))
            ->value('name');
    }
}
