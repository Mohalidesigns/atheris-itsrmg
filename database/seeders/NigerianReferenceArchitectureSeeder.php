<?php

namespace Database\Seeders;

use App\Models\Concerns\TenantScope;
use App\Models\Ea\Channel;
use App\Models\Ea\Rail;
use App\Models\Ea\Site;
use App\Models\LegalEntity;
use App\Models\Organization;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * The Nigerian Banking Reference Architecture content pack — ATH-EAR-002 §6.4.
 *
 * "A pre-built content library shipped with the product, so a bank starts at
 * 60% populated rather than at an empty canvas. **This is the single strongest
 * answer to Nigerian architect scarcity** — ~49 EA roles nationally, and the
 * flagship regional architecture role names zero EA tools. Users should
 * populate a structure, not design one."
 *
 * §5.1 also makes the case for shipping an opinionated model: a user-definable
 * metamodel is "actively harmful" given architect scarcity, because Ardoq's
 * flexibility is documented to produce "inconsistent models across teams".
 *
 * Commercially §6.4 frames this as "a **regulatory content subscription** —
 * recurring revenue on top of the platform licence, and a genuine reason to
 * renew."
 *
 * Everything here is public-record market structure — data centre operators,
 * licensed switches, payment rails, integrator names. It is reference content,
 * not any bank's data, and it is deliberately generic at the institution level:
 * the legal entities seeded are illustrative shapes, not real banks' estates.
 */
class NigerianReferenceArchitectureSeeder extends Seeder
{
    /**
     * The tenant that owns the institution-shaped half of this pack.
     *
     * The pack splits in two, and the split is deliberate:
     *
     *  · **Market structure** — data centres and payment rails — is seeded with
     *    `organization_id => null`. {@see TenantScope}
     *    resolves NULL rows for *every* organisation, so Rack Centre and NIBSS
     *    are visible to any tenant without being duplicated per bank. That is
     *    the §6.4 content-subscription proposition working as designed, and
     *    EaTenancyTest covers it.
     *
     *  · **Institution shapes** — the vendor register, the legal-entity tree
     *    and the channel estate — are one bank's records. `Vendor` is a
     *    tenant's own register (market-level vendors live in
     *    `shared_vendor_directory`), a legal-entity tree *is* the group
     *    structure, and a channel carries that bank's shortcode and its
     *    suspension risk. These were written against organisation 1 — the Acme
     *    organisation nobody demos as — which left them invisible to the tenant
     *    that owns the EA repository. They now belong to First Bank.
     */
    private int $orgId;

    public function run(): void
    {
        $this->orgId = $this->resolveTenant();
        $this->seedSites();
        $this->seedVendorsAndIntegrators();
        $this->seedRails();
        $this->seedLegalEntities();
        $this->seedChannels();

        $this->command?->info('[EA] Nigerian Banking Reference Architecture pack installed.');
    }

    /**
     * First Bank owns the EA repository, so it owns the institution half of the
     * pack. This seeder runs after FirstBankDemoSeeder, so the organisation is
     * already there; the fallback only matters if this pack is ever installed
     * standalone.
     */
    private function resolveTenant(): int
    {
        return Organization::where('slug', 'first-bank-nigeria')->value('id')
            ?? Organization::where('slug', 'kano-heritage-bank')->value('id')
            ?? 1;
    }

    /**
     * §6.4: "The 28 Nigerian data centres with tier, operator, city and power
     * attributes (A5)."
     *
     * §6.3 A5 names the estate: "Rack Centre, Africa Data Centres LOSI,
     * Equinix/MainOne LG1–LG3, MTN Sifiso Dabengwa, Digital Realty, OADC, NTT,
     * Airtel Nxtra, Tetracore, **Galaxy Backbone (Abuja, Kano Tier IV,
     * Enugu)**."
     *
     * The Lagos concentration is the point — 21 of 28 — so the seed reproduces
     * it rather than smoothing it out. A demo where DR is comfortably
     * distributed teaches the evaluator nothing.
     */
    private function seedSites(): void
    {
        $sites = [
            // ---- Lagos (the concentration) ----
            ['RC-LOS-01', 'Rack Centre LGS1', 'Rack Centre', 'Lagos', 'Lagos', 3, 72, 'high', 'medium', 'LOS-IKEJA', ['MainOne', 'Glo', 'MTN', 'Airtel'], ['ISO 27001', 'PCI DSS', 'Uptime Tier III']],
            ['ADC-LOSI', 'Africa Data Centres LOSI', 'Africa Data Centres', 'Lagos', 'Lagos', 3, 48, 'high', 'medium', 'LOS-LEKKI', ['MainOne', 'WIOCC', 'MTN'], ['ISO 27001', 'PCI DSS']],
            ['EQX-LG1', 'Equinix LG1 (MainOne)', 'Equinix', 'Lagos', 'Lagos', 3, 48, 'high', 'medium', 'LOS-LEKKI', ['MainOne', 'Equinix Fabric'], ['ISO 27001', 'SOC 2']],
            ['EQX-LG2', 'Equinix LG2', 'Equinix', 'Lagos', 'Lagos', 3, 48, 'high', 'medium', 'LOS-LEKKI', ['MainOne', 'Equinix Fabric'], ['ISO 27001']],
            ['EQX-LG3', 'Equinix LG3', 'Equinix', 'Lagos', 'Lagos', 3, 36, 'high', 'high', 'LOS-VI', ['MainOne'], ['ISO 27001']],
            ['MTN-SDB', 'MTN Sifiso Dabengwa DC', 'MTN Nigeria', 'Lagos', 'Lagos', 3, 72, 'high', 'medium', 'LOS-IKEJA', ['MTN', 'Glo'], ['ISO 27001']],
            ['DR-LOS', 'Digital Realty Lagos', 'Digital Realty', 'Lagos', 'Lagos', 3, 48, 'high', 'medium', 'LOS-IKEJA', ['MainOne', 'MTN'], ['ISO 27001', 'SOC 2']],
            ['OADC-LOS', 'OADC Lagos', 'Open Access Data Centres', 'Lagos', 'Lagos', 3, 48, 'high', 'medium', 'LOS-LEKKI', ['WIOCC', 'MainOne'], ['ISO 27001']],
            ['NTT-LOS', 'NTT Lagos', 'NTT', 'Lagos', 'Lagos', 3, 36, 'high', 'medium', 'LOS-IKEJA', ['MTN', 'MainOne'], ['ISO 27001']],
            ['NXTRA-LOS', 'Airtel Nxtra Lagos', 'Airtel Nigeria', 'Lagos', 'Lagos', 2, 24, 'medium', 'high', 'LOS-IKEJA', ['Airtel'], []],
            ['TET-LOS', 'Tetracore Lagos', 'Tetracore Energy Group', 'Lagos', 'Lagos', 2, 36, 'medium', 'medium', 'LOS-IKEJA', ['MTN'], []],

            // ---- Outside Lagos (the DR options that actually diversify) ----
            ['GBB-ABJ', 'Galaxy Backbone Abuja', 'Galaxy Backbone', 'Abuja', 'FCT', 3, 72, 'high', 'low', 'ABJ-CENTRAL', ['Galaxy Backbone', 'MTN'], ['ISO 27001']],
            ['GBB-KAN', 'Galaxy Backbone Kano', 'Galaxy Backbone', 'Kano', 'Kano', 4, 96, 'high', 'low', 'KAN-CENTRAL', ['Galaxy Backbone'], ['ISO 27001', 'Uptime Tier IV']],
            ['GBB-ENU', 'Galaxy Backbone Enugu', 'Galaxy Backbone', 'Enugu', 'Enugu', 3, 72, 'medium', 'low', 'ENU-CENTRAL', ['Galaxy Backbone'], ['ISO 27001']],
            ['MDXI-ABJ', 'MDXi Abuja', 'MainOne / Equinix', 'Abuja', 'FCT', 3, 48, 'high', 'low', 'ABJ-CENTRAL', ['MainOne'], ['ISO 27001']],
            ['MDXI-PHC', 'MDXi Port Harcourt', 'MainOne / Equinix', 'Port Harcourt', 'Rivers', 2, 36, 'medium', 'high', 'PHC-CENTRAL', ['MainOne'], ['ISO 27001']],
            ['ADC-ABJ', 'Africa Data Centres Abuja', 'Africa Data Centres', 'Abuja', 'FCT', 3, 48, 'high', 'low', 'ABJ-CENTRAL', ['MTN', 'MainOne'], ['ISO 27001']],
            ['KDC-KAD', 'Kaduna Regional DC', 'Regional operator', 'Kaduna', 'Kaduna', 2, 24, 'medium', 'low', 'KAD-CENTRAL', ['MTN'], []],
        ];

        foreach ($sites as [$code, $name, $operator, $city, $state, $tier, $generatorHours, $grid, $flood, $gridZone, $connectivity, $certifications]) {
            Site::updateOrCreate(
                ['code' => $code, 'organization_id' => null],
                [
                    'name' => $name,
                    'operator' => $operator,
                    'site_type' => 'colocation',
                    'city' => $city,
                    'state' => $state,
                    'country' => 'NG',
                    'tia942_tier' => $tier,
                    // Diesel is the binding constraint, not the tier badge.
                    'power_sources' => ['grid', 'diesel_generator', 'ups'],
                    'generator_autonomy_hours' => $generatorHours,
                    'fuel_dependency' => 'diesel',
                    'ups_autonomy_minutes' => 15,
                    'connectivity_providers' => $connectivity,
                    'flood_risk' => $flood,
                    'grid_reliability_band' => $grid,
                    'grid_zone' => $gridZone,
                    'certifications' => $certifications,
                    'is_sovereign' => true,
                    'notes' => 'Reference content — Nigerian Banking Reference Architecture pack.',
                ],
            );
        }
    }

    /**
     * §6.4: "CWG, Interswitch, eTranzact, Unified Payments, NIBSS, Chams,
     * Computer Warehouse, Signal Alliance, MainOne, Galaxy Backbone — with the
     * **role** dimension from A4."
     *
     * The role dimension is what surfaces the finding §6.3 A4 describes: CWG is
     * the sole Nigerian distributor of Finacle and reportedly serves ~60% of
     * Nigerian banks. That is a *services* concentration, invisible to anyone
     * modelling only the software publisher.
     */
    private function seedVendorsAndIntegrators(): void
    {
        $vendors = [
            // Core banking publishers — the near-duopoly at tier 1.
            ['Infosys Finacle', 'publisher', 'IN', 2, 3, 'Core banking migration is a multi-year programme. Realistic alternatives exist but switching cost is extreme.'],
            ['Oracle FSS (Flexucbe)', 'publisher', 'US', 2, 3, 'As above. Flexcube and Finacle together dominate tier 1.'],
            ['Temenos (T24)', 'publisher', 'CH', 3, 3, 'Present in-market, mostly at mid-tier.'],
            ['Path Solutions (iMAL)', 'publisher', 'LB', 3, 2, 'Non-interest banking specialism.'],
            ['SOPRA Banking (Amplitude)', 'publisher', 'FR', 3, 2, null],
            ['Intellect Design', 'publisher', 'IN', 3, 2, null],
            ['Peerless (SeaBaaS)', 'publisher', 'NG', 4, 2, 'Indigenous, naira-priced. The Sterling Bank migration is the reference case for FX-driven localisation.'],
            ['BankOne', 'publisher', 'NG', 4, 3, 'Indigenous, widely used by microfinance banks.'],
            ['Neptune Software', 'publisher', 'NG', 4, 2, 'Indigenous.'],

            // Integrators — the dimension A4 exists to surface.
            ['CWG Plc', 'integrator', 'NG', 1, 1, 'Sole Nigerian distributor of Infosys Finacle; reportedly serves a majority of Nigerian banks. A services concentration invisible if only publishers are modelled.'],
            ['Computer Warehouse Group', 'integrator', 'NG', 2, 2, null],
            ['Signal Alliance', 'integrator', 'NG', 3, 3, null],
            ['Chams Plc', 'integrator', 'NG', 3, 3, null],

            // Switches, rails and processors.
            ['Interswitch', 'managed_service', 'NG', 2, 2, 'Switch and processing. Systemically important to card and transfer flows.'],
            ['NIBSS', 'managed_service', 'NG', 1, 0, 'The national rail operator. No alternative exists by design.'],
            ['eTranzact', 'managed_service', 'NG', 3, 3, null],
            ['Unified Payments', 'managed_service', 'NG', 3, 3, null],

            // Hosting and connectivity.
            ['MainOne / Equinix', 'hosting', 'NG', 3, 4, null],
            ['Rack Centre', 'hosting', 'NG', 3, 4, null],
            ['Galaxy Backbone', 'hosting', 'NG', 3, 3, 'Government-owned; Kano facility is Tier IV.'],
            ['Africa Data Centres', 'hosting', 'ZA', 3, 4, null],
            ['MTN Nigeria', 'connectivity', 'NG', 2, 3, 'Also the counterparty on USSD channel agreements — see the 2020–2025 bank/telco debt dispute.'],
            ['Airtel Nigeria', 'connectivity', 'NG', 3, 3, null],
            ['Glo Mobile', 'connectivity', 'NG', 3, 3, null],
            ['9mobile', 'connectivity', 'NG', 3, 3, null],
        ];

        foreach ($vendors as [$name, $role, $origin, $substitutability, $alternatives, $notes]) {
            $vendor = Vendor::withoutGlobalScopes()->where('name', $name)->first();

            $attributes = [
                'role' => $role,
                'origin_country' => $origin,
                'substitutability_score' => $substitutability,
                'in_market_alternatives' => $alternatives,
                'switching_notes' => $notes,
            ];

            if ($vendor) {
                // Never overwrite a tenant's own vendor record with reference
                // content — only fill the dimensions A4 needs.
                $vendor->fill(array_filter($attributes, fn ($v, $k) => $vendor->{$k} === null, ARRAY_FILTER_USE_BOTH))->save();

                continue;
            }

            Vendor::create($attributes + [
                'organization_id' => $this->orgId,
                'vendor_code' => 'REF-'.strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name), 0, 8)),
                'name' => $name,
                'category' => ucfirst(str_replace('_', ' ', $role)),
                'status' => 'active',
                'country' => $origin,
                'description' => 'Reference content — Nigerian Banking Reference Architecture pack.',
            ]);
        }
    }

    /**
     * §6.4: "NIBSS NIP, NCS, BVN, NQR; the 17 CBN-licensed switches with their
     * licence conditions."
     *
     * §6.3 A7: switches are "subject to mandatory 24/7 operation, PCI DSS/EMV
     * compliance and **seven-year transaction logging**". Carrying those as
     * attributes rather than prose is what makes the constraint set inspectable
     * — and answerable in a return.
     */
    private function seedRails(): void
    {
        $rails = [
            ['NIP', 'NIBSS Instant Payments', 'rail', 'NIBSS', true, true, false, false, null],
            ['NCS', 'Nigeria Central Switch', 'rail', 'NIBSS', true, true, true, true, 7],
            ['BVN', 'Bank Verification Number registry', 'registry', 'NIBSS', true, true, false, false, 7],
            ['NQR', 'NQR QR payments', 'rail', 'NIBSS', false, true, false, false, 7],
            ['MCASH', 'mCASH', 'rail', 'NIBSS', false, true, false, false, 7],
            ['OBR', 'Open Banking Registry', 'registry', 'CBN', false, true, false, false, null],

            ['SW-ISW', 'Interswitch', 'switch', 'Interswitch', false, true, true, true, 7],
            ['SW-ETZ', 'eTranzact', 'switch', 'eTranzact', false, true, true, true, 7],
            ['SW-UPSL', 'Unified Payments', 'switch', 'Unified Payments', false, true, true, true, 7],
            ['SW-3LINE', '3Line Card Management', 'switch', '3Line', false, true, true, true, 7],
            ['SW-CORAL', 'CoralPay', 'switch', 'CoralPay', false, true, true, true, 7],
            ['SW-XPRESS', 'Xpress Payments', 'switch', 'Xpress Payment Solutions', false, true, true, true, 7],

            ['SCH-VERVE', 'Verve', 'scheme', 'Interswitch', false, true, true, true, 7],
            ['SCH-MC', 'Mastercard', 'scheme', 'Mastercard', false, true, true, true, 7],
            ['SCH-VISA', 'Visa', 'scheme', 'Visa', false, true, true, true, 7],
            ['SCH-AFRIGO', 'AfriGo', 'scheme', 'NIBSS / CBN', false, true, true, true, 7],
        ];

        foreach ($rails as [$code, $name, $type, $operator, $mandatory, $requires247, $pci, $emv, $retention]) {
            $vendorId = Vendor::withoutGlobalScopes()->where('name', 'like', $operator.'%')->value('id');

            Rail::updateOrCreate(
                ['code' => $code, 'organization_id' => null],
                [
                    'name' => $name,
                    'rail_type' => $type,
                    'operator' => $operator,
                    'operator_vendor_id' => $vendorId,
                    'is_mandatory' => $mandatory,
                    'requires_24_7' => $requires247,
                    'requires_pci_dss' => $pci,
                    'requires_emv' => $emv,
                    'log_retention_years' => $retention,
                    'licence_conditions' => $type === 'switch'
                        ? ['CBN switching licence', 'Annual recertification', 'Settlement account with CBN']
                        : [],
                    'description' => 'Reference content — Nigerian Banking Reference Architecture pack.',
                    'status' => 'active',
                ],
            );
        }
    }

    /**
     * Illustrative group structures — A6.
     *
     * §6.3: "GTCO 11 countries (incl. UK), Access Holdings 10 (incl. UK), UBA
     * ~20 African markets plus UBA UK, FBN Holdings 7, Zenith 5."
     *
     * Seeded as a generic holding-company shape rather than a named bank's
     * estate: the structure is what a design partner adapts, and putting a real
     * institution's name on demo data invites it being mistaken for their data.
     */
    private function seedLegalEntities(): void
    {
        if (LegalEntity::where('organization_id', $this->orgId)->count() > 0) {
            return;
        }

        $group = LegalEntity::create([
            'organization_id' => $this->orgId,
            'code' => 'GRP',
            'name' => 'Group Holding Company',
            'short_name' => 'Group',
            'jurisdiction' => 'NG',
            'jurisdiction_name' => 'Nigeria',
            'regulators' => ['CBN', 'SEC Nigeria'],
            'licence_class' => 'holding',
            'is_group_parent' => true,
            'status' => 'active',
        ]);

        $subsidiaries = [
            ['NG-BANK', 'Nigeria Commercial Bank', 'NG', 'Nigeria', ['CBN', 'NDIC', 'NDPC'], 'international', 500_000_000_000],
            ['GH-BANK', 'Ghana Subsidiary', 'GH', 'Ghana', ['Bank of Ghana'], 'foreign_subsidiary', null],
            ['KE-BANK', 'Kenya Subsidiary', 'KE', 'Kenya', ['Central Bank of Kenya'], 'foreign_subsidiary', null],
            ['UK-BANK', 'UK Subsidiary', 'GB', 'United Kingdom', ['PRA', 'FCA'], 'foreign_subsidiary', null],
            ['NG-PSP', 'Payments Subsidiary', 'NG', 'Nigeria', ['CBN', 'NDPC'], 'psp', null],
        ];

        foreach ($subsidiaries as [$code, $name, $jurisdiction, $jurisdictionName, $regulators, $class, $capital]) {
            LegalEntity::create([
                'organization_id' => $this->orgId,
                'code' => $code,
                'name' => $name,
                'jurisdiction' => $jurisdiction,
                'jurisdiction_name' => $jurisdictionName,
                'regulators' => $regulators,
                'licence_class' => $class,
                'capital_base_ngn' => $capital,
                'parent_id' => $group->id,
                'status' => 'active',
            ]);
        }
    }

    /**
     * §6.3 A7 — the channel layer, with the telco dependency modelled.
     *
     * USSD is the one that matters: a four-year bank/telco debt dispute, telcos
     * suspending service, and the FG deactivating nine banks' USSD codes.
     * `third_party_can_suspend` makes that a visible, assessable dependency.
     */
    private function seedChannels(): void
    {
        if (Channel::where('organization_id', $this->orgId)->count() > 0) {
            return;
        }

        $entity = LegalEntity::where('code', 'NG-BANK')->value('id');
        $mtn = Vendor::withoutGlobalScopes()->where('name', 'MTN Nigeria')->value('id');

        $channels = [
            ['CH-USSD', 'USSD Banking', 'ussd', 'critical', true, '*737#',
                'Telco can suspend the shortcode over a billing dispute. Precedent: the 2020–2025 bank/telco debt dispute and the deactivation of nine banks\' USSD codes.', $mtn],
            ['CH-AGENT', 'Agent Banking', 'agent_banking', 'critical', true,
                null, 'Depends on a third-party agent network operator.', null],
            ['CH-MOBILE', 'Mobile Banking App', 'mobile_app', 'critical', false, null, null, null],
            ['CH-IBANK', 'Internet Banking', 'internet_banking', 'high', false, null, null, null],
            ['CH-ATM', 'ATM Network', 'atm', 'high', false, null, null, null],
            ['CH-POS', 'POS Acquiring', 'pos', 'high', false, null, null, null],
            ['CH-BRANCH', 'Branch Network', 'branch', 'high', false, null, null, null],
            ['CH-API', 'Open Banking API', 'api_open_banking', 'medium', false, null,
                'Subject to the Open Banking Registry and Know Your Partner obligations.', null],
        ];

        foreach ($channels as [$code, $name, $type, $criticality, $canSuspend, $shortcode, $notes, $telcoId]) {
            Channel::create([
                'organization_id' => $this->orgId,
                'code' => $code,
                'name' => $name,
                'channel_type' => $type,
                'criticality' => $criticality,
                'legal_entity_id' => $entity,
                'telco_vendor_id' => $telcoId,
                'shortcode' => $shortcode,
                'third_party_can_suspend' => $canSuspend,
                'suspension_risk_notes' => $notes,
                'status' => 'active',
                'description' => 'Reference content — Nigerian Banking Reference Architecture pack.',
            ]);
        }
    }
}
