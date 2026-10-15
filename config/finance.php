<?php

/**
 * IT Financial Management (ATH-FIN-001). Every default and regulatory value lives here so it
 * can be changed per bank without a code edit; none of it is a constant in the services.
 */
return [
    'settings_defaults' => [
        'fiscal_year_start_month' => 1, 'period_scheme' => 'monthly',
        'variance_amber_pct' => 5, 'variance_red_pct' => 10, 'variance_abs_floor' => 500000,
        'renewal_lead_days' => 90, 'renewal_notice_offsets' => [180, 90, 60, 30, 14, 7, 0],
        'escalation_role' => 'Head of IT', 'overbudget_mode' => 'warn',
        'match_tolerance_pct' => 2, 'match_tolerance_abs' => 50000, 'eac_method' => 'remaining_budget',
        'showback_enabled' => false, 'digest_hour' => 7, 'fx_provider' => null,
        // Base currency; min inclusive, max exclusive; the last max is null (open-ended).
        'doa_bands' => [
            ['key' => '0-1m', 'label' => 'Up to 1m', 'min' => 0, 'max' => 1000000],
            ['key' => '1m-10m', 'label' => '1m to 10m', 'min' => 1000000, 'max' => 10000000],
            ['key' => '10m-50m', 'label' => '10m to 50m', 'min' => 10000000, 'max' => 50000000],
            ['key' => '50m-250m', 'label' => '50m to 250m', 'min' => 50000000, 'max' => 250000000],
            ['key' => '250m+', 'label' => 'Above 250m', 'min' => 250000000, 'max' => null],
        ],
        'investment_score_weights' => ['strategic_alignment' => 25, 'regulatory' => 25, 'risk_reduction' => 20, 'financial_return' => 20, 'delivery_risk' => 10],
    ],

    // Catalogue for "add currency": code => [name, symbol, decimals]. Seeded per bank = org currency + default_currencies.
    'currencies' => [
        'NGN' => ['Nigerian naira', '₦', 2], 'USD' => ['US dollar', '$', 2], 'GBP' => ['Pound sterling', '£', 2], 'EUR' => ['Euro', '€', 2],
        'GHS' => ['Ghanaian cedi', 'GH₵', 2], 'KES' => ['Kenyan shilling', 'KSh', 2], 'ZAR' => ['South African rand', 'R', 2], 'XOF' => ['West African CFA franc', 'CFA', 0],
    ],
    'default_currencies' => ['USD', 'GBP', 'EUR'],

    // Nigeria defaults (ATH-FIN-001 FIN-01.06). UNVERIFIED against the Nigeria Tax Act 2025 WHT schedule; editable per bank.
    'tax_types' => [
        ['code' => 'VAT', 'name' => 'Value Added Tax 7.5%', 'kind' => 'vat', 'rate' => 7.5, 'is_inclusive' => false, 'applies_to' => ['expense', 'invoice']],
        ['code' => 'WHT-5', 'name' => 'Withholding tax 5% (supplies, contracts)', 'kind' => 'wht', 'rate' => 5, 'is_inclusive' => false, 'applies_to' => ['invoice']],
        ['code' => 'WHT-10', 'name' => 'Withholding tax 10% (professional / consultancy services)', 'kind' => 'wht', 'rate' => 10, 'is_inclusive' => false, 'applies_to' => ['invoice']],
        ['code' => 'NONE', 'name' => 'No tax / exempt', 'kind' => 'none', 'rate' => 0, 'is_inclusive' => false, 'applies_to' => ['expense', 'invoice']],
    ],

    // Starter chart of accounts, editable: [code, name, type].
    'gl_accounts' => [
        ['1810', 'Computer hardware', 'capex'], ['1820', 'Software and intangibles', 'capex'], ['1830', 'IT capital work-in-progress', 'capex'],
        ['1401', 'Prepaid IT expenses', 'balance_sheet'], ['2101', 'Accrued IT expenses', 'balance_sheet'],
        ['6101', 'IT staff costs', 'opex'], ['6102', 'IT contractors and external labour', 'opex'],
        ['6201', 'Software licences and subscriptions', 'opex'], ['6202', 'Software maintenance and support (AMC)', 'opex'],
        ['6203', 'Hardware maintenance', 'opex'], ['6301', 'Telecoms and connectivity', 'opex'], ['6302', 'Cloud services', 'opex'],
        ['6401', 'IT consultancy and outsourced services', 'opex'], ['6501', 'Data centre facilities and power', 'opex'],
        ['6601', 'IT training', 'opex'], ['6901', 'Other IT expenses', 'opex'],
    ],

    'import_max_rows' => 5000,
];
