<?php

/*
 * Continuous monitoring (ATH-VAL-013). Values a bank's monitoring policy sets, not constants.
 */
return [

    // A failing CCM test raises an issue (one open issue per test).
    'auto_issue' => env('CCM_AUTO_ISSUE', true),

    // An access-review revocation not confirmed by IT within this many days is chased.
    'revocation_days' => 7,

    // Unauthorised drift at or above this severity raises an issue when detected.
    'drift_issue_severities' => ['critical'],
];
