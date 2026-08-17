<?php

namespace Tests\Feature\Ea;

use App\Models\Control;
use App\Models\Ea\ApplicationInstance;
use App\Models\Ea\Capability;
use App\Models\Ea\Channel;
use App\Models\Ea\DataFlow;
use App\Models\Ea\EaApplication;
use App\Models\Ea\Initiative;
use App\Models\Ea\LogicalEntity;
use App\Models\Ea\Process;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\Site;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\Returns\RegulatoryReturn;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ea\ConcentrationService;
use App\Services\Ea\FxExposureService;
use App\Services\Ea\OwnershipService;
use App\Services\Ea\QualitySealService;
use App\Services\Ea\ResidencyService;
use App\Services\Ea\SiteResilienceService;
use App\Services\Returns\ReturnCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ATH-EAR-002 Phase 3 exit criterion (§9):
 *
 *   "A design-partner bank can produce a CSAT pre-fill pack, an ITSB maturity
 *    assessment against its category target, an NDPC CAR extract and a
 *    localisation gap report — each with entity-level citations and
 *    evidence-quality indicators — from its own data."
 *
 * Plus the specific mechanics §6.3 specifies for A2–A7, and the §10 evidence
 * integrity gate: "Every generated return … hash-sealed, immutable once
 * signed."
 */
class EaAfricanWedgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private LegalEntity $entity;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->organization = Organization::factory()->create();

        $this->entity = LegalEntity::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'code' => 'NG-BANK',
            'name' => 'Nigeria Commercial Bank',
            'jurisdiction' => 'NG',
            'regulators' => ['CBN', 'NDPC'],
            'licence_class' => 'international',
            'capital_base_ngn' => 600_000_000_000,
        ]);
    }

    private function site(array $overrides = []): Site
    {
        return Site::withoutGlobalScopes()->forceCreate(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'SITE-'.fake()->unique()->numerify('####'),
            'name' => 'Rack Centre LGS1',
            'operator' => 'Rack Centre',
            'city' => 'Lagos',
            'country' => 'NG',
            'tia942_tier' => 3,
            'generator_autonomy_hours' => 72,
            'ups_autonomy_minutes' => 15,
            'grid_reliability_band' => 'high',
            'flood_risk' => 'medium',
            'grid_zone' => 'LOS-IKEJA',
            'connectivity_providers' => ['MainOne', 'MTN'],
        ], $overrides));
    }

    private function application(array $overrides = []): EaApplication
    {
        return EaApplication::withoutGlobalScopes()->forceCreate(array_merge([
            'organization_id' => $this->organization->id,
            'code' => 'APP-'.fake()->unique()->numerify('####'),
            'name' => 'Core Banking',
            'criticality' => 'critical',
            'lifecycle' => 'live',
            'owner_role' => 'Head of Core Banking',
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */
    /* A6 — Legal entities and the ITSB category target                    */
    /* ------------------------------------------------------------------ */

    public function test_the_itsb_maturity_target_follows_the_institution_category(): void
    {
        // §6.1 Finding 1: Category One (international commercial banks;
        // established commercial and merchant banks) → Level 3 "Defined";
        // Category Two (banks ≤18 months; payment system providers) → Level 2.
        $this->assertSame('one', $this->entity->itsbCategory());
        $this->assertSame(3, $this->entity->itsbTargetLevel());

        $psp = LegalEntity::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'code' => 'NG-PSP', 'name' => 'Payments Subsidiary',
            'jurisdiction' => 'NG', 'licence_class' => 'psp',
        ]);
        $this->assertSame('two', $psp->itsbCategory());
        $this->assertSame(2, $psp->itsbTargetLevel());

        // A UK subsidiary is outside the ITSB's scope entirely.
        $uk = LegalEntity::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'code' => 'UK-BANK', 'name' => 'UK Subsidiary',
            'jurisdiction' => 'GB', 'licence_class' => 'foreign_subsidiary',
            'regulators' => ['PRA', 'FCA'],
        ]);
        $this->assertNull($uk->itsbCategory());
    }

    public function test_a_subsidiary_inherits_group_regulators(): void
    {
        // §6.3 A6: a Nigerian group's UK subsidiary answers to the PRA and FCA
        // *and* to CBN at group level.
        $child = LegalEntity::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'code' => 'GH-BANK', 'name' => 'Ghana Subsidiary',
            'jurisdiction' => 'GH', 'regulators' => ['Bank of Ghana'],
            'parent_id' => $this->entity->id,
        ]);

        $this->assertEqualsCanonicalizing(
            ['Bank of Ghana', 'CBN', 'NDPC'],
            $child->effectiveRegulators(),
        );
    }

    /* ------------------------------------------------------------------ */
    /* A2 — Residency and the localisation directive                       */
    /* ------------------------------------------------------------------ */

    public function test_an_offshore_dr_site_is_a_localisation_breach_even_when_the_primary_is_compliant(): void
    {
        // §6.3 A2: the directive "affects **primary and disaster recovery
        // infrastructure**". This is the half banks most often miss.
        $lagos = $this->site();
        $london = $this->site(['code' => 'OFF-LON', 'name' => 'London DC', 'city' => 'London', 'country' => 'GB']);

        $instance = ApplicationInstance::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'application_id' => $this->application()->id,
            'legal_entity_id' => $this->entity->id,
            'code' => 'CORE-NG',
            'hosting_site_id' => $lagos->id,
            'hosting_country' => 'NG',
            'dr_site_id' => $london->id,
            'dr_country' => 'GB',
            'criticality' => 'critical',
            'contains_nigerian_payment_data' => true,
        ]);

        $this->assertTrue($instance->breachesLocalisation());
        $this->assertStringContainsString('DR is outside Nigeria', $instance->localisationBreachReason());

        $register = (new ResidencyService())->gapRegister();
        $this->assertCount(1, $register);
        $this->assertSame('NG', $register[0]['hosting_country']);
    }

    public function test_a_system_without_nigerian_payment_data_is_not_a_localisation_gap(): void
    {
        // The directive binds payment transaction data specifically. Flagging
        // every offshore system would bury the real finding.
        $london = $this->site(['code' => 'OFF-LON2', 'city' => 'London', 'country' => 'GB']);

        ApplicationInstance::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'application_id' => $this->application(['name' => 'HR System'])->id,
            'legal_entity_id' => $this->entity->id,
            'code' => 'HR-NG',
            'hosting_site_id' => $london->id,
            'hosting_country' => 'GB',
            'criticality' => 'low',
            'contains_nigerian_payment_data' => false,
        ]);

        $this->assertCount(0, (new ResidencyService())->gapRegister());
    }

    public function test_the_cross_border_register_distinguishes_prohibited_from_undocumented(): void
    {
        // §6.3 A2 and §6.1: an SCC needs Commission approval; a payment-data
        // flow across a border is prohibited outright and no basis cures it.
        $source = LogicalEntity::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id, 'code' => 'LE-1', 'name' => 'Customer', 'pii_flag' => true,
        ]);
        $target = LogicalEntity::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id, 'code' => 'LE-2', 'name' => 'Analytics', 'pii_flag' => true,
        ]);

        $flows = [
            ['Prohibited flow', true, null, null],
            ['No basis flow', false, null, null],
            ['Awaiting approval', false, 'scc', null],
            ['Documented flow', false, 'adequacy', 'NDPC/TRF/2026/0001'],
        ];

        foreach ($flows as [$name, $paymentData, $basis, $reference]) {
            DataFlow::withoutGlobalScopes()->forceCreate([
                'organization_id' => $this->organization->id,
                'source_entity_id' => $source->id,
                'target_entity_id' => $target->id,
                'name' => $name,
                'cross_border' => true,
                'source_country' => 'NG',
                'destination_country' => 'GB',
                'contains_nigerian_payment_data' => $paymentData,
                'transfer_basis' => $basis,
                'transfer_approval_reference' => $reference,
            ]);
        }

        $register = collect((new ResidencyService())->crossBorderFlows());

        $this->assertSame('prohibited', $register->firstWhere('name', 'Prohibited flow')['status']);
        $this->assertSame('no_basis', $register->firstWhere('name', 'No basis flow')['status']);
        $this->assertSame('awaiting_approval', $register->firstWhere('name', 'Awaiting approval')['status']);
        $this->assertSame('documented', $register->firstWhere('name', 'Documented flow')['status']);
    }

    /* ------------------------------------------------------------------ */
    /* A5 — Site resilience and geographic concentration                   */
    /* ------------------------------------------------------------------ */

    public function test_a_dr_pair_in_the_same_city_raises_a_critical_alert(): void
    {
        // §6.3 A5: 21 of Nigeria's 28 data centres are in Lagos, so "we have a
        // DR site" says very little on its own.
        $primary = $this->site(['code' => 'LOS-1', 'name' => 'Lagos A']);
        $dr = $this->site(['code' => 'LOS-2', 'name' => 'Lagos B']);

        $this->application([
            'hosting_site_id' => $primary->id,
            'dr_site_id' => $dr->id,
            'criticality' => 'critical',
        ]);

        $alerts = collect((new SiteResilienceService())->concentrationAlerts());
        $sameCity = $alerts->firstWhere('type', 'same_city');

        $this->assertNotNull($sameCity);
        $this->assertSame('critical', $sameCity['severity']);
    }

    public function test_generator_autonomy_outweighs_tier_in_the_resilience_score(): void
    {
        // §6.3 A5: "A DR architecture that ignores diesel is fiction in this
        // market." A Tier III site with four hours of fuel is less resilient in
        // practice than a Tier II with seventy-two.
        $tierThreeThinFuel = $this->site(['code' => 'T3', 'tia942_tier' => 3, 'generator_autonomy_hours' => 4]);
        $tierTwoDeepFuel = $this->site(['code' => 'T2', 'tia942_tier' => 2, 'generator_autonomy_hours' => 72]);

        $this->assertGreaterThan(
            $tierThreeThinFuel->resilienceScore(),
            $tierTwoDeepFuel->resilienceScore(),
        );
    }

    public function test_resilience_posture_compares_site_autonomy_against_the_bia_rto(): void
    {
        // Only possible because contract I-6 (Phase 2) made the RTO real rather
        // than seeded.
        $site = $this->site(['generator_autonomy_hours' => 4, 'ups_autonomy_minutes' => 0]);
        $application = $this->application(['hosting_site_id' => $site->id]);

        Process::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'BP-PAY', 'name' => 'Funds Transfer', 'level' => 2,
            'criticality' => 'critical', 'rto_hours' => 24,
            'linked_applications' => [$application->id],
        ]);

        $posture = collect((new SiteResilienceService())->postureAgainstBia());
        $row = $posture->firstWhere('process', 'Funds Transfer');

        $this->assertNotNull($row);
        $this->assertFalse($row['meets_rto']);
        $this->assertSame(20.0, $row['shortfall_hours']);
    }

    /* ------------------------------------------------------------------ */
    /* A3 — FX exposure                                                    */
    /* ------------------------------------------------------------------ */

    public function test_fx_exposure_normalises_foreign_currency_costs_into_naira(): void
    {
        config(['ea.fx.rates' => ['NGN' => 1.0, 'USD' => 1500.0]]);

        $this->application(['name' => 'Finacle', 'annual_cost' => 4_000_000, 'cost_currency' => 'USD']);
        $this->application(['name' => 'SeaBaaS', 'annual_cost' => 300_000_000, 'cost_currency' => 'NGN', 'is_indigenous' => true]);

        $dashboard = (new FxExposureService())->dashboard();

        // 4m USD × 1500 = 6bn NGN, plus 300m NGN = 6.3bn total.
        $this->assertSame(6_300_000_000.0, $dashboard['total_ngn']);
        $this->assertSame(6_000_000_000.0, $dashboard['foreign_ngn']);
        $this->assertSame(95.2, $dashboard['foreign_share']);
    }

    public function test_the_devaluation_stress_test_reprices_the_dollar_book(): void
    {
        // §6.3 A3: "at ₦X/$ our application cost base is ₦Y". Naira devaluation
        // nearly doubled dollar-priced core banking costs across 2023–24.
        config(['ea.fx.rates' => ['NGN' => 1.0, 'USD' => 1500.0]]);

        $this->application(['annual_cost' => 1_000_000, 'cost_currency' => 'USD']);

        $result = (new FxExposureService())->stressTest([1500, 3000]);

        $baseline = collect($result['scenarios'])->firstWhere('rate', 1500);
        $stressed = collect($result['scenarios'])->firstWhere('rate', 3000);

        $this->assertSame(1_500_000_000.0, $baseline['total_ngn']);
        $this->assertSame(3_000_000_000.0, $stressed['total_ngn']);
        $this->assertSame(100.0, $stressed['delta_percent']);
    }

    public function test_systems_with_no_recorded_currency_are_reported_not_assumed(): void
    {
        // A dollar-priced licence booked in naira hides the exposure entirely,
        // so the count of unknown-currency systems is itself a finding.
        $this->application(['annual_cost_ngn' => 50_000_000, 'cost_currency' => null]);

        $dashboard = (new FxExposureService())->dashboard();

        $this->assertSame(1, $dashboard['unknown_currency']);
    }

    /* ------------------------------------------------------------------ */
    /* A4 — Concentration                                                  */
    /* ------------------------------------------------------------------ */

    public function test_hhi_uses_the_competition_authority_scale(): void
    {
        $service = new ConcentrationService();

        // A monopoly is 100² = 10,000.
        $this->assertSame(10000.0, $service->hhi(collect([5])));
        $this->assertSame('highly_concentrated', $service->hhiBand(10000.0));

        // Four equal suppliers: 4 × 25² = 2,500.
        $this->assertSame(2500.0, $service->hhi(collect([1, 1, 1, 1])));

        // Ten equal suppliers: 10 × 10² = 1,000 — unconcentrated.
        $this->assertSame('unconcentrated', $service->hhiBand($service->hhi(collect(array_fill(0, 10, 1)))));
    }

    public function test_integrator_concentration_is_measured_separately_from_publishers(): void
    {
        // §6.3 A4: "the CWG case is a *services* concentration, which is
        // invisible if you only model the software publisher."
        $publisherA = Vendor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Infosys', 'role' => 'publisher']);
        $publisherB = Vendor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Oracle FSS', 'role' => 'publisher']);
        $integrator = Vendor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'CWG Plc', 'role' => 'integrator']);

        // The estate looks diversified at the publisher layer …
        foreach ([$publisherA, $publisherA, $publisherB, $publisherB] as $publisher) {
            $this->application(['vendor_id' => $publisher->id]);
        }
        // … while one integrator sits behind a set of its own.
        foreach (range(1, 4) as $ignored) {
            $this->application(['vendor_id' => $integrator->id]);
        }

        $byRole = collect((new ConcentrationService())->byRole());

        $publishers = $byRole->firstWhere('role', 'publisher');
        $integrators = $byRole->firstWhere('role', 'integrator');

        $this->assertSame(2, $publishers['vendors']);
        $this->assertSame(1, $integrators['vendors']);
        $this->assertSame(100.0, $integrators['top_vendor_share']);
        $this->assertSame('highly_concentrated', $integrators['band']);
    }

    public function test_a_channel_a_third_party_can_suspend_appears_in_the_spof_register(): void
    {
        // §6.3 A7: the four-year bank/telco dispute and the deactivation of
        // nine banks' USSD codes. A commercial dependency with no software in
        // it, invisible to an availability model.
        $telco = Vendor::factory()->create(['organization_id' => $this->organization->id, 'name' => 'MTN Nigeria', 'role' => 'connectivity']);

        Channel::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'CH-USSD', 'name' => 'USSD Banking', 'channel_type' => 'ussd',
            'criticality' => 'critical', 'telco_vendor_id' => $telco->id,
            'third_party_can_suspend' => true,
            'suspension_risk_notes' => 'Telco can suspend the shortcode over a billing dispute.',
        ]);

        $register = collect((new ConcentrationService())->spofRegister());
        $ussd = $register->firstWhere('vendor', 'MTN Nigeria');

        $this->assertNotNull($ussd);
        $this->assertSame('critical', $ussd['severity']);
        $this->assertSame('connectivity', $ussd['role']);
    }

    /* ------------------------------------------------------------------ */
    /* A1 — the returns engine, and the Phase 3 exit criterion             */
    /* ------------------------------------------------------------------ */

    /** Build a small but genuinely populated estate for the returns to read. */
    private function seedEstateForReturns(): void
    {
        $lagos = $this->site();
        $london = $this->site(['code' => 'OFF-LON', 'city' => 'London', 'country' => 'GB']);
        $ownership = new OwnershipService();
        $seals = new QualitySealService($ownership);
        $owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $owner->assignRole('Application Owner');

        foreach (range(1, 6) as $i) {
            $application = $this->application([
                'name' => "System {$i}",
                'business_fit' => 4,
                'technical_fit' => 3,
                'description' => 'Demo system.',
                'time_score' => 'Invest',
                'annual_cost_ngn' => 10_000_000,
                'user_count' => 100,
                'capability_ids' => [1],
                'hosting_site_id' => $i === 1 ? $london->id : $lagos->id,
                'hosting_country' => $i === 1 ? 'GB' : 'NG',
                'dr_site_id' => $lagos->id,
                'dr_country' => 'NG',
                'contains_nigerian_payment_data' => true,
                'annual_cost' => 100_000,
                'cost_currency' => 'USD',
            ]);

            $ownership->subscribe(EaApplication::class, $application->id, $owner->id, 'accountable');

            // Seal half of them, so evidence confidence lands strictly between
            // 0 and 100 and the assertions are meaningful.
            if ($i % 2 === 0) {
                $seals->approve(EaApplication::class, $application->id, $owner);
            }
        }

        Initiative::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'INI-1', 'name' => 'Repatriate offshore workloads',
            'status' => 'in_flight', 'adm_phase' => 'E',
            'start_date' => now()->subMonth(), 'target_end_date' => now()->addYear(),
        ]);

        // Privacy-side data, so the NDPC CAR has a processing inventory,
        // a transfer register and a processor arrangement to cite. Two of
        // the five CAR audit domains are architecture questions dressed as
        // privacy questions (§6.1), which is why they live here at all.
        $processor = Vendor::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Cloud Processor Ltd',
            'role' => 'hosting',
            'origin_country' => 'GB',
        ]);

        $entities = collect(['Customer', 'Transaction', 'Account'])->map(
            fn ($name, $i) => LogicalEntity::withoutGlobalScopes()->forceCreate([
                'organization_id' => $this->organization->id,
                'code' => 'LE-'.($i + 1),
                'name' => $name,
                'pii_flag' => true,
                'classification' => 'confidential',
                'ndpa_classification' => 'personal_data',
                'lawful_basis' => $i === 2 ? null : 'contract',
                'retention_period_months' => 84,
                'processor_vendor_id' => $processor->id,
            ])
        );

        DataFlow::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'source_entity_id' => $entities[0]->id,
            'target_entity_id' => $entities[1]->id,
            'name' => 'Customer data to offshore analytics',
            'cross_border' => true,
            'source_country' => 'NG',
            'destination_country' => 'GB',
            'transfer_basis' => 'scc',
        ]);

        \App\Models\Ea\DpiaAssessment::withoutGlobalScopes()->forceCreate([
            'organization_id' => $this->organization->id,
            'code' => 'DPIA-1',
            'subject' => 'Offshore analytics processing',
            'risk_band' => 'high',
            'status' => 'approved',
        ]);
    }

    public function test_a_bank_can_produce_all_four_returns_with_citations_and_confidence(): void
    {
        // The Phase 3 exit criterion, in one test.
        $this->seedEstateForReturns();

        $compiler = new ReturnCompiler();
        $actor = User::factory()->create(['organization_id' => $this->organization->id]);

        foreach (['csat', 'itsb_maturity', 'ndpc_car', 'localisation_gap'] as $template) {
            $return = $compiler->compile($template, '2026', $this->entity, $actor);

            $this->assertInstanceOf(RegulatoryReturn::class, $return);
            $this->assertNotEmpty($return->payload, "{$template} produced no sections.");
            $this->assertNotEmpty($return->summary, "{$template} produced no summary.");

            // "each with entity-level citations and evidence-quality indicators"
            $this->assertGreaterThan(0, $return->citations()->count(), "{$template} produced no citations.");
            $this->assertIsInt($return->evidence_confidence);
            $this->assertNotNull($return->confidenceBand());
        }

        $this->assertSame(4, RegulatoryReturn::withoutGlobalScopes()->count());
    }

    public function test_citations_snapshot_the_seal_state_at_capture(): void
    {
        // §8.1 names the column `seal_state_at_capture`. A return signed in
        // February must show February's evidence quality, not today's.
        $this->seedEstateForReturns();

        $return = (new ReturnCompiler())->compile('csat', '2026', $this->entity);

        $citation = $return->citations()
            ->where('entity_type', EaApplication::class)
            ->whereNotNull('seal_state_at_capture')
            ->first();

        $this->assertNotNull($citation);
        $this->assertContains($citation->seal_state_at_capture, ['approved', 'draft', 'check_needed', 'unsealed']);

        // Breaking the seal afterwards must not rewrite history.
        $captured = $citation->seal_state_at_capture;
        QualitySeal::forEntity($citation->entity_type, $citation->entity_id)
            ->update(['state' => QualitySeal::REJECTED]);

        $this->assertSame($captured, $citation->fresh()->seal_state_at_capture);
    }

    public function test_a_return_cannot_be_signed_below_the_evidence_confidence_floor(): void
    {
        // Signing blind should not be something the software allows: the April
        // 2026 CSAT circular makes false or misleading data a breach under
        // BOFIA 2020, and the return is CISO-signed.
        config(['ea.returns.minimum_evidence_confidence' => 90]);

        $this->seedEstateForReturns();

        $compiler = new ReturnCompiler();
        $return = $compiler->compile('csat', '2026', $this->entity);
        $signer = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/below the 90% floor/');
        $compiler->sign($return, $signer, 'Chief Information Security Officer');
    }

    public function test_signing_hash_seals_the_return_and_makes_it_immutable(): void
    {
        // §10: "Every generated return and evidence pack hash-sealed, immutable
        // once signed, with the signer and timestamp recorded."
        config(['ea.returns.minimum_evidence_confidence' => 0]);

        $this->seedEstateForReturns();

        $compiler = new ReturnCompiler();
        $return = $compiler->compile('localisation_gap', '2026', $this->entity);
        $signer = User::factory()->create(['organization_id' => $this->organization->id]);

        $signed = $compiler->sign($return, $signer, 'Chief Information Security Officer');

        $this->assertSame(RegulatoryReturn::SIGNED, $signed->state);
        $this->assertStringStartsWith('sha256:', $signed->archive_hash);
        $this->assertSame($signer->name, $signed->signed_by);
        $this->assertNotNull($signed->signed_at);
        $this->assertCount(1, $signed->signoff_chain);

        // Recompiling a signed return must be refused, not silently allowed.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already been signed and is immutable/');
        $compiler->compile('localisation_gap', '2026', $this->entity);
    }

    public function test_the_localisation_return_reports_the_dr_offshore_case_separately(): void
    {
        // §6.3 A2 calls this "the most commonly missed half of the requirement",
        // so it gets its own section rather than being folded into the total.
        $lagos = $this->site();
        $london = $this->site(['code' => 'OFF-LON', 'city' => 'London', 'country' => 'GB']);

        $this->application([
            'name' => 'Compliant primary, offshore DR',
            'hosting_site_id' => $lagos->id, 'hosting_country' => 'NG',
            'dr_site_id' => $london->id, 'dr_country' => 'GB',
            'contains_nigerian_payment_data' => true,
        ]);

        $return = (new ReturnCompiler())->compile('localisation_gap', '2026', $this->entity);

        $drSection = collect($return->payload)->firstWhere('ref', 'LOC-DR');

        $this->assertNotNull($drSection);
        $this->assertSame(1, $drSection['answer']['primary_compliant_dr_offshore']);
        $this->assertSame(0, $drSection['completeness']);
        $this->assertStringContainsString('most commonly missed', $drSection['caveat']);
    }

    public function test_the_itsb_return_scores_against_the_entity_category_target(): void
    {
        $this->seedEstateForReturns();

        $return = (new ReturnCompiler())->compile('itsb_maturity', '2026', $this->entity);

        $this->assertSame(3, $return->summary['target_level'], 'Category One requires Level 3.');
        $this->assertSame('one', $return->summary['category']);
        $this->assertArrayHasKey('overall_level', $return->summary);
        $this->assertArrayHasKey('meets_target', $return->summary);

        // The maturity level is capped below 5 because documentation
        // completeness cannot evidence training and policy integration.
        $this->assertLessThanOrEqual(4, $return->summary['overall_level']);
    }

    public function test_the_period_diff_compares_against_the_prior_return(): void
    {
        // §6.3 A1: "a diff against the prior period" — the first question a
        // supervisor asks is what changed.
        $this->seedEstateForReturns();

        $compiler = new ReturnCompiler();
        $compiler->compile('localisation_gap', '2025', $this->entity);

        // The estate worsens between periods.
        $london = Site::withoutGlobalScopes()->where('country', 'GB')->first();
        $this->application([
            'name' => 'New offshore system',
            'hosting_site_id' => $london->id, 'hosting_country' => 'GB',
            'contains_nigerian_payment_data' => true,
        ]);

        $current = $compiler->compile('localisation_gap', '2026', $this->entity);
        $diff = $compiler->diff($current);

        $this->assertNotNull($diff);
        $this->assertSame('2025', $diff['previous_period']);

        $gapChange = collect($diff['changes'])->firstWhere('metric', 'localisation_gaps');
        $this->assertNotNull($gapChange, 'The diff should report the change in localisation gaps.');
        $this->assertSame('up', $gapChange['direction']);
    }

    public function test_an_unknown_template_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown return template/');

        (new ReturnCompiler())->compile('not_a_real_return', '2026', $this->entity);
    }
}
