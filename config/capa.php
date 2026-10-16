<?php

/*
 * Integrated CAPA (IT-GOV-CAPA-001 v3.0). The Issues register is the CAPA register; an issue
 * becomes a CAPA when a framework path is chosen for it (issues.capa_framework).
 *
 * Regulatory references below are taken from the policy document and have NOT been checked
 * against the issued CBN texts (section numbers, titles). They are configuration, not
 * constants, so Compliance can correct them without a release ('verified' => false).
 */
return [

    'enabled' => env('CAPA_ENABLED', true),

    /* §2 / §4 stage 1, 3 and 6 — the three framework paths. */
    'frameworks' => [
        'iso27001' => [
            'label' => 'ISO/IEC 27001:2022',
            'focus' => 'Information Security Management Systems (ISMS) & Compliance',
            'classifications' => [
                'nonconformity' => 'Nonconformity',
            ],
            'references' => [
                'iso27001_10_1' => 'Clause 10.1 — Continual improvement',
                'iso27001_10_2' => 'Clause 10.2 — Nonconformity & corrective action',
            ],
            'classification_hint' => 'A nonconformity affecting the confidentiality, integrity or availability of an asset.',
            'rca_focus' => 'Isolate the breakdown in information security controls (e.g. failed access reviews, missing policy).',
            'verification_criteria' => [
                'risk_within_threshold' => 'Residual risk is within the bank\'s acceptable threshold',
                'nonconformity_mitigated' => 'The nonconformity is mitigated and the control operates as designed',
                'evidence_retained' => 'Evidence of the corrective action is retained in the ISMS record',
                'no_new_nonconformity' => 'No new nonconformity has arisen from the change',
            ],
        ],
        'cobit2019' => [
            'label' => 'COBIT 2019',
            'focus' => 'Enterprise IT Governance, Strategy & Risk Alignment',
            'classifications' => [
                'risk_event' => 'Risk event',
                'performance_variance' => 'Performance variance',
            ],
            'references' => [
                'cobit_apo12' => 'APO12 — Managed Risk',
                'cobit_mea01' => 'MEA01 — Managed Performance & Conformance Monitoring',
            ],
            'classification_hint' => 'A risk event or a performance variance skewing enterprise alignment.',
            'rca_focus' => 'Identify gaps in capability levels, organisational structures or process metrics.',
            'verification_criteria' => [
                'metrics_at_baseline' => 'Process capability metrics have returned to their standard baselines',
                'capability_level_met' => 'The target capability level is met for the affected process',
                'risk_profile_updated' => 'The enterprise risk profile has been updated to reflect the fix',
                'governance_informed' => 'Governance bodies have been informed of the outcome',
            ],
        ],
        'itilv4' => [
            'label' => 'ITIL v4',
            'focus' => 'Service Management, Operational Incidents & Continuous Value',
            'classifications' => [
                'problem_statement' => 'Problem statement',
            ],
            'references' => [
                'itil_problem_management' => 'Problem Management practice',
                'itil_continual_improvement' => 'Continual Improvement practice',
            ],
            'classification_hint' => 'A problem statement emerging from one or more underlying operational incidents.',
            'rca_focus' => 'Isolate the underlying structural error within the IT infrastructure or configuration baseline.',
            'verification_criteria' => [
                'problem_record_closed' => 'The problem record is closed in the service management tool',
                'no_incident_recurrence' => 'No incident has recurred from this root cause during the settling period',
                'known_error_retired' => 'The known error / workaround has been retired',
                'cmdb_baseline_updated' => 'The configuration baseline (CMDB) reflects the permanent fix',
            ],
        ],
    ],

    /* §3 — mandatory CBN cross-references. Unverified against the issued texts. */
    'cbn_provisions' => [
        'cbn_itsb_3_5' => [
            'label' => 'CBN IT Standards Blueprint §3.5',
            'source' => 'CBN IT Standards Blueprint',
            'description' => 'Formal root cause analysis for persistent system downtimes, core banking platform errors and missing software patches.',
            'verified' => false,
        ],
        'cbn_itsb_4_2' => [
            'label' => 'CBN IT Standards Blueprint §4.2',
            'source' => 'CBN IT Standards Blueprint',
            'description' => 'Formal root cause analysis for persistent system downtimes, core banking platform errors and missing software patches.',
            'verified' => false,
        ],
        'cbn_rbcsf_3_1_5' => [
            'label' => 'CBN Risk-Based Cyber Security Framework §3.1.5',
            'source' => 'CBN Risk-Based Cybersecurity Framework and Guidelines',
            'description' => 'Formalised remedial action plans following any security incident, internal audit finding or penetration-testing exception.',
            'verified' => false,
        ],
        'cbn_cpr_6_0' => [
            'label' => 'CBN Consumer Protection Regulations §6.0',
            'source' => 'CBN Consumer Protection Regulations',
            'description' => 'An operational IT failure causing erroneous customer debits, mobile banking downtime or financial data leakage must trigger an immediate CAPA log.',
            'verified' => false,
        ],
    ],

    /* §4 — the six stages, then closure. */
    'stages' => [
        'identification' => 'Identification & classification',
        'containment' => 'Containment & immediate mitigation',
        'rca' => 'Root cause analysis',
        'action_planning' => 'Action plan & CBN alignment',
        'implementation' => 'Implementation & deployment',
        'verification' => 'Independent effectiveness verification',
        'closed' => 'Closed',
    ],

    /* Issue.status each stage keeps the issue in (the SLA clock follows status). */
    'stage_status' => [
        'identification' => 'open',
        'containment' => 'open',
        'rca' => 'in_progress',
        'action_planning' => 'in_progress',
        'implementation' => 'in_progress',
        'verification' => 'remediated',
        'closed' => 'closed',
    ],

    /*
     * §5 RACI. Each RACI role maps to the Spatie roles seeded by RolesAndPermissionsSeeder that
     * come closest; Super Admin bypasses. There is no dedicated "IT Operations" role, so Change
     * Manager and Application Owner stand in. Internal audit is Auditor only, so that
     * verification stays independent of the CISO (Organization Admin in the demo banks).
     */
    'raci_roles' => [
        'it_governance' => ['label' => 'IT Governance / GRC', 'roles' => ['Compliance Officer', 'Risk Manager', 'Organization Admin']],
        'it_operations' => ['label' => 'IT Infrastructure & Operations', 'roles' => ['Change Manager', 'Application Owner']],
        'infosec' => ['label' => 'Information Security / CISO', 'roles' => ['Security Analyst', 'Organization Admin']],
        'internal_audit' => ['label' => 'Internal IT Auditor', 'roles' => ['Auditor']],
    ],

    'raci' => [
        'identification' => ['it_governance' => 'A', 'it_operations' => 'R', 'infosec' => 'R', 'internal_audit' => 'C'],
        'containment' => ['it_governance' => 'C', 'it_operations' => 'R', 'infosec' => 'R', 'internal_audit' => 'I'],
        'rca' => ['it_governance' => 'A', 'it_operations' => 'R', 'infosec' => 'R', 'internal_audit' => 'I'],
        'action_planning' => ['it_governance' => 'A', 'it_operations' => 'R', 'infosec' => 'R', 'internal_audit' => 'C'],
        'implementation' => ['it_governance' => 'I', 'it_operations' => 'R', 'infosec' => 'R', 'internal_audit' => 'I'],
        'verification' => ['it_governance' => 'R', 'it_operations' => 'I', 'infosec' => 'I', 'internal_audit' => 'A'],
    ],

    /* Stages that cannot be left without a sign-off by that stage's Accountable role. */
    'signoff_required' => ['action_planning'],

    /* §4 stage 6 — settling period before effectiveness can be verified ("typically 30 to 90 days"). */
    'settling_days' => ['default' => 30, 'min' => 30, 'max' => 90],

    /*
     * §6 resolution SLA: maximum remediation window per severity, in hours. CAPAs run on these
     * windows, not on the bank's vulnerability SLA policy (which legacy issues still follow).
     */
    'sla_hours' => ['critical' => 48, 'high' => 336, 'moderate' => 720, 'low' => 2160],

    /* §6 — escalation. */
    'escalation' => [
        'critical' => [
            'notify' => ['CIO', 'CISO', 'Risk Committee'],
            'within_hours' => 2,
        ],
    ],

    /* §6 KPI targets. */
    'kpi_targets' => [
        'overdue_rate_pct' => 5,
        'recurrence_rate_pct' => 0,
    ],

    /* §3.3 — CBN CPR 6.0: customer-impacting IT failure opens a CAPA immediately. */
    'auto_trigger' => [
        'incident_customer_impact' => env('CAPA_AUTO_TRIGGER_INCIDENT', true),
        'framework' => 'itilv4',
        'classification' => 'problem_statement',
        'framework_reference' => 'itil_problem_management',
        'provisions' => ['cbn_cpr_6_0'],
    ],

    /* The UI says "Medium"; the stored value stays "moderate". */
    'severity_labels' => ['critical' => 'Critical', 'high' => 'High', 'moderate' => 'Medium', 'low' => 'Low'],
];
