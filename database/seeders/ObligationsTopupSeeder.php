<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tops up Obligations register to ≥ 50 rows for Kano Heritage demo.
 * Covers: CBN, NDPC, NAICOM, NDIC, SEC Nigeria, NCC, PENCOM, BoG, CBK.
 */
class ObligationsTopupSeeder extends Seeder
{
    public function run(): void
    {
        // Resolve the tenant this top-up has always described itself as being
        // for. It wrote organisation 1 (Acme) while every row it inserts is
        // titled as a Kano Heritage obligation, so the register these rows top
        // up was not the one the demo shows.
        $orgId = Organization::where('slug', 'kano-heritage-bank')->value('id') ?? 1;

        $extras = [
            // CBN
            ['CBN', 'RBCSF-2.4-MFA', 'MFA enforcement on privileged accounts (CBN RBCSF §2.4)', 'CISO', 180],
            ['CBN', 'RBCSF-4.1-EDR', 'Endpoint detection coverage ≥ 95% (CBN RBCSF §4.1)', 'CISO', 90],
            ['CBN', 'RBCSF-5.3-VM', 'Critical vulnerability remediation ≤ 72 hours', 'CISO', 90],
            ['CBN', 'RBCSF-6.2-LOG', 'Centralised log retention ≥ 7 years (CBN RBCSF §6.2)', 'CISO', 365],
            ['CBN', 'BCM-ANNUAL-TEST', 'Annual BCP test result filed with CBN Banking Supervision', 'CRO', 365],
            ['CBN', 'NCS-CRMS-Q2', 'CBN CRMS IT return — Q2', 'Head of Compliance', 90],
            ['CBN', 'NCS-CRMS-Q3', 'CBN CRMS IT return — Q3', 'Head of Compliance', 90],
            ['CBN', 'NCS-CRMS-Q4', 'CBN CRMS IT return — Q4', 'Head of Compliance', 90],
            ['CBN', 'CYBER-QUARTERLY-DASH', 'Quarterly CISO dashboard to Board Risk Committee', 'CISO', 90],
            ['CBN', 'PCI-DSS-ROC', 'Annual PCI-DSS 4.0.1 Report on Compliance submission', 'CISO', 365],
            // NDPC
            ['NDPC', 'NDPA-§40-DSAR-Q1', 'NDPA §40 Data Subject Access Request response audit Q1', 'DPCO', 90],
            ['NDPC', 'NDPA-§40-DSAR-Q2', 'NDPA §40 Data Subject Access Request response audit Q2', 'DPCO', 90],
            ['NDPC', 'NDPA-CBD-TRANSFER', 'Cross-border data-transfer register review', 'DPCO', 180],
            ['NDPC', 'NDPR-2019-RETAIN', 'NDPR records retention register', 'DPCO', 365],
            ['NDPC', 'DPIA-Q1', 'Quarterly DPIA register refresh', 'DPCO', 90],
            // NAICOM
            ['NAICOM', 'ERM-Q2', 'Quarterly ERM return — Q2', 'CRO', 90],
            ['NAICOM', 'INSURANCE-CYBER', 'Annual cyber insurance coverage filing', 'CRO', 365],
            // NDIC
            ['NDIC', 'DEPOSIT-RETURN-Q1', 'NDIC deposit-insurance quarterly return', 'Head of Compliance', 90],
            ['NDIC', 'IT-AUDIT-ANNUAL', 'NDIC annual IT audit filing', 'Head of Internal Audit', 365],
            // SEC
            ['SEC', 'CYBER-DISCLOSURE-Q1', 'SEC Nigeria quarterly cyber disclosure', 'CISO', 90],
            ['SEC', 'MATERIAL-EVENT', 'Material event disclosure (on demand)', 'CISO', 30],
            // NCC
            ['NCC', 'ANNUAL-LICENCE-RENEWAL', 'Telecom/VAS licence annual renewal', 'Legal', 365],
            // PENCOM
            ['PENCOM', 'QUARTERLY-FILING-Q2', 'PENCOM quarterly pension filings Q2', 'HR', 90],
            ['PENCOM', 'ANNUAL-STATEMENT', 'Annual statement of pension compliance', 'HR', 365],
            // BoG (Ghana) — pan-African
            ['BoG', 'CYBER-ANNUAL-GH', 'BoG annual cyber self-assessment', 'CISO', 365],
            // CBK (Kenya)
            ['CBK', 'CYBER-GUIDANCE-KE', 'CBK cyber risk management return', 'CISO', 365],
        ];

        $inserted = 0;
        foreach ($extras as [$reg, $code, $title, $role, $cycle]) {
            $row = DB::table('obligations')
                ->where('regulator_code', $reg)
                ->where('reference_code', $code)
                ->first();
            if ($row) {
                continue;
            }
            DB::table('obligations')->insert([
                'organization_id' => $orgId,
                'regulator_code' => $reg,
                'reference_code' => $code,
                'title' => $title,
                'body_markdown' => $title.' — Kano Heritage Bank applicable obligation.',
                'effective_date' => now()->subMonths(rand(1, 18)),
                'review_cycle_days' => $cycle,
                'owner_role' => $role,
                'status' => 'active',
                'applicability' => json_encode(['dmb' => true, 'mfb' => in_array($reg, ['CBN', 'NDIC'])]),
                'evidence_requirement' => 'Signed evidence pack with supporting artefacts, timestamped.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }
        $this->command?->info("Obligations top-up: inserted {$inserted} rows.");
    }
}
