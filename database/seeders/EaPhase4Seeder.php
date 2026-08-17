<?php

namespace Database\Seeders;

use App\Models\Ea\ConsentPurpose;
use App\Models\Ea\DataFlow;
use App\Models\Ea\Diagram;
use App\Models\Ea\DpiaAssessment;
use App\Models\Ea\Driver;
use App\Models\Ea\EaApplication;
use App\Models\Ea\GlossaryTerm;
use App\Models\Ea\Goal;
use App\Models\Ea\KriDefinition;
use App\Models\Ea\Outcome;
use App\Models\Ea\Stakeholder;
use App\Models\Ea\ThreatModel;
use App\Models\Ea\ThreatTechnique;
use App\Services\Ea\AnomalyEngine;
use App\Services\Ea\KriCalculator;
use App\Services\Ea\TechObsolescenceService;
use Illuminate\Database\Seeder;

class EaPhase4Seeder extends Seeder
{
    public function run(): void
    {
        $this->seedKris();
        $this->seedMotivation();
        $this->seedGlossary();
        $this->seedConsent();
        $this->seedThreatModels();
        $this->seedDiagrams();
        $this->seedDpia();

        // Run the obsolescence + KRI + anomaly calculations so the dashboards have data.
        (new TechObsolescenceService)->recompute();
        (new KriCalculator)->compute();
        (new AnomalyEngine)->run();
    }

    private function seedKris(): void
    {
        $kris = [
            ['EA-KRI-01', 'Applications without owner', 'portfolio', 'higher_worse', 0, 5, 20, 'count'],
            ['EA-KRI-02', 'Critical apps with no DR plan in last 12m', 'compliance', 'higher_worse', 0, 1, 5, 'count'],
            ['EA-KRI-03', 'Tech components within 12-month EOL with no replacement', 'tech-debt', 'higher_worse', 0, 3, 10, 'count'],
            ['EA-KRI-04', 'Open ARB exceptions past expiry', 'compliance', 'higher_worse', 0, 1, 5, 'count'],
            ['EA-KRI-05', 'Cross-border data flows without DPIA', 'security', 'higher_worse', 0, 1, 3, 'count'],
            ['EA-KRI-06', 'Capabilities at maturity Level 1', 'portfolio', 'higher_worse', 5, 15, 30, 'count'],
            ['EA-KRI-07', 'Standards exceptions issued in last quarter', 'compliance', 'higher_worse', 5, 15, 30, 'count'],
            ['EA-KRI-08', 'Vendor concentration — apps from single vendor', 'security', 'higher_worse', 5, 15, 30, 'count'],
            ['EA-KRI-09', 'Initiatives delivered on-time (12m rolling)', 'delivery', 'higher_better', 80, 60, 40, 'percent'],
            ['EA-KRI-10', 'Plateau drift — applications outside target plateau', 'portfolio', 'higher_worse', 5, 15, 30, 'percent'],
        ];
        foreach ($kris as [$code, $name, $cat, $dir, $g, $a, $r, $unit]) {
            KriDefinition::firstOrCreate(['code' => $code], [
                'name' => $name, 'category' => $cat, 'direction' => $dir,
                'threshold_green' => $g, 'threshold_amber' => $a, 'threshold_red' => $r, 'unit' => $unit,
            ]);
        }
    }

    private function seedMotivation(): void
    {
        $goals = [
            ['EA-GOAL-01', 'Become Nigeria\'s leading digital-first bank', 'high', '3y', 65],
            ['EA-GOAL-02', 'Achieve CBN-EA Maturity Level 4 by 2027', 'high', '3y', 40],
            ['EA-GOAL-03', 'NDPA Article 32 full attestation', 'high', 'annual', 78],
            ['EA-GOAL-04', 'Reduce technology obsolescence to <5% of stack', 'medium', '3y', 35],
            ['EA-GOAL-05', 'Cut TCO of non-strategic apps by 30%', 'medium', '3y', 22],
        ];
        foreach ($goals as [$code, $name, $priority, $horizon, $progress]) {
            $g = Goal::firstOrCreate(['code' => $code], [
                'name' => $name, 'priority' => $priority, 'horizon' => $horizon, 'progress_percent' => $progress,
                'description' => 'Strategic objective tracked by the Board of Directors.',
            ]);
            Outcome::firstOrCreate(['code' => $code.'-O1'], [
                'goal_id' => $g->id,
                'name' => "Measurable outcome — {$name}",
                'measure_unit' => 'percent', 'baseline' => 30, 'target' => 80, 'actual' => $progress,
            ]);
        }

        $drivers = [
            ['EA-DRV-01', 'CBN Risk-Based Cybersecurity Framework v2.0 (May 2026)', 'regulatory'],
            ['EA-DRV-02', 'NDPA enforcement & NDPC fines exposure', 'regulatory'],
            ['EA-DRV-03', 'Open Banking interoperability mandate', 'regulatory'],
            ['EA-DRV-04', 'Customer experience pressure (mobile-first competitors)', 'market'],
            ['EA-DRV-05', 'Cost-to-income ratio target <50%', 'internal'],
        ];
        foreach ($drivers as [$code, $name, $origin]) {
            Driver::firstOrCreate(['code' => $code], ['name' => $name, 'origin' => $origin, 'classification' => 'strategic']);
        }

        $stakeholders = [
            ['EA-STK-01', 'Chief Information Officer', 'CIO', 'high', 'high'],
            ['EA-STK-02', 'Chief Information Security Officer', 'CISO', 'high', 'high'],
            ['EA-STK-03', 'Chief Data Officer / DPO', 'DPO', 'high', 'high'],
            ['EA-STK-04', 'Head of Architecture', 'Head of EA', 'high', 'high'],
            ['EA-STK-05', 'CBN Examiner', 'External regulator', 'high', 'medium'],
            ['EA-STK-06', 'Internal Audit', 'Audit', 'medium', 'high'],
        ];
        foreach ($stakeholders as [$code, $name, $role, $influence, $interest]) {
            Stakeholder::firstOrCreate(['code' => $code], ['name' => $name, 'role' => $role, 'influence' => $influence, 'interest' => $interest]);
        }
    }

    private function seedGlossary(): void
    {
        $terms = [
            ['Architecture Repository', 'business', 'A persisted store of architecture artefacts — capabilities, applications, interfaces, principles, plateaux — that supports change-impact, compliance and modernisation analyses.'],
            ['ArchiMate', 'technical', 'An Open Group enterprise-architecture modelling language, currently at version 3.2.'],
            ['BIAN', 'business', 'Banking Industry Architecture Network — a reference model of banking business capabilities (v12 released 2024).'],
            ['BFTF', 'business', 'Business-Fit × Technical-Fit grid: a 5×5 matrix used to rationalise the application portfolio.'],
            ['Blast Radius', 'technical', 'The set of components affected by a change to or outage of a given component, surfaced via graph traversal.'],
            ['CBN-EA', 'regulatory', 'Central Bank of Nigeria Enterprise Architecture domain of the Risk-Based Cybersecurity Framework.'],
            ['NDPA', 'regulatory', 'Nigeria Data Protection Act 2023 — primary privacy regulation enforced by the NDPC.'],
            ['DPIA', 'regulatory', 'Data Protection Impact Assessment — required under NDPA for high-risk processing of personal data.'],
            ['Plateau', 'technical', 'A relatively stable, internally consistent state of the architecture, usually current / target / transition.'],
            ['TIME', 'business', 'Tolerate / Invest / Migrate / Eliminate — Gartner-style application-rationalisation scoring.'],
            ['6R', 'business', 'Rehost / Replatform / Repurchase / Refactor / Retire / Retain — cloud-migration disposition.'],
            ['MCP', 'technical', 'Model Context Protocol — Anthropic\'s standard for connecting LLM agents to external tools.'],
        ];
        foreach ($terms as [$term, $category, $definition]) {
            GlossaryTerm::firstOrCreate(['term' => $term], ['category' => $category, 'definition' => $definition, 'status' => 'approved']);
        }
    }

    private function seedConsent(): void
    {
        $purposes = [
            ['EA-CP-01', 'Customer onboarding (KYC)', 'legal_obligation', '7 years', false],
            ['EA-CP-02', 'Account servicing', 'contract', '7 years', false],
            ['EA-CP-03', 'Marketing communications', 'consent', '12 months', false],
            ['EA-CP-04', 'Cross-border correspondent banking', 'contract', '7 years', true],
            ['EA-CP-05', 'Fraud detection analytics', 'legitimate_interest', '5 years', false],
            ['EA-CP-06', 'Regulatory reporting (CBN / NFIU)', 'legal_obligation', '10 years', false],
        ];
        foreach ($purposes as [$code, $purpose, $basis, $retention, $crossBorder]) {
            ConsentPurpose::firstOrCreate(['code' => $code], [
                'purpose' => $purpose, 'lawful_basis' => $basis, 'retention_period' => $retention, 'cross_border' => $crossBorder,
                'description' => "Lawful basis: {$basis}. Retention: {$retention}.",
            ]);
        }
    }

    private function seedThreatModels(): void
    {
        $apps = EaApplication::limit(3)->get();
        foreach ($apps as $a) {
            $tm = ThreatModel::firstOrCreate(
                ['code' => 'TM-'.$a->code],
                [
                    'name' => "STRIDE — {$a->name}",
                    'subject_type' => EaApplication::class,
                    'subject_id' => $a->id,
                    'methodology' => 'STRIDE',
                    'risk_score' => rand(30, 70) / 10,
                    'status' => 'active',
                ]
            );
            $techniques = [
                ['Spoofing', 'T1078', 'Credential stuffing against the login endpoint', 'Enforce MFA + WAF bot-detection.'],
                ['Tampering', 'T1565', 'Man-in-the-middle on inter-service traffic', 'mTLS-everywhere + certificate pinning.'],
                ['Information Disclosure', 'T1530', 'Excessive PII in audit logs', 'Tokenise PII before log emission.'],
                ['Denial of Service', 'T1499', 'Application-layer DDoS', 'Rate-limit at the edge; autoscale critical paths.'],
            ];
            foreach ($techniques as [$cat, $mitre, $threat, $mit]) {
                ThreatTechnique::firstOrCreate(
                    ['model_id' => $tm->id, 'threat' => $threat],
                    ['category' => $cat, 'mitre_attack' => $mitre, 'mitigation' => $mit, 'likelihood' => 3, 'impact' => 4]
                );
            }
        }
    }

    private function seedDiagrams(): void
    {
        $diagrams = [
            ['DIA-001', 'Layered View — current state', 'layered', 'approved'],
            ['DIA-002', 'Application Co-operation — core banking', 'application_cooperation', 'review'],
            ['DIA-003', 'Information Structure — customer master', 'information_structure', 'draft'],
            ['DIA-004', 'Implementation & Deployment — kubernetes platform', 'implementation_deployment', 'approved'],
            ['DIA-005', 'Risk & Security overlay', 'risk_security', 'approved'],
        ];
        foreach ($diagrams as [$code, $name, $vp, $status]) {
            Diagram::firstOrCreate(['code' => $code], [
                'name' => $name, 'viewpoint' => $vp, 'status' => $status,
                'description' => 'Seeded standard viewpoint snapshot.',
                'elements_json' => [], 'edges_json' => [], 'version' => 1,
            ]);
        }
    }

    private function seedDpia(): void
    {
        $flow = DataFlow::where('cross_border', true)->first();
        if ($flow) {
            DpiaAssessment::firstOrCreate(['code' => 'DPIA-2026-001'], [
                'subject' => 'Cross-border correspondent banking — DPIA',
                'data_flow_id' => $flow->id,
                'status' => 'approved',
                'risk_score' => 5.5,
                'risk_band' => 'medium',
                'mitigation_plan' => '1) mTLS in transit. 2) Tokenise account numbers. 3) Quarterly DPO review. 4) Standard Contractual Clauses with counterparties.',
                'dpo_decision' => 'approve_with_controls',
                'decided_on' => now()->subWeeks(3),
                'answers' => ['q1' => false, 'q2' => true, 'q3' => true, 'q6' => true, 'q7' => true, 'q9' => true],
            ]);
        }
    }
}
