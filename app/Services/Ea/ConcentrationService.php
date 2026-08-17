<?php

namespace App\Services\Ea;

use App\Models\Ea\Capability;
use App\Models\Ea\Channel;
use App\Models\Ea\EaApplication;
use App\Models\Vendor;
use Illuminate\Support\Collection;

/**
 * ConcentrationService — ATH-EAR-002 A4, §8.3: "Extend
 * `VendorConcentrationService`: HHI index, integrator dimension, SPOF register,
 * substitutability."
 *
 * §6.3 A4 market condition: "**CWG Plc is the sole Nigerian distributor of
 * Infosys Finacle** and serves First Bank, GTBank, UBA, Fidelity, Stanbic IBTC,
 * FCMB and Wema; an investor cited in reporting states CWG is *'used by 60% of
 * Nigerian banks.'* Finacle and Flexcube are a near-duopoly at tier 1. The CBN
 * cybersecurity framework §2.3 and App. II §1.4 explicitly require banks to
 * manage third-party concentration — and **no bank can currently visualise
 * it**."
 *
 * The crucial insight §6.3 draws out: "the CWG case is a *services*
 * concentration, which is invisible if you only model the software publisher."
 * Hence `Vendor.role` — publisher, integrator, hosting, managed service,
 * connectivity — as a first-class dimension.
 */
class ConcentrationService
{
    public const ROLES = [
        'publisher' => 'Software publisher',
        'integrator' => 'Systems integrator',
        'hosting' => 'Hosting / data centre',
        'managed_service' => 'Managed service',
        'connectivity' => 'Connectivity / telco',
    ];

    /**
     * Herfindahl–Hirschman Index over a set of shares.
     *
     * The competition-authority convention: sum of squared percentage shares.
     * Above 2,500 is "highly concentrated", 1,500–2,500 "moderately". Using the
     * standard scale rather than inventing one means the number means something
     * to a risk committee that has seen it before.
     */
    public function hhi(Collection $shares): float
    {
        $total = $shares->sum();

        if ($total <= 0) {
            return 0.0;
        }

        return round($shares->reduce(fn ($carry, $v) => $carry + (($v / $total) * 100) ** 2, 0.0), 1);
    }

    public function hhiBand(float $hhi): string
    {
        return match (true) {
            $hhi >= 2500 => 'highly_concentrated',
            $hhi >= 1500 => 'moderately_concentrated',
            $hhi > 0 => 'unconcentrated',
            default => 'no_data',
        };
    }

    /**
     * Report 1 — the concentration heat map.
     *
     * §6.3 A4: "across vendor → application → business capability → value
     * stream, with an HHI-style concentration index per capability."
     */
    public function byCapability(): array
    {
        $applications = EaApplication::whereNotNull('vendor_id')
            ->get(['id', 'name', 'vendor_id', 'criticality', 'capability_ids', 'annual_cost_ngn']);

        $vendors = Vendor::get(['id', 'name', 'role', 'substitutability_score'])->keyBy('id');
        $capabilities = Capability::get(['id', 'code', 'name'])->keyBy('id');

        $rows = [];

        foreach ($capabilities as $capability) {
            $supporting = $applications->filter(
                fn ($a) => in_array($capability->id, $a->capability_ids ?? [], true)
            );

            if ($supporting->isEmpty()) {
                continue;
            }

            $byVendor = $supporting->groupBy('vendor_id')->map->count();
            $hhi = $this->hhi($byVendor);
            $topVendorId = $byVendor->sortDesc()->keys()->first();

            $rows[] = [
                'capability_id' => $capability->id,
                'capability' => $capability->name,
                'applications' => $supporting->count(),
                'critical_applications' => $supporting->whereIn('criticality', ['critical', 'high'])->count(),
                'vendors' => $byVendor->count(),
                'hhi' => $hhi,
                'band' => $this->hhiBand($hhi),
                'top_vendor' => $vendors->get($topVendorId)?->name,
                'top_vendor_role' => $vendors->get($topVendorId)?->role,
                'top_vendor_share' => $supporting->count()
                    ? round(($byVendor[$topVendorId] ?? 0) / $supporting->count() * 100, 1)
                    : 0.0,
                'annual_spend_ngn' => round((float) $supporting->sum('annual_cost_ngn'), 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b['hhi'] <=> $a['hhi']);

        return $rows;
    }

    /**
     * Report 2 — concentration by vendor role.
     *
     * The dimension that surfaces the CWG case. A bank looking only at software
     * publishers sees Infosys and Oracle and concludes it is diversified; the
     * services layer underneath may be a single firm.
     */
    public function byRole(): array
    {
        $applications = EaApplication::whereNotNull('vendor_id')
            ->get(['id', 'vendor_id', 'criticality', 'annual_cost_ngn']);

        $vendors = Vendor::get(['id', 'name', 'role', 'origin_country', 'substitutability_score', 'in_market_alternatives']);

        $rows = [];

        foreach (self::ROLES as $role => $label) {
            $roleVendors = $vendors->where('role', $role);

            if ($roleVendors->isEmpty()) {
                continue;
            }

            $counts = $roleVendors->mapWithKeys(function ($vendor) use ($applications) {
                return [$vendor->id => $applications->where('vendor_id', $vendor->id)->count()];
            })->filter(fn ($c) => $c > 0);

            if ($counts->isEmpty()) {
                continue;
            }

            $hhi = $this->hhi($counts);
            $topId = $counts->sortDesc()->keys()->first();
            $top = $roleVendors->firstWhere('id', $topId);
            $totalApps = $counts->sum();

            $rows[] = [
                'role' => $role,
                'role_label' => $label,
                'vendors' => $counts->count(),
                'applications' => $totalApps,
                'hhi' => $hhi,
                'band' => $this->hhiBand($hhi),
                'top_vendor' => $top?->name,
                'top_vendor_share' => $totalApps ? round($counts[$topId] / $totalApps * 100, 1) : 0.0,
                'top_vendor_origin' => $top?->origin_country,
                'top_vendor_alternatives' => $top?->in_market_alternatives,
            ];
        }

        usort($rows, fn ($a, $b) => $b['hhi'] <=> $a['hhi']);

        return $rows;
    }

    /**
     * Report 3 — the single-point-of-failure register.
     *
     * §6.3 A4: "capabilities where one vendor or one integrator supports >X% of
     * critical applications."
     */
    public function spofRegister(float $threshold = 60.0): array
    {
        $register = [];

        foreach ($this->byCapability() as $row) {
            if ($row['critical_applications'] === 0) {
                continue;
            }
            if ($row['top_vendor_share'] < $threshold) {
                continue;
            }

            $register[] = [
                'capability' => $row['capability'],
                'vendor' => $row['top_vendor'],
                'role' => $row['top_vendor_role'],
                'share' => $row['top_vendor_share'],
                'critical_applications' => $row['critical_applications'],
                'annual_spend_ngn' => $row['annual_spend_ngn'],
                'severity' => match (true) {
                    $row['top_vendor_share'] >= 90 => 'critical',
                    $row['top_vendor_share'] >= 75 => 'high',
                    default => 'medium',
                },
            ];
        }

        // The channel layer has its own single points of failure — a telco that
        // can switch off USSD is a concentration risk with no software in it.
        foreach (Channel::with(['telco', 'aggregator'])->get() as $channel) {
            if (! $channel->isSuspensionExposed()) {
                continue;
            }

            $register[] = [
                'capability' => $channel->typeLabel().' channel',
                'vendor' => $channel->telco?->name ?: $channel->aggregator?->name ?: 'Unnamed third party',
                'role' => 'connectivity',
                'share' => 100.0,
                'critical_applications' => 1,
                'annual_spend_ngn' => 0.0,
                'severity' => 'critical',
                'note' => $channel->suspension_risk_notes
                    ?: 'This third party can unilaterally suspend the channel.',
            ];
        }

        return $register;
    }

    /**
     * Report 4 — the reverse view for the Vendor page.
     *
     * §7.3 I-5 and §6.3 A4: "dependent applications, capabilities and business
     * services (the TPRM officer's screen today shows nothing of this)."
     */
    public function forVendor(int $vendorId): array
    {
        $vendor = Vendor::find($vendorId);

        if (! $vendor) {
            return [];
        }

        $applications = EaApplication::where('vendor_id', $vendorId)
            ->get(['id', 'code', 'name', 'criticality', 'lifecycle', 'annual_cost_ngn', 'capability_ids']);

        $capabilityIds = $applications->flatMap(fn ($a) => $a->capability_ids ?? [])->unique();

        $channels = Channel::where('telco_vendor_id', $vendorId)
            ->orWhere('aggregator_vendor_id', $vendorId)
            ->get(['id', 'name', 'channel_type', 'criticality', 'third_party_can_suspend']);

        return [
            'vendor' => [
                'id' => $vendor->id,
                'name' => $vendor->name,
                'role' => $vendor->role,
                'role_label' => self::ROLES[$vendor->role] ?? null,
                'origin_country' => $vendor->origin_country,
                'substitutability_score' => $vendor->substitutability_score,
                'in_market_alternatives' => $vendor->in_market_alternatives,
                'switching_notes' => $vendor->switching_notes,
            ],
            'applications' => $applications->map(fn ($a) => [
                'id' => $a->id,
                'label' => "{$a->code} — {$a->name}",
                'criticality' => $a->criticality,
                'lifecycle' => $a->lifecycle,
                'annual_cost_ngn' => (float) $a->annual_cost_ngn,
            ])->values(),
            'critical_count' => $applications->whereIn('criticality', ['critical', 'high'])->count(),
            'annual_spend_ngn' => round((float) $applications->sum('annual_cost_ngn'), 2),
            'capabilities' => Capability::whereIn('id', $capabilityIds)->get(['id', 'code', 'name']),
            'channels' => $channels,
            'can_suspend_channel' => $channels->where('third_party_can_suspend', true)->count() > 0,
        ];
    }

    /** Headline figures for the concentration workspace. */
    public function summary(): array
    {
        $capabilities = collect($this->byCapability());
        $roles = collect($this->byRole());
        $spof = collect($this->spofRegister());

        return [
            'capabilities_assessed' => $capabilities->count(),
            'highly_concentrated' => $capabilities->where('band', 'highly_concentrated')->count(),
            'mean_hhi' => $capabilities->count() ? round($capabilities->avg('hhi'), 1) : 0.0,
            'spof_count' => $spof->count(),
            'critical_spof' => $spof->where('severity', 'critical')->count(),
            'roles_assessed' => $roles->count(),
            'most_concentrated_role' => $roles->first()['role_label'] ?? null,
            'vendors_without_role' => Vendor::whereNull('role')->count(),
        ];
    }
}
