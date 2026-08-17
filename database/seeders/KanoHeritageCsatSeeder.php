<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatInstitutionProfile;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatIrResponse;
use App\Modules\CBNCSAT\Models\CsatMaResponse;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use Illuminate\Database\Seeder;

/**
 * Kano Heritage Bank CBN-CSAT 2026 assessment with ~60% completion.
 *
 * Covers:
 *  - Institution profile
 *  - 47 Inherent-risk responses (all 47 answered)
 *  - ~60% of the 494 Maturity statements answered with realistic Yes/No/N/A mix
 */
class KanoHeritageCsatSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'kano-heritage-bank')->first();
        if (! $org) {
            $this->command?->warn('Kano Heritage org not found — run KanoHeritageDemoSeeder first.');

            return;
        }

        $creator = User::where('organization_id', $org->id)
            ->where('email', 'admin@kanoheritage.ng')->first()
            ?? User::where('organization_id', $org->id)->first();

        $year = (int) now()->format('Y');

        $assessment = CsatAssessment::updateOrCreate(
            ['organization_id' => $org->id, 'assessment_year' => $year, 'framework_version' => 'FFIEC-CAT-1.1'],
            [
                'status' => 'in_progress',
                'submission_deadline' => now()->addMonths(3)->toDateString(),
                'composite_risk_level' => 'moderate',
                'composite_risk_score' => 3.1,
                'overall_maturity_level' => 'evolving',
                'ai_readiness_score' => 62,
                'ai_readiness_rag' => 'amber',
                'created_by' => $creator->id,
            ]
        );

        // Institution profile
        CsatInstitutionProfile::updateOrCreate(
            ['assessment_id' => $assessment->id],
            [
                'institution_name' => 'Kano Heritage Bank Plc',
                'cbn_licence_type' => 'dmb',
                'head_office_address' => 'Heritage Plaza, Ibrahim Taiwo Road, Kano 700241, Kano State, Nigeria',
                'ciso_name' => 'Adaeze Kunle-Usman',
                'ciso_email' => 'admin@kanoheritage.ng',
                'ciso_phone' => '+234-803-000-0101',
                'ciso_grade' => 'Executive Director',
                'ciso_reporting_line' => 'Board Risk Committee (via MD/CEO)',
                'parent_bank_name' => null,
            ]
        );

        // === Inherent risk: answer ALL 47 questions with realistic spread ===
        $irQuestions = CsatIrQuestion::orderBy('id')->get();
        $irWeights = [1 => 5, 2 => 25, 3 => 40, 4 => 20, 5 => 10]; // center-weighted "moderate"
        foreach ($irQuestions as $q) {
            CsatIrResponse::updateOrCreate(
                ['assessment_id' => $assessment->id, 'question_id' => $q->id],
                [
                    'selected_level' => $this->weighted($irWeights),
                    'comment' => $this->irComment($q->id),
                    'completed_by' => $creator->id,
                    'completed_at' => now()->subDays(rand(1, 60)),
                ]
            );
        }

        // === Maturity: answer ~60% of 494 statements ===
        $maStatements = CsatMaStatement::orderBy('id')->get();
        $target = (int) round($maStatements->count() * 0.60);
        $picked = $maStatements->shuffle()->take($target);

        // Realistic distribution: heavier Yes in baseline statements, tapering with maturity level
        $responseWeights = ['yes' => 55, 'yes_cc' => 15, 'no' => 20, 'na' => 10];

        foreach ($picked as $stmt) {
            $resp = $this->weightedKey($responseWeights);
            CsatMaResponse::updateOrCreate(
                ['assessment_id' => $assessment->id, 'statement_id' => $stmt->id],
                [
                    'response' => $resp,
                    'has_compensating_control' => $resp === 'yes_cc',
                    'comment' => $this->maComment($resp),
                    'responded_by' => $creator->id,
                    'responded_at' => now()->subDays(rand(1, 90)),
                ]
            );
        }

        $this->command?->info("Seeded Kano Heritage CSAT {$year}: 47 IR responses + {$picked->count()}/{$maStatements->count()} maturity responses (~60% complete).");
    }

    private function weighted(array $weights): int
    {
        $total = array_sum($weights);
        $r = mt_rand(1, $total);
        $cum = 0;
        foreach ($weights as $level => $w) {
            $cum += $w;
            if ($r <= $cum) {
                return $level;
            }
        }

        return array_key_last($weights);
    }

    private function weightedKey(array $weights): string
    {
        $total = array_sum($weights);
        $r = mt_rand(1, $total);
        $cum = 0;
        foreach ($weights as $k => $w) {
            $cum += $w;
            if ($r <= $cum) {
                return $k;
            }
        }

        return array_key_last($weights);
    }

    private function irComment(int $qid): ?string
    {
        if (rand(0, 100) < 30) {
            return null;
        }
        $stubs = [
            'Based on 2025 self-assessment baseline.',
            'Refreshed after Q1 2026 internal audit.',
            'Residual risk confirmed with CBN RBCSF § 3.9.3.',
            'Validated by CISO against current threat model.',
            'Includes agent-banking and USSD channel exposure.',
            'Considers NIBSS NIP volume and Interswitch dependency.',
            'NDPA-aligned on customer data handling.',
        ];

        return $stubs[array_rand($stubs)];
    }

    private function maComment(string $resp): ?string
    {
        if (rand(0, 100) < 40) {
            return null;
        }
        $map = [
            'yes' => ['Evidence on file via Evidence Vault.', 'Control design & operation verified by Internal Audit.', 'Current implementation documented in AUCS catalogue.'],
            'yes_cc' => ['Compensating control in place — network segmentation + monitoring.', 'Temporary CC: detective control via SIEM rule.', 'CC pending full remediation (see linked Issue).'],
            'no' => ['Gap identified; Issue raised in remediation register.', 'Target date Q3 2026.', 'Dependency on Finacle upgrade.'],
            'na' => ['Not applicable to Kano Heritage current scope.', 'Out of scope — referenced in exception register.'],
        ];
        $opts = $map[$resp] ?? [];

        return $opts ? $opts[array_rand($opts)] : null;
    }
}
