<?php

/*
 * PCI DSS validation (ATH-VAL-011). Values the bank's acquirer and card brands set, not constants.
 */
return [
    // A deposit-money bank that issues and acquires is normally a Level 1 entity validated by a
    // Report on Compliance from a QSA.
    'validation_type' => env('PCI_VALIDATION_TYPE', 'Report on Compliance (Level 1, QSA-assessed)'),

    // Validation is annual.
    'validation_months' => 12,
];
