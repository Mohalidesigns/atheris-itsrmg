<?php

/*
|--------------------------------------------------------------------------
| Security Operations
|--------------------------------------------------------------------------
|
| Regulatory notification clocks run from when the bank became aware of the incident (its
| detected_at, or a linked data breach's discovered_at). The values below are the commonly cited
| windows; confirm them against the current circulars before relying on them in production.
|
*/

return [

    'notification_deadline_hours' => [
        'CBN' => (int) env('SECOPS_CBN_NOTIFY_HOURS', 24),      // CBN Risk-Based Cybersecurity Framework incident reporting
        'NDPC' => (int) env('SECOPS_NDPC_NOTIFY_HOURS', 72),    // NDPA 2023 s.40 personal data breach
        'NFIU' => (int) env('SECOPS_NFIU_NOTIFY_HOURS', 24),    // suspicious transaction report
        'NAICOM' => (int) env('SECOPS_NAICOM_NOTIFY_HOURS', 72),
    ],

    // SIEM webhook ingest: signals at or above this severity raise a Security Alert automatically.
    'siem_auto_alert_severities' => ['critical', 'high'],

    // Reject webhook deliveries whose timestamp is further than this from now (replay protection).
    'siem_signature_tolerance_seconds' => 300,

];
