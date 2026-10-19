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
        'fin_expense_requests', 'fin_purchase_orders', 'fin_invoices', 'fin_actuals', 'fin_recurring_expenses', 'fin_accruals', 'fin_cloud_cost_lines', 'fin_capitalisations'],
    'workbook' => ['max_rows' => 2000, 'max_kb' => 4096],
    'approval_defaults' => [ // base-currency thresholds that FinancePolicySeeder maps onto each bank's bands
        'budget_md_ceo_from' => 250000000,
        'supplementary_cio_from' => 50000000,
        'transfer_cio_from' => 50000000,
        'renewal_cfo_from' => 10000000, 'renewal_cfo_uplift_pct' => 10, // a contract renewal also needs the CFO above either threshold (plan C50)
        'expense_head_of_it_from' => 1000000, 'expense_cfo_from' => 10000000, 'expense_md_ceo_from' => 50000000,
        'invoice_sod_from' => 10000000, // fallback when fin_settings.invoice_sod_from is null
        'business_case_board_from' => 250000000, // a business case or change at or above this base amount also needs the board (P5-5)
        'capitalisation_cfo_from' => 50000000, // a capitalisation at or above this base amount also needs the CFO (P6-11)
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

    // KRI codes whose values are hidden from viewers without `view it-finance-amounts` (security M2, used from P5 WP-B)
    'masked_kri_codes' => ['KRI-FIN-VAR', 'KRI-FIN-UNB'],

    // P5 IT investment and value realisation (ATH-FIN-001 §3.5)
    'investments' => [
        'approval_gates' => ['approved', 'delivered'], // gates routed through finance.business_case_approval ('change' always, when over tolerance)
        'default_discount_rate_pct' => 15, // demo hurdle rate, unverified; fin_settings overrides
        'default_horizon_years' => 5, 'max_horizon_years' => 15,
        'default_cost_tolerance_pct' => 10, 'schedule_tolerance_days' => 30,
        'value_review_offsets_months' => ['pir_3m' => 3, 'pir_6m' => 6, 'pir_12m' => 12],
        'value_review_lead_days' => 14, 'value_review_overdue_offsets' => [1, 7, 14, 30],
        'cost_outcome_band_pct' => 5,
        'health' => ['cpi_at_risk' => '0.95', 'cpi_off_track' => '0.85'],
        'reason_min' => 20,
        'irr' => ['guess' => 0.10, 'max_iterations' => 100, 'tolerance' => 1e-10, 'low' => -0.99, 'high' => 10.0],
        'gate_criteria' => [ // [key, label, auto]; auto keys are evaluated by StageGateService::criteria(); manual keys must be ticked
            'business_case' => [['sponsor_named', 'Sponsor named', true], ['manager_named', 'Manager named', true], ['cost_centre_set', 'Cost centre set', true], ['problem_agreed', 'Problem statement and options agreed with the sponsor', false]],
            'approved' => [['business_case_complete', 'Business case complete (costs, horizon, rate)', true], ['scored', 'All five criteria scored', true], ['risks_recorded', 'Risks recorded (summary or linked risk)', true], ['regulatory_reference', 'Regulatory reference (regulatory investments)', true], ['options_considered', 'Options considered and recommendation stated', false]],
            'in_delivery' => [['funded', 'Funded: at least one baseline budget line tagged', true], ['start_date', 'Start date set', true], ['delivery_plan', 'Delivery plan and governance agreed', false]],
            'delivered' => [['actual_end', 'Actual end date given', true], ['acceptance', 'User acceptance signed off', false], ['handover', 'Handed over to operations', false]],
            'benefits_tracking' => [['benefits_registered', 'At least one benefit with owner, target and due date', true], ['reviews_scheduled', 'Value reviews scheduled', true]],
            'closed' => [['reviews_done', 'No value review scheduled, due or overdue', true], ['benefits_final', 'Every benefit has a final status', true], ['lessons', 'Lessons learned recorded', false]],
        ],
    ],

    // P6 cloud cost / FinOps, allocation and showback, capitalisation, application TCO (ATH-FIN-001 §3.3, §3.5)
    'cloud' => [
        'providers' => ['aws' => 'Amazon Web Services', 'azure' => 'Microsoft Azure', 'gcp' => 'Google Cloud', 'oci' => 'Oracle Cloud', 'other' => 'Other'],
        'dimensions' => ['cost_centre' => 'Cost centre', 'ea_application' => 'EA application', 'business_service' => 'Business service'],
        'max_rows' => 200000, 'max_kb' => 51200, 'chunk' => 500,
        'anomaly' => ['default_pct' => 30, 'default_abs_floor' => 250000, 'baseline_days' => 7, 'min_baseline_days' => 5, 'lookback_days' => 3, 'kri_window_days' => 30],
        'close_requires_import' => false,
        'formats' => [ // canonical => candidate header names (first present wins); tag_prefix/tags_json/tags_kv describe tags; account => id column checked against account_identifier
            'aws_cur' => ['date' => ['lineItem/UsageStartDate'], 'service' => ['product/ProductName', 'lineItem/ProductCode'], 'resource_id' => ['lineItem/ResourceId'],
                'region' => ['product/region', 'product/regionCode'], 'cost' => ['lineItem/UnblendedCost'], 'currency' => ['lineItem/CurrencyCode'], 'account' => ['lineItem/UsageAccountId'], 'tag_prefix' => 'resourceTags/user:'],
            'azure' => ['date' => ['Date', 'UsageDate'], 'service' => ['ServiceName', 'MeterCategory'], 'resource_id' => ['ResourceId', 'InstanceId'], 'region' => ['ResourceLocation'],
                'cost' => ['CostInBillingCurrency', 'Cost', 'PreTaxCost'], 'currency' => ['BillingCurrencyCode', 'BillingCurrency', 'Currency'], 'account' => ['SubscriptionId'], 'tags_json' => ['Tags']],
            'gcp' => ['date' => ['usage_start_time'], 'service' => ['service.description'], 'resource_id' => ['resource.name', 'sku.description'], 'region' => ['location.region'],
                'cost' => ['cost'], 'currency' => ['currency'], 'account' => ['project.id'], 'tags_json' => ['labels'], 'tag_prefix' => 'labels.'],
            'generic' => ['date' => ['date'], 'service' => ['service'], 'resource_id' => ['resource_id'], 'region' => ['region'], 'cost' => ['cost'], 'currency' => ['currency'],
                'account' => ['account_id'], 'tags_kv' => ['tags'], 'tag_prefix' => 'tag:'],
        ],
    ],
    'allocations' => ['source_precedence' => ['budget_line', 'contract', 'category', 'cost_centre']],
    'showback' => ['default_units' => [], 'chargeback_debit_gl' => '6950', 'chargeback_credit_gl' => '6951', 'void_reason_min' => 10],
    'capitalisation' => [
        'standards' => ['ias_38' => 'IAS 38 intangible (software)', 'ias_16' => 'IAS 16 property, plant and equipment (hardware)'],
        'checklists' => [
            'ias_38' => [['technical_feasibility', 'Technically feasible to complete for use (IAS 38.57a)'], ['intention', 'Intention to complete and use (57b)'], ['ability_to_use', 'Ability to use the asset (57c)'],
                ['future_benefits', 'How it will generate probable future economic benefits (57d)'], ['resources', 'Adequate technical, financial and other resources to complete (57e)'],
                ['reliable_measurement', 'Development expenditure reliably measured (57f)'], ['exclusions', 'Research-phase, training and admin overheads excluded (IAS 38.54, .67)']],
            'ias_16' => [['future_benefits', 'Probable future economic benefits (IAS 16.7a)'], ['reliable_measurement', 'Cost reliably measurable (16.7b)'], ['use_beyond_year', 'Expected to be used for more than one period (16.6)']],
        ],
        'note_min' => 10, 'max_life_months' => 600, 'default_life_months' => ['ias_38' => 60, 'ias_16' => 48],
        'investment_stages' => ['in_delivery', 'delivered', 'benefits_tracking', 'closed'],
        'expense_statuses' => ['received', 'invoiced', 'closed'],
        'asset_category' => 'Capitalised IT asset',
    ],
];
