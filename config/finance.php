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
        ['1850', 'Right-of-use assets (IFRS 16)', 'balance_sheet'], ['2201', 'Lease liabilities (IFRS 16)', 'balance_sheet'],
    ],

    'import_max_rows' => 5000,

    // P1 budgeting (ATH-FIN-001 §3.2, §5.1)
    'fx' => [
        'rate_types' => ['spot', 'average', 'closing', 'budget'],
        // When no rate of the requested type exists on or before the date, try these in order.
        'fallback' => ['budget' => ['spot'], 'average' => ['spot'], 'closing' => ['spot'], 'spot' => []],
        'planning_rate_type' => 'budget', // budget lines convert at this type on the fiscal-year start date
    ],
    'phasing' => ['seasonal' => [6, 7, 8, 8, 8, 8, 8, 8, 9, 9, 10, 11]], // weights by period sequence 1..12
    'transfers' => ['allow_capex_opex' => false],
    // Tables that hold money; the base currency is frozen once any has a row (P3+ append).
    'money_tables' => ['fin_budget_lines', 'fin_budget_transfers', 'fin_supplementary_requests', 'fin_contracts', 'fin_contract_renewals',
        'fin_expense_requests', 'fin_purchase_orders', 'fin_invoices', 'fin_actuals', 'fin_recurring_expenses', 'fin_accruals'],
    'workbook' => ['max_rows' => 2000, 'max_kb' => 4096],
    'approval_defaults' => [ // base-currency thresholds that FinancePolicySeeder maps onto each bank's bands
        'budget_md_ceo_from' => 250000000,
        'supplementary_cio_from' => 50000000,
        'transfer_cio_from' => 50000000,
        'renewal_cfo_from' => 10000000, 'renewal_cfo_uplift_pct' => 10, // a contract renewal also needs the CFO above either threshold (plan C50)
        'expense_head_of_it_from' => 1000000, 'expense_cfo_from' => 10000000, 'expense_md_ceo_from' => 50000000,
        'invoice_sod_from' => 10000000, // fallback when fin_settings.invoice_sod_from is null
    ],

    // P2 contracts, subscriptions, renewals and calendar (ATH-FIN-001 §3.6, §5.4)
    'timezone' => 'Africa/Lagos', // "today" for status derivation, sweeps, the digest hour and the calendar (the app runs on UTC)
    'contracts' => [
        'default_notice_period_days' => 90, // FIN-01.09 default; no fin_settings column yet (plan C57)
        'catch_up_days' => 3, // T-minus tolerance for a missed sweep (plan C60)
        'escalation_days_before_notice' => 7,
        'terminate_backdate_days' => 30, // an early termination may be dated at most this many days back
        'escalation_job_titles' => ['Chief Information Officer', 'Chief Financial Officer'],
        'finance_roles' => ['IT Finance Controller'],
        'calendar' => ['ics_past_days' => 90, 'ics_future_days' => 548, 'max_range_days' => 400],
        'document_max_kb' => 20480,
    ],

    // P3 expenses, procurement, invoices and actuals (ATH-FIN-001 §3.3)
    'expenses' => ['justification_min' => 20, 'unbudgeted_band_bump' => 1],
    'invoices' => ['due_offsets' => [7, 0], 'overdue_offsets' => [1, 7, 14, 30], 'default_payment_days' => 30, 'override_reason_min' => 10],
    'actuals' => ['max_kb' => 8192, 'date_formats' => ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-M-Y', 'd.m.Y']],
    'recurring' => ['catch_up_max' => 12],
    // P4 monitoring, variance, period close and reports (ATH-FIN-001 §3.4, §3.7)
    'monitoring' => [
        'explain_amber_at_close' => false, // amber notes only warn at close
        'allow_early_close' => false, // D10: a period cannot close before its end date
        'override_reason_min' => 10,
        'close_reminder_working_days' => [5],
        'close_overdue_working_days' => [3], // still open 3 working days after the end
        'top_variances' => 10,
    ],
    'reports' => ['max_rows' => 20000, 'pdf_max_rows' => 1500, 'preview_rows' => 50],
];
