<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical business architecture (ATH-EAR-002 D1 / WS 2.1)
    |--------------------------------------------------------------------------
    |
    | §7.2 resolves the duplicate-model problem in favour of EA: "EA owns the
    | canonical business-architecture objects; the core BusinessService /
    | BusinessCapability family becomes a read-through projection or is
    | retired."
    |
    | When true, App\Models\BusinessCapability, BusinessProcess, BusinessService
    | and ServiceDependency are thin deprecated projections over the EA tables.
    | When false they fall back to their original tables, which the WS 2.1
    | migration deliberately did not drop.
    |
    | Risk R4 asks for this switch: "The canonical-model migration breaks
    | existing modules … Phase 2.1 behind a feature flag with compatibility
    | accessors and a reversible migration."
    |
    */
    'canonical_business_architecture' => env('EA_CANONICAL_BUSINESS_ARCHITECTURE', true),

    /*
    |--------------------------------------------------------------------------
    | Cross-module integration contracts (§7.3, I-1 … I-14)
    |--------------------------------------------------------------------------
    |
    | Each contract can be disabled independently. A bank piloting the EA module
    | before its Risk register is populated should be able to hold I-1 off
    | rather than have obsolescence findings open risks nobody owns yet.
    |
    */
    'contracts' => [
        'obsolescence_to_risk' => env('EA_CONTRACT_I1', true),        // I-1
        'cve_to_vulnerability' => env('EA_CONTRACT_I2', true),        // I-2
        'application_to_asset' => env('EA_CONTRACT_I3', true),        // I-3
        'bia_to_process' => env('EA_CONTRACT_I6', true),              // I-6
        'kri_to_platform' => env('EA_CONTRACT_I8', true),             // I-8
        'arb_to_issues' => env('EA_CONTRACT_I9', true),               // I-9
        'evidence_to_vault' => env('EA_CONTRACT_I10', true),          // I-10
    ],

    /*
    |--------------------------------------------------------------------------
    | Obsolescence → Risk thresholds (I-1)
    |--------------------------------------------------------------------------
    |
    | A component past EOL with no replacement initiative opens a risk. The
    | score is derived from criticality × exposure rather than fixed, so the
    | risk register does not fill with identical medium findings.
    |
    */
    'obsolescence' => [
        'tech_debt_threshold' => 60,
        'eol_horizon_days' => 365,
        'risk_category' => 'Technology Obsolescence',
    ],

    /*
    |--------------------------------------------------------------------------
    | Data localisation (A2 / §6.1 Finding 3)
    |--------------------------------------------------------------------------
    |
    | The circular of 15 June 2026 requires Nigerian payment transaction data to
    | be stored and managed within Nigeria, covering primary AND disaster
    | recovery infrastructure, by 1 January 2027. A companion market-structure
    | rule bites 31 December 2026.
    |
    | Configurable rather than compiled in: a date in code is a date nobody can
    | correct without a deploy, and §12.1 R3 flags that the enforcement picture
    | may shift.
    |
    */
    'localisation' => [
        'deadline' => env('EA_LOCALISATION_DEADLINE', '2027-01-01'),
        'market_structure_deadline' => env('EA_MARKET_STRUCTURE_DEADLINE', '2026-12-31'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FX exposure (A3 / §6.3)
    |--------------------------------------------------------------------------
    |
    | Reference rates in naira. These move constantly — the naira went from
    | roughly ₦460/$ to over ₦1,500/$ across 2023–24 — so they are configuration,
    | not constants. §6.3 A3: tier-1 banks spend at least $10m annually on core
    | banking licences alone, dollar-priced, and devaluation nearly doubled that
    | cost in naira terms.
    |
    | `stress_scenarios` spans a range this market has actually lived through
    | rather than a theoretical ±10%.
    |
    */
    'fx' => [
        'rates' => [
            'NGN' => 1.0,
            'USD' => (float) env('EA_FX_USD', 1550),
            'EUR' => (float) env('EA_FX_EUR', 1680),
            'GBP' => (float) env('EA_FX_GBP', 1960),
            'ZAR' => (float) env('EA_FX_ZAR', 85),
            'GHS' => (float) env('EA_FX_GHS', 105),
        ],
        'stress_scenarios' => [1200, 1550, 1800, 2200, 2600],
    ],

    /*
    |--------------------------------------------------------------------------
    | Regulatory returns (A1 / §6.2)
    |--------------------------------------------------------------------------
    |
    | Due dates carry verification warnings in the source document and are
    | therefore configurable:
    |
    |   ⚠️ CSAT — §6.2 gives 28 February, noting "the exposure draft said
    |      31 March; confirm against the signed circular".
    |   ⚠️ NDPC CAR — 31 March annually, but the 2025 cycle was extended to
    |      30 May 2026 and "extensions appear to be a pattern".
    |
    | `minimum_evidence_confidence` is the floor below which the compiler
    | refuses a signature. The April 2026 CSAT circular makes false or
    | misleading data a regulatory breach under BOFIA 2020, and the return is
    | CISO-signed — signing blind should not be a thing the software allows.
    |
    */
    'returns' => [
        'csat_due' => env('EA_CSAT_DUE', '02-28'),
        'ndpc_car_due' => env('EA_NDPC_CAR_DUE', '03-31'),
        'minimum_evidence_confidence' => (int) env('EA_RETURN_MIN_CONFIDENCE', 30),
    ],

];
