<?php

namespace Database\Seeders;

use App\Models\Ea\ApplicationInstance;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\EaInterface;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Rail;
use App\Models\Ea\Relationship;
use App\Models\Ea\Site;
use App\Models\LegalEntity;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Demo data for the ATH-EAR-002 Phase 3 wedge features.
 *
 * The reference pack (NigerianReferenceArchitectureSeeder) ships market
 * structure — sites, rails, switches, integrators. This seeder wires a
 * *tenant's estate* onto it: residency, cost and currency, vendor links,
 * multi-entity instances and transfer bases, so the four wedge lenses and the
 * returns engine have something real to compute over.
 *
 * Deliberately imperfect, for the same reason the stewardship seeder is: a demo
 * where every system is compliant, owned and in-country demonstrates nothing.
 * §6.3's whole argument is that these reports surface gaps nobody can currently
 * see, so the seed contains gaps to surface — including the DR-offshore trap,
 * which is the one banks most often miss.
 */
class EaWedgeDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (EaApplication::count() === 0) {
            $this->command?->warn('[EA] No applications — skipping wedge demo seed.');

            return;
        }

        $this->assignResidencyAndCost();
        $this->assignVendors();
        $this->createInstances();
        $this->classifyData();
        $this->linkInterfacesToRails();

        $this->command?->info('[EA] Phase 3 wedge demo data seeded.');
    }

    /**
     * Residency, cost and currency across the estate.
     *
     * The currency split reflects §6.3 A3's market condition: core banking is
     * dollar-priced and is the dominant line item, while indigenous platforms
     * are naira-priced — which is exactly why the FX lens sells to the CFO.
     */
    private function assignResidencyAndCost(): void
    {
        $nigerianSites = Site::where('country', 'NG')->get();
        $lagos = $nigerianSites->where('city', 'Lagos')->values();
        $upcountry = $nigerianSites->where('city', '!=', 'Lagos')->values();

        if ($nigerianSites->isEmpty()) {
            return;
        }

        // An offshore site, so the localisation gap has something to find.
        $offshore = Site::updateOrCreate(
            ['code' => 'OFF-LON', 'organization_id' => null],
            [
                'name' => 'London Colocation (group DR)',
                'operator' => 'Global colocation provider',
                'site_type' => 'colocation',
                'city' => 'London',
                'country' => 'GB',
                'tia942_tier' => 3,
                'generator_autonomy_hours' => 48,
                'grid_reliability_band' => 'high',
                'flood_risk' => 'low',
                'is_sovereign' => false,
                'notes' => 'Demo data — the offshore DR site the localisation directive catches.',
            ],
        );

        // Payment-data scope is decided first, then residency shapes are dealt
        // across that subset explicitly. Deriving both from modulo arithmetic
        // on the same index made the two conditions nearly disjoint, so the
        // gap register came out near-empty for arithmetic reasons rather than
        // architectural ones — and the DR-offshore case, which §6.3 A2 calls
        // the most commonly missed half of the requirement, never appeared.
        $applications = EaApplication::orderBy('id')->get()->values();

        $isPaymentSystem = function (EaApplication $application, int $i): bool {
            $name = strtolower($application->name);

            return str_contains($name, 'core') || str_contains($name, 'finacle')
                || str_contains($name, 'flexcube') || str_contains($name, 'payment')
                || str_contains($name, 'card') || str_contains($name, 'switch')
                || str_contains($name, 'nibss') || $i % 2 === 0;
        };

        $paymentIndex = 0;

        foreach ($applications as $i => $application) {
            $isCore = str_contains(strtolower($application->name), 'core')
                || str_contains(strtolower($application->name), 'finacle')
                || str_contains(strtolower($application->name), 'flexcube');

            $carriesPaymentData = $isPaymentSystem($application, $i);

            $primary = $lagos->isNotEmpty() ? $lagos[$i % $lagos->count()] : $nigerianSites->first();

            // Four shapes, dealt round-robin across the payment-data systems so
            // each is genuinely represented:
            //   0 → compliant, upcountry DR (the good case)
            //   1 → compliant primary, OFFSHORE DR (the trap)
            //   2 → offshore primary (the obvious breach)
            //   3 → in-country but both sites in Lagos (compliant on residency,
            //       concentrated on geography — A5's separate finding)
            $shape = $carriesPaymentData ? ($paymentIndex++ % 4) : 3;

            [$drSite, $drCountry] = match ($shape) {
                0 => [$upcountry->isNotEmpty() ? $upcountry[$i % $upcountry->count()] : null, 'NG'],
                1, 2 => [$offshore, 'GB'],
                default => [$lagos->isNotEmpty() ? $lagos[($i + 3) % $lagos->count()] : null, 'NG'],
            };

            $hostingSite = ($shape === 2) ? $offshore : $primary;
            $hostingCountry = ($shape === 2) ? 'GB' : 'NG';

            $currency = match (true) {
                $isCore => 'USD',
                $i % 7 === 0 => 'EUR',
                $i % 5 === 0 => 'GBP',
                $i % 2 === 0 => 'USD',
                default => 'NGN',
            };

            $nativeCost = match ($currency) {
                'USD' => $isCore ? random_int(2_000_000, 6_000_000) : random_int(40_000, 600_000),
                'EUR' => random_int(30_000, 250_000),
                'GBP' => random_int(25_000, 200_000),
                default => random_int(15_000_000, 400_000_000),
            };

            // Roughly one in five is an indigenous, naira-priced platform.
            $indigenous = $currency === 'NGN' && $i % 5 === 0;

            $application->forceFill([
                'hosting_country' => $hostingCountry,
                'hosting_site_id' => $hostingSite?->id,
                'hosting_model' => $i % 4 === 0 ? 'colocation' : ($i % 7 === 0 ? 'saas' : 'on_prem'),
                'dr_country' => $drCountry,
                'dr_site_id' => $drSite?->id,
                'replication_topology' => $i % 3 === 0 ? 'active_passive' : 'async',
                'contains_nigerian_payment_data' => $carriesPaymentData,
                'data_categories' => $carriesPaymentData
                    ? ['transaction_data', 'customer_pii']
                    : ['operational'],
                'annual_cost' => $nativeCost,
                'cost_currency' => $currency,
                'contract_end_date' => now()->addMonths(random_int(2, 30))->toDateString(),
                'renewal_notice_days' => [30, 60, 90, 180][$i % 4],
                'licence_model' => $isCore ? 'perpetual_plus_support' : ['subscription', 'perpetual', 'per_user'][$i % 3],
                'is_indigenous' => $indigenous,
            ])->save();
        }
    }

    /** Link applications to vendors so concentration can be computed. */
    private function assignVendors(): void
    {
        $publishers = Vendor::withoutGlobalScopes()->where('role', 'publisher')->get();
        $integrators = Vendor::withoutGlobalScopes()->where('role', 'integrator')->get();

        if ($publishers->isEmpty()) {
            return;
        }

        // The CWG shape from §6.3 A4: one integrator behind a large share of
        // the estate, invisible if only publishers are modelled.
        $dominantIntegrator = $integrators->firstWhere('name', 'CWG Plc') ?? $integrators->first();

        foreach (EaApplication::orderBy('id')->get()->values() as $i => $application) {
            $isCore = str_contains(strtolower($application->name), 'core');

            // Core banking concentrates on the two publishers that dominate
            // tier 1; everything else spreads more evenly.
            $vendor = $isCore
                ? ($publishers->firstWhere('name', 'Infosys Finacle') ?? $publishers->first())
                : $publishers[$i % $publishers->count()];

            $application->forceFill(['vendor_id' => $vendor->id])->save();

            // Two-thirds of the estate sits behind the dominant integrator.
            if ($dominantIntegrator && $i % 3 !== 0) {
                Relationship::firstOrCreate([
                    'source_type' => Vendor::class,
                    'source_id' => $dominantIntegrator->id,
                    'target_type' => EaApplication::class,
                    'target_id' => $application->id,
                    'relation_type' => 'serving',
                ], ['attrs' => ['role' => 'integrator']]);
            }
        }
    }

    /**
     * Multi-entity instances — A6.
     *
     * One logical application, several deployed instances, each with its own
     * residency and regulator set. The Ghana and UK instances are what make
     * "show me this subsidiary's estate against its own regulator" real.
     */
    private function createInstances(): void
    {
        if (ApplicationInstance::count() > 0) {
            return;
        }

        $entities = LegalEntity::whereNotNull('parent_id')->get();
        $sites = Site::all()->keyBy('code');

        if ($entities->isEmpty()) {
            return;
        }

        // Only the shared platforms get instances — a subsidiary rarely runs
        // its own copy of every back-office system.
        $shared = EaApplication::whereIn('criticality', ['critical', 'high'])->limit(6)->get();

        foreach ($shared as $application) {
            foreach ($entities as $entity) {
                $isNigeria = $entity->jurisdiction === 'NG';

                $hostingSite = $isNigeria
                    ? ($sites['RC-LOS-01'] ?? $sites->first())
                    : null;

                $drSite = $isNigeria ? ($sites['GBB-ABJ'] ?? null) : null;

                ApplicationInstance::create([
                    'organization_id' => $application->organization_id,
                    'application_id' => $application->id,
                    'legal_entity_id' => $entity->id,
                    'code' => $application->code.'-'.$entity->code,
                    'name' => $application->name.' — '.$entity->name,
                    'hosting_site_id' => $hostingSite?->id,
                    'hosting_country' => $entity->jurisdiction,
                    'hosting_model' => $isNigeria ? 'colocation' : 'public_cloud',
                    'dr_site_id' => $drSite?->id,
                    'dr_country' => $isNigeria ? 'NG' : $entity->jurisdiction,
                    'replication_topology' => 'async',
                    'criticality' => $application->criticality,
                    'environment' => 'production',
                    // Only the Nigerian entities hold Nigerian payment data.
                    'contains_nigerian_payment_data' => $isNigeria,
                    'data_categories' => $isNigeria ? ['transaction_data', 'customer_pii'] : ['customer_pii'],
                    'status' => 'active',
                ]);
            }
        }
    }

    /** NDPA classification, transfer bases and processors — the CAR inputs. */
    private function classifyData(): void
    {
        $processors = Vendor::withoutGlobalScopes()
            ->whereIn('role', ['hosting', 'managed_service'])->get();

        foreach (LogicalEntity::orderBy('id')->get()->values() as $i => $entity) {
            $isPii = (bool) $entity->pii_flag;

            $entity->forceFill([
                'ndpa_classification' => $isPii
                    ? ['personal_data', 'sensitive_personal_data'][$i % 2]
                    : 'non_personal',
                // Deliberately incomplete: roughly a quarter have no lawful
                // basis, which is what the CAR section reports as a gap.
                'lawful_basis' => $i % 4 === 3
                    ? null
                    : ['contract', 'legal_obligation', 'consent', 'legitimate_interest'][$i % 4],
                'retention_period_months' => $i % 5 === 4 ? null : [12, 24, 60, 84, 120][$i % 5],
                'processor_vendor_id' => ($isPii && $processors->isNotEmpty() && $i % 3 !== 2)
                    ? $processors[$i % $processors->count()]->id
                    : null,
            ])->save();
        }

        foreach (DataFlow::orderBy('id')->get()->values() as $i => $flow) {
            $crossBorder = $i % 4 === 0;

            $flow->forceFill([
                'cross_border' => $crossBorder,
                'source_country' => 'NG',
                'destination_country' => $crossBorder ? ['GB', 'US', 'ZA', 'GH'][$i % 4] : 'NG',
                // A spread of the four failure modes the register distinguishes.
                'transfer_basis' => $crossBorder
                    ? [null, 'scc', 'adequacy', 'consent'][$i % 4]
                    : null,
                'transfer_approval_reference' => ($crossBorder && $i % 8 === 2)
                    ? 'NDPC/TRF/2026/'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)
                    : null,
                // A prohibited flow — payment data crossing a border, which no
                // transfer basis cures.
                'contains_nigerian_payment_data' => $crossBorder && $i % 12 === 0,
            ])->save();
        }
    }

    /** Point interfaces at the rails they actually run over. */
    private function linkInterfacesToRails(): void
    {
        $rails = Rail::all();
        $railVendors = Vendor::withoutGlobalScopes()->whereIn('role', ['managed_service'])->get();

        if ($rails->isEmpty()) {
            return;
        }

        foreach (EaInterface::orderBy('id')->get()->values() as $i => $interface) {
            $isExternal = in_array($interface->counterparty_type, ['regulator', 'switch', 'third_party'], true);

            if (! $isExternal && $i % 4 !== 0) {
                continue;
            }

            $rail = $rails[$i % $rails->count()];

            $interface->forceFill([
                'rail_id' => $rail->id,
                'counterparty_vendor_id' => $railVendors->isNotEmpty()
                    ? $railVendors[$i % $railVendors->count()]->id
                    : null,
                'crosses_border' => $i % 11 === 0,
                'source_country' => 'NG',
                'destination_country' => $i % 11 === 0 ? 'GB' : 'NG',
            ])->save();
        }
    }
}
