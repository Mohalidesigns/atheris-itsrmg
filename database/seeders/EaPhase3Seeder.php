<?php

namespace Database\Seeders;

use App\Models\Ea\EvidencePack;
use App\Models\Ea\ExchangeJob;
use App\Models\Ea\Initiative;
use App\Models\Ea\KriDefinition;
use App\Models\Ea\KriValue;
use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\Pattern;
use App\Models\Ea\Plateau;
use App\Models\Ea\Solution;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class EaPhase3Seeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Seeding EA-Studio Phase 3 demo data…');
        $tenantId = Organization::where('slug', 'first-bank-nigeria')->value('id') ?? 1;

        $this->plateaux($tenantId);
        $this->initiatives($tenantId);
        $this->patterns($tenantId);
        $this->solutions($tenantId);
        $this->kris($tenantId);
        $this->exchange($tenantId);
        $this->evidencePacks($tenantId);
    }

    private function plateaux(int $tenantId): void
    {
        if (Plateau::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $items = [
            ['PL-CUR', 'Current State 2026', 'current', now()->subYear(), now()],
            ['PL-T1', 'Transition 2026 H2', 'transition', now(), now()->addMonths(6)],
            ['PL-T2', 'Transition 2027 H1', 'transition', now()->addMonths(6), now()->addYear()],
            ['PL-TGT', 'Target State 2028', 'target', now()->addYear(), now()->addYears(2)],
        ];
        foreach ($items as [$code, $name, $type, $from, $to]) {
            Plateau::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name,
                'plateau_type' => $type,
                'effective_from' => $from, 'effective_to' => $to,
                'description' => $name.' — plateau for FBN EA transformation.',
            ]);
        }
    }

    private function initiatives(int $tenantId): void
    {
        if (Initiative::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $plateaux = Plateau::where('organization_id', $tenantId)->pluck('id', 'code')->toArray();
        $items = [
            ['Finacle Upgrade to 11.6', 'in_flight', 'B', 35, 150_000_000, 'PL-T1'],
            ['Retire AIX legacy CRMS', 'in_flight', 'F', 60, 80_000_000, 'PL-T1'],
            ['Zero-Trust Architecture Programme', 'approved', 'A', 15, 400_000_000, 'PL-T1'],
            ['Adopt Kafka Event Backbone', 'in_flight', 'E', 45, 120_000_000, 'PL-T1'],
            ['FirstMobile 3.0 Re-platform', 'in_flight', 'C', 55, 200_000_000, 'PL-T1'],
            ['Cloud Landing Zone (AWS)', 'in_flight', 'D', 70, 100_000_000, 'PL-T1'],
            ['SWIFT CSP Attestation 2026', 'delivered', 'H', 100, 25_000_000, 'PL-CUR'],
            ['NDPA Automation — DSAR Workflow', 'approved', 'A', 10, 35_000_000, 'PL-T2'],
            ['Fraud Platform Modernisation', 'proposed', 'A', 0, 180_000_000, 'PL-T2'],
            ['SOC 24/7 Optimisation', 'in_flight', 'G', 50, 60_000_000, 'PL-T1'],
            ['Contact Centre Migration to Cloud', 'approved', 'B', 20, 75_000_000, 'PL-T2'],
            ['Digital Onboarding STP', 'in_flight', 'C', 40, 90_000_000, 'PL-T1'],
            ['Islamic Banking Core Upgrade', 'on_hold', 'A', 5, 220_000_000, 'PL-T2'],
            ['Treasury Murex Upgrade', 'proposed', 'A', 0, 140_000_000, 'PL-TGT'],
            ['Retire Legacy SunGard Back-office', 'approved', 'B', 10, 50_000_000, 'PL-T2'],
        ];
        foreach ($items as $i => [$name, $status, $phase, $progress, $budget, $plateauCode]) {
            Initiative::create([
                'organization_id' => $tenantId,
                'code' => 'FBN-INIT-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => $name.' — portfolio initiative aligned to EA-Studio roadmap.',
                'status' => $status,
                'start_date' => now()->subMonths(rand(1, 6))->toDateString(),
                'target_end_date' => now()->addMonths(rand(3, 15))->toDateString(),
                'budget_ngn' => $budget,
                'plateau_id' => $plateaux[$plateauCode] ?? null,
                'adm_phase' => $phase,
                'linked_capabilities' => [rand(1, 40)],
                'linked_applications' => [rand(1, 50)],
                'linked_risks' => [rand(1, 40)],
                'linked_obligations' => [rand(1, 50)],
                'progress_percent' => $progress,
            ]);
        }
    }

    private function patterns(int $tenantId): void
    {
        if (Pattern::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $items = [
            ['API-LED', 'API-led integration', 'integration', 'Decouple systems via well-versioned APIs.',
                'Enterprise integrating multiple systems with differing lifecycles.',
                'API gateway, system/process/experience APIs, observability.',
                'Governance overhead vs loose coupling.',
                'Higher initial design cost; dramatically easier change later.'],
            ['EDA-CORE', 'Event-driven core', 'integration', 'Core emits events rather than being polled.',
                'High-throughput core banking events consumed by many downstream.',
                'Kafka, schema registry, consumer groups, dead-letter queue.',
                'Eventual consistency tolerance required.',
                'Decoupling + replay + time-travel debugging.'],
            ['ZT-ACCESS', 'Zero-trust access', 'security', 'Never trust, always verify; identity-aware proxies.',
                'Perimeter-less environment; SaaS + cloud-native + on-prem mix.',
                'Identity provider, policy engine, enforcement point.',
                'Session / policy complexity.',
                'Reduced blast radius of credential theft.'],
            ['CUST-ONBOARD', 'Digital customer onboarding', 'business-process', 'Straight-through digital onboarding with KYC/AML.',
                'Nigerian DMB onboarding retail customers via FirstMobile.',
                'BVN/NIN verifier, AML screening, eKYC camera capture, digital signature.',
                'Fraud vs conversion trade-off.',
                'Drives deposit growth; reduces branch cost-to-serve.'],
            ['REG-REPORTING', 'Regulatory reporting factory', 'compliance', 'Single data mart feeds all regulator returns.',
                'Multiple regulators requiring different cuts of the same data.',
                'Data mart, mappings, reviewer workflow, evidence vault.',
                'Data-lineage rigour required.',
                'Submission on-time rate > 99%; examiner satisfaction.'],
        ];
        foreach ($items as $i => [$code, $name, $cat, $intent, $ctx, $parts, $forces, $conseq]) {
            Pattern::create([
                'organization_id' => $tenantId,
                'code' => 'FBN-PAT-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'category' => $cat,
                'intent' => $intent,
                'context' => $ctx,
                'participants' => $parts,
                'forces' => $forces,
                'consequences' => $conseq,
                'version' => '1.0',
                'status' => 'published',
                'adoption_count' => rand(1, 6),
            ]);
        }
    }

    private function solutions(int $tenantId): void
    {
        if (Solution::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $patterns = Pattern::where('organization_id', $tenantId)->pluck('id', 'code')->toArray();
        $initiatives = Initiative::where('organization_id', $tenantId)->pluck('id', 'name')->toArray();
        $items = [
            ['SA-001', 'FirstMobile 3.0 Solution', 'API-LED', 'FirstMobile 3.0 Re-platform'],
            ['SA-002', 'Kafka Core Events Solution', 'EDA-CORE', 'Adopt Kafka Event Backbone'],
            ['SA-003', 'Entra ID Zero-Trust Rollout', 'ZT-ACCESS', 'Zero-Trust Architecture Programme'],
            ['SA-004', 'Digital Onboarding STP Solution', 'CUST-ONBOARD', 'Digital Onboarding STP'],
            ['SA-005', 'CBN CRMS Factory', 'REG-REPORTING', 'NDPA Automation — DSAR Workflow'],
        ];
        foreach ($items as [$code, $name, $patternCode, $initName]) {
            Solution::create([
                'organization_id' => $tenantId,
                'code' => $code, 'name' => $name,
                'pattern_id' => $patterns[$patternCode] ?? null,
                'initiative_id' => $initiatives[$initName] ?? null,
                'notes' => 'Solution design derived from '.$patternCode.' pattern.',
                'status' => 'approved',
            ]);
        }
    }

    private function kris(int $tenantId): void
    {
        if (KriDefinition::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $defs = [
            ['EA-KRI-001', 'Application obsolescence ratio', 'portfolio', 5, 10, 20, 'higher_worse', '%'],
            ['EA-KRI-002', 'Standards compliance rate', 'compliance', 95, 85, 70, 'lower_worse', '%'],
            ['EA-KRI-003', 'Critical apps lacking documented RTO', 'portfolio', 0, 3, 8, 'higher_worse', 'count'],
            ['EA-KRI-004', 'Tech radar "hold" components in production', 'tech-debt', 3, 8, 15, 'higher_worse', 'count'],
            ['EA-KRI-005', 'ARB throughput (mean days-to-decision)', 'delivery', 10, 20, 35, 'higher_worse', 'days'],
            ['EA-KRI-006', 'Portfolio aligned to plateaux', 'delivery', 80, 65, 50, 'lower_worse', '%'],
            ['EA-KRI-007', 'Interface catalogue coverage', 'integration', 95, 85, 70, 'lower_worse', '%'],
            ['EA-KRI-008', 'Active exceptions expiring ≤30 days', 'compliance', 2, 5, 10, 'higher_worse', 'count'],
        ];
        foreach ($defs as [$code, $name, $cat, $g, $a, $r, $dir, $unit]) {
            $kri = KriDefinition::create([
                'organization_id' => $tenantId, 'code' => $code, 'name' => $name, 'category' => $cat,
                'threshold_green' => $g, 'threshold_amber' => $a, 'threshold_red' => $r,
                'direction' => $dir, 'unit' => $unit,
            ]);
            // 12 months of readings
            for ($m = 11; $m >= 0; $m--) {
                $val = $dir === 'higher_worse'
                    ? max(0, $g + rand(0, (int) $r))
                    : max(0, $r + rand(0, (int) max($g - $r, 1)));
                $status = $this->kriStatus($val, $g, $a, $r, $dir);
                KriValue::create([
                    'kri_id' => $kri->id,
                    'period' => now()->subMonths($m)->format('Y-m'),
                    'value' => $val,
                    'status' => $status,
                    'recorded_at' => now()->subMonths($m),
                ]);
            }
        }
    }

    private function kriStatus($val, $g, $a, $r, $dir): string
    {
        if ($dir === 'higher_worse') {
            if ($val <= $g) {
                return 'green';
            }
            if ($val <= $a) {
                return 'amber';
            }

            return 'red';
        }
        if ($val >= $g) {
            return 'green';
        }
        if ($val >= $a) {
            return 'amber';
        }

        return 'red';
    }

    private function exchange(int $tenantId): void
    {
        if (ExchangeJob::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $items = [
            ['export', 'completed', 450, 820, 'exchange/ea-export-2026-04.xml', 'Full tenant export — round-trip verified.'],
            ['import', 'completed', 145, 220, 'exchange/ea-import-partners-2026-04.xml', 'Partner catalogue merged successfully.'],
            ['export', 'completed', 450, 820, 'exchange/ea-export-2026-03.xml', 'Monthly routine export — zero data loss.'],
            ['import', 'failed', 0, 0, null, 'Schema mismatch — partner file uses Archi 4 extension; retry requested.'],
            ['export', 'queued', 0, 0, null, 'Queued via Reports distribution lists.'],
        ];
        foreach ($items as [$type, $status, $elements, $rels, $file, $msg]) {
            ExchangeJob::create([
                'organization_id' => $tenantId,
                'type' => $type,
                'format' => 'archimate-oef-3.2',
                'status' => $status,
                'element_count' => $elements,
                'relationship_count' => $rels,
                'file_path' => $file,
                'message' => $msg,
            ]);
        }
    }

    private function evidencePacks(int $tenantId): void
    {
        if (EvidencePack::where('organization_id', $tenantId)->count() > 0) {
            return;
        }
        $assessment = MaturityAssessment::where('organization_id', $tenantId)->first();
        for ($i = 0; $i < 3; $i++) {
            EvidencePack::create([
                'organization_id' => $tenantId,
                'code' => 'FBN-EP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => 'CBN EA Evidence Pack '.now()->subMonths($i * 3)->format('Y Q'),
                'period' => now()->subMonths($i * 3)->format('Y-m'),
                'assessment_id' => $assessment?->id,
                'overall_score' => round(3.0 + ($i * 0.15), 2),
                'pdf_path' => 'evidence-packs/fbn-ea-pack-'.($i + 1).'.pdf',
                'zip_path' => 'evidence-packs/fbn-ea-pack-'.($i + 1).'.zip',
                'status' => $i === 0 ? 'generated' : 'submitted',
                'generated_at' => now()->subMonths($i * 3),
            ]);
        }
    }
}
