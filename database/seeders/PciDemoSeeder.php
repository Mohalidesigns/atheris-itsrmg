<?php

namespace Database\Seeders;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\Evidence;
use App\Models\Organization;
use App\Models\User;
use App\Services\Pci\PciPosture;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PCI DSS demo data (ATH-VAL-011). Runs after IsmsDemoSeeder.
 *
 * Per bank, deterministically:
 * - scopes every asset (CDE, connected-to or out of scope) with its rationale, from what the asset
 *   is; cloud accounts and anything still under discovery review are left unscoped (deliberate gap);
 * - maps the bank's controls to PCI DSS sub-requirements by control domain, leaving the wireless and
 *   payment-page sub-requirements unmapped (deliberate gap);
 * - records last year's Report on Compliance as a completed PCI assessment on the compliance
 *   engine: mostly in place, with partial and failed sub-requirements and their findings;
 * - attaches evidence to a share of the results.
 */
class PciDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'lead' => 'compliance', 'name' => 'Kano Heritage'],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'lead' => 'pci', 'name' => 'First Bank'],
    ];

    /** Asset-name fragment => scope; first match wins. Everything else is out of scope. */
    private const SCOPE = [
        ['ATM', 'cde', 'Card-present acquiring: ATMs read card data and PINs.'],
        ['PoS', 'cde', 'Card-present acquiring: PoS terminals read card data.'],
        ['HSM', 'cde', 'Holds the PIN and card-verification keys (PCI DSS 3.6, 3.7).'],
        ['Thales', 'cde', 'Holds the PIN and card-verification keys (PCI DSS 3.6, 3.7).'],
        ['Card Gateway', 'cde', 'Transmits cardholder data to the card scheme switch.'],
        ['Mastercard', 'cde', 'Transmits cardholder data to the card scheme.'],
        ['Merchant Gateway', 'cde', 'Accepts card payments for merchants: stores and transmits PAN.'],
        ['Payments Hub', 'cde', 'Routes card transactions; processes PAN in memory.'],
        ['Active Directory', 'connected', 'Authenticates administrators of CDE systems (security-impacting).'],
        ['Entra', 'connected', 'Identity provider for CDE administrator access (security-impacting).'],
        ['Firewall', 'connected', 'Segments the CDE from the corporate network (PCI DSS 1.3).'],
        ['Core Switch', 'connected', 'Carries CDE VLANs; segmentation enforced upstream.'],
        ['Nexus', 'connected', 'Carries CDE VLANs; segmentation enforced upstream.'],
        ['F5', 'connected', 'Load-balances CDE web traffic.'],
        ['VPN', 'connected', 'Remote administrative access path into the CDE.'],
        ['Sentinel', 'connected', 'Collects CDE audit logs (PCI DSS 10).'],
        ['Defender', 'connected', 'Anti-malware on CDE systems (PCI DSS 5).'],
        ['Tenable', 'connected', 'Scans CDE systems (PCI DSS 11.3).'],
        ['Qualys', 'connected', 'Scans CDE systems (PCI DSS 11.3).'],
        ['CyberArk', 'connected', 'Vaults privileged credentials for CDE systems.'],
        ['Data Centre', 'connected', 'Hosts CDE systems; physical controls apply (PCI DSS 9).'],
        [' DC', 'connected', 'Hosts CDE systems; physical controls apply (PCI DSS 9).'],
        ['Finacle Core', 'connected', 'Card accounts are debited here; no PAN stored (tokenised by the card management system).'],
    ];

    private const UNSCOPED = ['AWS', 'Azure', 'GCP'];

    /** Requirement => control domains to map from. */
    private const DOMAINS = [
        '1' => ['Vulnerability Management', 'Governance'], '2' => ['Vulnerability Management', 'Governance'],
        '3' => ['Cryptography', 'Data Protection'], '4' => ['Cryptography'], '5' => ['Vulnerability Management', 'Logging & Monitoring'],
        '6' => ['Vulnerability Management', 'Governance'], '7' => ['Identity & Access'], '8' => ['Identity & Access'],
        '9' => ['Governance'], '10' => ['Logging & Monitoring', 'Governance'], '11' => ['Vulnerability Management'], '12' => ['Governance'],
    ];

    private const UNMAPPED = ['2.3', '11.2', '11.6'];

    /** Sub-requirement => [result, finding]. Anything not listed is in place. */
    private const RESULTS = [
        '2.3' => ['not_applicable', null],
        '3.4' => ['partially_compliant', 'Call-centre agents can display full PAN in the card management system; masking rule pending.'],
        '6.4' => ['partially_compliant', 'WAF in detection mode only on the merchant portal.'],
        '8.4' => ['partially_compliant', 'MFA into the CDE not enforced for two vendor support accounts.'],
        '10.4' => ['partially_compliant', 'Daily log review evidenced for 9 of the last 12 weeks.'],
        '11.3' => ['non_compliant', 'Internal vulnerability scans of the CDE missed one quarter; two high-risk findings not rescanned.'],
        '12.5' => ['non_compliant', 'Scope not revalidated after the PoS fleet expansion; data-flow diagrams out of date.'],
        '11.6' => ['not_assessed', null],
    ];

    private const EVIDENCE = [
        '1.2' => 'Firewall rule-set review, signed off by the network team',
        '1.3' => 'CDE segmentation test report',
        '3.6' => 'HSM key ceremony minutes',
        '4.2' => 'TLS configuration scan of CDE endpoints',
        '5.2' => 'EDR coverage report for CDE servers',
        '8.3' => 'Password and authentication standard',
        '9.5' => 'PoS device inspection log',
        '10.2' => 'SIEM log-source inventory for the CDE',
        '11.4' => 'Annual external and internal penetration test report',
        '12.6' => 'Security awareness training completion report',
        '12.10' => 'Incident response plan and last test report',
    ];

    public function run(): void
    {
        $pci = app(PciPosture::class);
        $fwId = $pci->frameworkId();
        if (! $fwId) {
            return;
        }

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || DB::table('assets')->where('organization_id', $org->id)->whereNotNull('pci_scope')->exists()) {
                continue;
            }
            $lead = User::where('email', "{$bank['lead']}@{$bank['domain']}")->value('id')
                ?? User::where('organization_id', $org->id)->value('id');

            $this->scope($org->id);
            $this->mappings($org->id, $fwId);
            $this->clearGenerated($org->id, $fwId);
            $this->assessment($org->id, $fwId, $bank, $lead);
            $this->currentCycle($org->id, $fwId, $lead);
        }
    }

    /**
     * ComplianceDemoSeeder (module 3) creates assessments for every active framework, so on a fresh
     * install it generates PCI assessments with arbitrary results before this seeder runs. They are
     * replaced by a coherent pair: last year's ROC and this year's cycle.
     */
    private function clearGenerated(int $orgId, int $fwId): void
    {
        $ids = DB::table('compliance_assessments')->where('organization_id', $orgId)->where('framework_id', $fwId)->pluck('id');
        $results = DB::table('compliance_results')->whereIn('assessment_id', $ids)->pluck('id');
        DB::table('evidence')->where('evidenceable_type', ComplianceResult::class)->whereIn('evidenceable_id', $results)->delete();
        DB::table('compliance_results')->whereIn('assessment_id', $ids)->delete();
        DB::table('compliance_assessments')->whereIn('id', $ids)->delete();
    }

    /** This year's assessment, under way: a third of the sub-requirements already tested. */
    private function currentCycle(int $orgId, int $fwId, ?int $lead): void
    {
        $assessment = ComplianceAssessment::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'framework_id' => $fwId,
            'title' => 'PCI DSS v4.0.1 Report on Compliance '.now()->year.' (in progress)',
            'description' => 'Fieldwork for this year\'s ROC; requirements 1 to 4 tested so far.',
            'status' => 'in_progress', 'lead_assessor_id' => $lead,
            'start_date' => now()->subWeeks(3), 'due_date' => now()->addMonths(2),
            'total_requirements' => 0, 'compliant_count' => 0, 'partial_count' => 0, 'non_compliant_count' => 0, 'not_applicable_count' => 0,
        ]);
        foreach (ComplianceAssessment::assessableRequirements($fwId) as $req) {
            $tested = in_array(strtok($req->requirement_code, '.'), ['1', '2', '3', '4'], true);
            [$status] = $tested ? (self::RESULTS[$req->requirement_code] ?? ['compliant']) : ['not_assessed'];
            ComplianceResult::create([
                'assessment_id' => $assessment->id, 'requirement_id' => $req->id, 'status' => $status,
                'assessed_by' => $tested ? $lead : null, 'assessed_at' => $tested ? now()->subWeeks(2) : null,
            ]);
        }
        $assessment->recalculateScore();
    }

    private function scope(int $orgId): void
    {
        foreach (DB::table('assets')->where('organization_id', $orgId)->whereNull('deleted_at')->get(['id', 'name', 'status']) as $asset) {
            if ($asset->status === 'under_review' || collect(self::UNSCOPED)->contains(fn ($f) => str_contains($asset->name, $f))) {
                continue;
            }
            $rule = collect(self::SCOPE)->first(fn ($r) => str_contains($asset->name, $r[0]));
            DB::table('assets')->where('id', $asset->id)->update([
                'pci_scope' => $rule[1] ?? 'out_of_scope',
                'pci_scope_note' => $rule[2] ?? 'Does not store, process or transmit cardholder data and has no connection to the CDE.',
            ]);
        }
    }

    private function mappings(int $orgId, int $fwId): void
    {
        $controls = DB::table('controls')->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('control_code')->get(['id', 'domain'])->groupBy('domain');
        $subs = DB::table('framework_requirements as s')->join('framework_requirements as p', 'p.id', '=', 's.parent_id')
            ->where('s.framework_id', $fwId)->orderBy('s.id')->get(['s.id', 's.requirement_code', 'p.requirement_code as parent']);

        foreach ($subs as $i => $sub) {
            if (in_array($sub->requirement_code, self::UNMAPPED, true)) {
                continue;
            }
            $pool = collect(self::DOMAINS[$sub->parent])->flatMap(fn ($d) => $controls[$d] ?? collect())->values();
            if ($pool->isEmpty()) {
                $pool = $controls['Governance'] ?? collect();
            }
            foreach ($pool->isEmpty() ? [] : [$pool[$i % $pool->count()], $pool[($i + 3) % $pool->count()]] as $control) {
                DB::table('control_framework_mappings')->updateOrInsert(
                    ['control_id' => $control->id, 'requirement_id' => $sub->id],
                    ['coverage' => 'full', 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    private function assessment(int $orgId, int $fwId, array $bank, ?int $lead): void
    {
        $assessment = ComplianceAssessment::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'framework_id' => $fwId,
            'title' => 'PCI DSS v4.0.1 Report on Compliance '.now()->subMonths(4)->year,
            'description' => "Annual QSA assessment of the {$bank['name']} cardholder data environment.",
            'status' => 'completed', 'lead_assessor_id' => $lead,
            'start_date' => now()->subMonths(5), 'end_date' => now()->subMonths(4), 'due_date' => now()->subMonths(4),
            'total_requirements' => 0, 'compliant_count' => 0, 'partial_count' => 0, 'non_compliant_count' => 0, 'not_applicable_count' => 0,
        ]);

        foreach (ComplianceAssessment::assessableRequirements($fwId) as $req) {
            [$status, $finding] = self::RESULTS[$req->requirement_code] ?? ['compliant', null];
            $control = DB::table('control_framework_mappings as m')->join('controls as c', 'c.id', '=', 'm.control_id')
                ->where('m.requirement_id', $req->id)->where('c.organization_id', $orgId)->value('c.id');
            $result = ComplianceResult::create([
                'assessment_id' => $assessment->id, 'requirement_id' => $req->id, 'control_id' => $control,
                'status' => $status, 'findings' => $finding,
                'recommendations' => $finding ? 'Remediate and retest before the next ROC.' : null,
                'assessed_by' => $status === 'not_assessed' ? null : $lead,
                'assessed_at' => $status === 'not_assessed' ? null : now()->subMonths(4)->subDays(7),
            ]);

            if (isset(self::EVIDENCE[$req->requirement_code])) {
                Evidence::withoutGlobalScopes()->create([
                    'organization_id' => $orgId, 'evidenceable_type' => ComplianceResult::class, 'evidenceable_id' => $result->id,
                    'title' => self::EVIDENCE[$req->requirement_code], 'type' => 'attestation', 'status' => 'approved',
                    'description' => 'Reviewed by the QSA for requirement '.$req->requirement_code.'; held in the ROC working papers.',
                    'uploaded_by' => $lead, 'reviewed_by' => $lead, 'reviewed_at' => now()->subMonths(4),
                    'valid_from' => now()->subMonths(4)->toDateString(),
                    'valid_until' => Str::startsWith($req->requirement_code, '11.4') ? now()->subDays(10)->toDateString() : now()->addMonths(8)->toDateString(),
                ]);
            }
        }

        $assessment->recalculateScore();
    }
}
