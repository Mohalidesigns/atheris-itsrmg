<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ATH-EAR-002 Phase 3 — the African wedge (§6.3, §8.1, §8.2).
 *
 * "These are the 'massive feature.' Each is evidenced by a specific market
 * condition, each is buildable on the existing metamodel, and **none of them
 * will be built by a global vendor** because the addressable market outside
 * Africa does not justify it." §4 rows 39–48 score every competitor 0 or 1 on
 * this axis.
 *
 * Tables per §8.1:
 *   legal_entities              A6 — platform-level, multi-jurisdiction
 *   ea_sites                    A5 — data centre / site with power and tier
 *   ea_application_instances    A6 — one logical app, N deployed instances
 *   ea_channels                 A7 — branch/ATM/POS/agent/USSD/mobile/API
 *   ea_rails                    A7 — NIBSS NIP, NQR, BVN, NCS, switches
 *   regulatory_returns          A1 — the flagship return engine
 *   regulatory_return_citations A1 — evidence trail with seal state at capture
 *
 * Plus the §8.2 column additions that carry residency, cost and currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createLegalEntities();
        $this->createSites();
        $this->createApplicationInstances();
        $this->createChannelsAndRails();
        $this->createRegulatoryReturns();
        $this->alterExistingTables();
    }

    /**
     * A6 — Multi-Entity, Multi-Jurisdiction Architecture.
     *
     * §6.3: "Nigerian banking groups are genuinely multi-jurisdictional: GTCO
     * 11 countries (incl. UK), Access Holdings 10 (incl. UK), UBA ~20 African
     * markets plus UBA UK … Each subsidiary faces a different regulator."
     *
     * "A global tool models 'regions'. It does not model *'this subsidiary's
     * core banking instance must simultaneously satisfy CBN localisation, the
     * Ghanaian directive and UK operational resilience.'*"
     */
    private function createLegalEntities(): void
    {
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('short_name', 64)->nullable();
            // ISO 3166-1 alpha-2, so cross-border comparisons are mechanical.
            $table->string('jurisdiction', 2)->default('NG')->index();
            $table->string('jurisdiction_name', 64)->nullable();
            // Multiple regulators per entity is the normal case, not the
            // exception: a Nigerian bank's UK subsidiary answers to the PRA and
            // the FCA as well as to CBN at group level.
            $table->json('regulators')->nullable();
            // international / national / regional / merchant / non-interest /
            // psb / psp / mfb / holding / foreign-subsidiary
            $table->string('licence_class', 32)->nullable();
            $table->string('licence_reference', 64)->nullable();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->boolean('is_group_parent')->default(false);
            // §6.3 A6 — the CBN recapitalisation thresholds (₦500bn
            // international / ₦200bn regional / ₦50bn national) are per-entity.
            $table->decimal('capital_base_ngn', 20, 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'legal_entities_code_unique');
        });
    }

    /**
     * A5 — Power & Site Resilience Modelling.
     *
     * §6.3: "Nigeria has **28 data centres, 21 of them in Lagos** — severe
     * geographic concentration for DR purposes. Power is an under-acknowledged
     * cause of payment failure and was **entirely absent from stakeholder
     * checklists**… A DR architecture that ignores diesel is fiction in this
     * market." The ITSB adopts TIA-942 for data centre tiering.
     */
    private function createSites(): void
    {
        Schema::create('ea_sites', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('operator')->nullable();
            // owned / colocation / cloud_region / branch / office
            $table->string('site_type', 32)->default('colocation');
            $table->string('city', 64)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('country', 2)->default('NG')->index();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // TIA-942 tier — named by the ITSB, so it is an assessable
            // attribute rather than a nice-to-have.
            $table->unsignedTinyInteger('tia942_tier')->nullable();
            $table->json('power_sources')->nullable();
            $table->unsignedSmallInteger('generator_autonomy_hours')->nullable();
            $table->string('fuel_dependency', 32)->nullable();
            $table->unsignedSmallInteger('ups_autonomy_minutes')->nullable();
            $table->json('connectivity_providers')->nullable();
            // low / medium / high — flood and grid reliability are the two
            // environmental factors that actually take Nigerian sites down.
            $table->string('flood_risk', 16)->nullable();
            $table->string('grid_reliability_band', 16)->nullable();
            // Lagos concentration is the headline finding; a grid zone lets the
            // alert fire on "same grid" as well as "same city".
            $table->string('grid_zone', 32)->nullable();
            $table->json('certifications')->nullable();
            $table->boolean('is_sovereign')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'ea_sites_code_unique');
        });
    }

    /**
     * A6 — "Application *instances* mapped to entities — one logical
     * application, N deployed instances, each with its own hosting, residency,
     * criticality and regulator set."
     */
    private function createApplicationInstances(): void
    {
        Schema::create('ea_application_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('application_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('code', 48);
            $table->string('name')->nullable();

            // Residency travels with the *instance*, not the logical
            // application — that distinction is the whole point of A6.
            $table->unsignedBigInteger('hosting_site_id')->nullable()->index();
            $table->string('hosting_country', 2)->nullable()->index();
            // on_prem / colocation / private_cloud / public_cloud / saas
            $table->string('hosting_model', 32)->nullable();
            $table->unsignedBigInteger('dr_site_id')->nullable()->index();
            $table->string('dr_country', 2)->nullable();
            $table->string('replication_topology', 32)->nullable();

            $table->string('criticality', 32)->default('medium');
            $table->string('environment', 16)->default('production');
            // The 1 January 2027 localisation directive turns on this flag.
            $table->boolean('contains_nigerian_payment_data')->default(false);
            $table->json('data_categories')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'ea_app_instances_code_unique');
        });
    }

    /**
     * A7 — The African Channel & Rails Model.
     *
     * §6.3: "USSD is a first-class retail channel in Nigeria with commercial
     * and regulatory fragility that has no Western analogue: a four-year debt
     * dispute between banks and telcos, telcos suspending service, and the FG
     * deactivating nine banks' USSD codes."
     *
     * Modelling the telco as a dependency is what makes "MTN can switch off
     * this channel over a billing dispute" a visible, assessable risk.
     */
    private function createChannelsAndRails(): void
    {
        Schema::create('ea_channels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            // branch / atm / pos / agent_banking / ussd / mobile_app /
            // internet_banking / api_open_banking / whatsapp
            $table->string('channel_type', 32)->index();
            $table->text('description')->nullable();
            $table->string('criticality', 32)->default('medium');
            $table->string('status', 16)->default('active');

            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();

            // The dependency that has no Western analogue.
            $table->unsignedBigInteger('telco_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('aggregator_vendor_id')->nullable()->index();
            $table->string('shortcode', 32)->nullable();
            $table->boolean('third_party_can_suspend')->default(false);
            $table->text('suspension_risk_notes')->nullable();

            $table->unsignedBigInteger('monthly_volume')->nullable();
            $table->decimal('monthly_value_ngn', 20, 2)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'ea_channels_code_unique');
        });

        Schema::create('ea_rails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            // rail / switch / scheme / registry
            $table->string('rail_type', 32)->index();
            $table->string('operator')->nullable();
            $table->unsignedBigInteger('operator_vendor_id')->nullable()->index();
            $table->text('description')->nullable();
            $table->boolean('is_mandatory')->default(false);

            // Switch objects carry the CBN licence conditions as attributes
            // "so the constraint set is inspectable" (§6.3 A7).
            $table->boolean('requires_24_7')->default(false);
            $table->boolean('requires_pci_dss')->default(false);
            $table->boolean('requires_emv')->default(false);
            $table->unsignedSmallInteger('log_retention_years')->nullable();
            $table->string('cbn_licence_reference', 64)->nullable();
            $table->json('licence_conditions')->nullable();

            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'ea_rails_code_unique');
        });
    }

    /**
     * A1 — the Regulatory Architecture Return Engine, the flagship.
     *
     * §6.3: "A return-generation engine that treats each regulatory submission
     * as a compiled artefact of the architecture graph plus the GRC data."
     * §1.4: "Do not sell an EA tool. **Sell the regulatory architecture return,
     * and ship an EA repository as the machine that produces it.**"
     *
     * §5.5 promotes this out of EA to a suite-level module, because the returns
     * draw on CSAT, Risk, Control, Vendor, Incident and BCP data too — "burying
     * them inside EA hides the product's best feature from the CISO who buys
     * it".
     */
    private function createRegulatoryReturns(): void
    {
        Schema::create('regulatory_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('code', 40);
            // csat / itsb_maturity / ndpc_car / localisation_gap /
            // board_pack / incident_annex
            $table->string('template', 32)->index();
            $table->string('name');
            // e.g. "2026", "2026-Q3"
            $table->string('period', 16);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('due_date')->nullable()->index();
            // Wide enough for a named body, not just an acronym:
            // "CBN IT Standards Governance Council" is 35 characters.
            $table->string('regulator', 96)->nullable();

            // draft / in_preparation / in_review / signed / submitted / archived
            $table->string('state', 24)->default('draft');
            $table->unsignedBigInteger('owner_id')->nullable()->index();

            // The compiled artefact plus the numbers behind its cover page.
            $table->json('payload')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedTinyInteger('completeness')->default(0);
            // §6.3 A1: "with the quality-seal state shown so the CISO knows
            // what is trustworthy before signing".
            $table->unsignedTinyInteger('evidence_confidence')->default(0);

            // Sign-off chain — §6.3 A1 requires "a preparation workflow with
            // sign-off stages" and the CSAT is CISO-signed.
            $table->json('signoff_chain')->nullable();
            $table->string('signed_by')->nullable();
            $table->string('signed_role', 64)->nullable();
            $table->timestamp('signed_at')->nullable();

            // Hash-sealed archive, reusing the EvidencePackGenerator pattern.
            $table->string('archive_path')->nullable();
            $table->string('archive_hash', 80)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('previous_return_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'regulatory_returns_code_unique');
            $table->index(['template', 'period']);
        });

        Schema::create('regulatory_return_citations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->unsignedBigInteger('return_id')->index();
            // Which question or section of the return this evidences.
            $table->string('question_ref', 64)->index();
            $table->string('entity_type', 128);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_label')->nullable();
            // §8.1 names this column explicitly: the seal state **at capture**,
            // not the current one. A return signed in February must show what
            // the evidence quality was in February, not what it became later.
            $table->string('seal_state_at_capture', 16)->nullable();
            $table->unsignedTinyInteger('completeness_at_capture')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['return_id', 'question_ref']);
        });
    }

    /** §8.2 — columns the wedge features need on existing tables. */
    private function alterExistingTables(): void
    {
        Schema::table('ea_applications_ext', function (Blueprint $table) {
            // A2 — residency
            $table->string('hosting_country', 2)->nullable()->after('vendor_id')->index();
            $table->unsignedBigInteger('hosting_site_id')->nullable()->after('hosting_country')->index();
            $table->string('hosting_model', 32)->nullable()->after('hosting_site_id');
            $table->string('dr_country', 2)->nullable()->after('hosting_model');
            $table->unsignedBigInteger('dr_site_id')->nullable()->after('dr_country')->index();
            $table->string('replication_topology', 32)->nullable()->after('dr_site_id');
            $table->boolean('contains_nigerian_payment_data')->default(false)->after('replication_topology')->index();
            $table->json('data_categories')->nullable()->after('contains_nigerian_payment_data');
            // adequacy / scc / bcr / consent / jural_obligation / none
            $table->string('transfer_basis', 32)->nullable()->after('data_categories');
            $table->string('transfer_approval_reference', 64)->nullable()->after('transfer_basis');

            // A3 — FX exposure. `annual_cost_ngn` already exists but carries no
            // currency, which is exactly the gap: a dollar-priced core banking
            // licence recorded in naira hides the exposure.
            $table->decimal('annual_cost', 18, 2)->nullable()->after('transfer_approval_reference');
            $table->string('cost_currency', 3)->nullable()->after('annual_cost')->index();
            $table->date('contract_end_date')->nullable()->after('cost_currency');
            $table->unsignedSmallInteger('renewal_notice_days')->nullable()->after('contract_end_date');
            $table->string('licence_model', 32)->nullable()->after('renewal_notice_days');

            // A6 — entity scoping
            $table->unsignedBigInteger('legal_entity_id')->nullable()->after('licence_model')->index();
            $table->boolean('is_indigenous')->default(false)->after('legal_entity_id');
        });

        Schema::table('ea_tech_components_ext', function (Blueprint $table) {
            $table->unsignedBigInteger('hosting_site_id')->nullable()->index();
            $table->decimal('annual_cost', 18, 2)->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->string('origin_country', 2)->nullable();
        });

        Schema::table('ea_interfaces', function (Blueprint $table) {
            $table->boolean('crosses_border')->default(false)->index();
            $table->unsignedBigInteger('counterparty_vendor_id')->nullable()->index();
            $table->unsignedBigInteger('rail_id')->nullable()->index();
            $table->string('source_country', 2)->nullable();
            $table->string('destination_country', 2)->nullable();
        });

        Schema::table('ea_data_flows', function (Blueprint $table) {
            $table->string('transfer_basis', 32)->nullable();
            $table->string('transfer_approval_reference', 64)->nullable();
            $table->string('source_country', 2)->nullable();
            $table->string('destination_country', 2)->nullable();
            $table->boolean('contains_nigerian_payment_data')->default(false);
        });

        Schema::table('ea_logical_entities', function (Blueprint $table) {
            // NDPA classification drives the CAR extract.
            $table->string('ndpa_classification', 32)->nullable();
            $table->unsignedSmallInteger('retention_period_months')->nullable();
            $table->unsignedBigInteger('processor_vendor_id')->nullable()->index();
            $table->string('lawful_basis', 32)->nullable();
        });

        Schema::table('ea_control_mappings', function (Blueprint $table) {
            $table->string('coverage_basis', 64)->nullable();
            $table->date('last_verified_at')->nullable();
        });

        // §8.2 — vendors gains the dimension A4 turns on. §6.3: "the CWG case
        // is a *services* concentration, which is invisible if you only model
        // the software publisher."
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('role', 32)->nullable()->after('category')->index();
            $table->unsignedTinyInteger('substitutability_score')->nullable()->after('role');
            $table->string('origin_country', 2)->nullable()->after('substitutability_score');
            $table->unsignedSmallInteger('in_market_alternatives')->nullable()->after('origin_country');
            $table->text('switching_notes')->nullable()->after('in_market_alternatives');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['role', 'substitutability_score', 'origin_country', 'in_market_alternatives', 'switching_notes']);
        });
        Schema::table('ea_control_mappings', function (Blueprint $table) {
            $table->dropColumn(['coverage_basis', 'last_verified_at']);
        });
        Schema::table('ea_logical_entities', function (Blueprint $table) {
            $table->dropColumn(['ndpa_classification', 'retention_period_months', 'processor_vendor_id', 'lawful_basis']);
        });
        Schema::table('ea_data_flows', function (Blueprint $table) {
            $table->dropColumn(['transfer_basis', 'transfer_approval_reference', 'source_country', 'destination_country', 'contains_nigerian_payment_data']);
        });
        Schema::table('ea_interfaces', function (Blueprint $table) {
            $table->dropColumn(['crosses_border', 'counterparty_vendor_id', 'rail_id', 'source_country', 'destination_country']);
        });
        Schema::table('ea_tech_components_ext', function (Blueprint $table) {
            $table->dropColumn(['hosting_site_id', 'annual_cost', 'cost_currency', 'origin_country']);
        });
        Schema::table('ea_applications_ext', function (Blueprint $table) {
            $table->dropColumn([
                'hosting_country', 'hosting_site_id', 'hosting_model', 'dr_country', 'dr_site_id',
                'replication_topology', 'contains_nigerian_payment_data', 'data_categories',
                'transfer_basis', 'transfer_approval_reference', 'annual_cost', 'cost_currency',
                'contract_end_date', 'renewal_notice_days', 'licence_model', 'legal_entity_id', 'is_indigenous',
            ]);
        });

        Schema::dropIfExists('regulatory_return_citations');
        Schema::dropIfExists('regulatory_returns');
        Schema::dropIfExists('ea_rails');
        Schema::dropIfExists('ea_channels');
        Schema::dropIfExists('ea_application_instances');
        Schema::dropIfExists('ea_sites');
        Schema::dropIfExists('legal_entities');
    }
};
