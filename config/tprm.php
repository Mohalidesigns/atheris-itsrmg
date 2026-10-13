<?php

/*
 * Third-party risk management (ATH-VAL-008). Values a bank's TPRM policy sets, not constants.
 */
return [

    // Months between full reassessments, by tier (1 = critical, 2 = important, 3 = standard).
    'review_months' => [1 => 12, 2 => 24, 3 => 36],

    // Tiering points (VendorTiering): tier 1 at or above `tier_1`, tier 2 at or above `tier_2`.
    'tier_thresholds' => ['tier_1' => 6, 'tier_2' => 3],

    // Residual rating implied by a completed assessment's score (0-100): lowest score for each band.
    'residual_bands' => ['low' => 80, 'medium' => 65, 'high' => 50],

    // Security-rating grades (0-100 scale): lowest score for each grade; below `D` is F.
    'grades' => ['A' => 90, 'B' => 80, 'C' => 70, 'D' => 60],

    // A rating drop of at least this many points within `drop_window_days` is flagged.
    'rating_drop_alert' => 10,
    'drop_window_days' => 30,

    'contract_warning_days' => 90,

    // A vendor never assessed is due its onboarding assessment within this many days.
    'onboarding_days' => 30,
];
