<?php

/*
 * Key risk indicators (ATH-VAL-014). Values a bank's risk appetite statement sets, not constants.
 */
return [

    // A red breach raises an issue for the KRI owner (one open issue per breach).
    'auto_issue' => env('KRI_AUTO_ISSUE', true),
];
