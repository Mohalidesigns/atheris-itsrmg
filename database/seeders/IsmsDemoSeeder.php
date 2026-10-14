<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\SoaVersion;
use App\Models\User;
use App\Services\Isms\StatementOfApplicabilityService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ISMS demo data (ATH-VAL-010). Runs after PolicyManagementDemoSeeder.
 *
 * The bank seeders exclude Annex A controls at random (Kano excluded A.5.1 "Policies for
 * information security" and A.8.5 "Secure authentication", which no bank can exclude) and give
 * every entry one of two boilerplate justifications. Per bank, this seeder:
 * - re-includes those controls, taking the implementation status from the latest ISO 27001
 *   assessment result, and keeps only exclusions a bank could defend, with the reason;
 * - writes a justification for every applicable control from its theme, keeping any specific one;
 * - issues two approved SoA versions (last year's and this year's review), prepared by the ISMS
 *   lead and approved by the CISO, then changes three entries so "changed since approval" shows;
 * - leaves the claim/evidence contradictions in place: they are what the SoA page is for.
 */
class IsmsDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'preparer' => 'policy', 'approver' => 'admin', 'exclude' => [
            'A.8.30' => 'All application development is done in-house by the Digital Channels and Core Banking teams; no development is outsourced.',
            'A.7.12' => 'Data-centre cabling at the Lagos DR site is owned and maintained by the colocation provider (MainOne) under its ISO 27001 certificate; covered by supplier controls A.5.19 to A.5.22.',
        ]],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'preparer' => 'isms', 'approver' => 'ciso', 'exclude' => [
            'A.7.12' => 'Primary and DR data-centre cabling is owned by the colocation providers (Rack Centre, MainOne) and assured through their ISO 27001 certificates and our supplier controls A.5.19 to A.5.22.',
        ]],
    ];

    private const DRIVERS = [
        'A.5' => 'required by the CBN Risk-Based Cybersecurity Framework and the bank\'s Information Security Policy',
        'A.6' => 'required for every member of staff and contractor under the HR security and acceptable use policies',
        'A.7' => 'required to protect the bank\'s branches, head office and data centres',
        'A.8' => 'required to protect core banking, payment, channel and treasury systems',
    ];

    private const BOILERPLATE = ['Implemented per', 'Not applicable — '];

    public function run(): void
    {
        $service = app(StatementOfApplicabilityService::class);

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || SoaVersion::withoutGlobalScopes()->where('organization_id', $org->id)->exists() || ! $service->frameworkId()) {
                continue;
            }
            $preparer = User::where('email', "{$bank['preparer']}@{$bank['domain']}")->value('id');
            $approver = User::where('email', "{$bank['approver']}@{$bank['domain']}")->value('id');

            $this->decisions($org->id, $bank, $preparer, $service);

            // Two annual reviews, approved; the current SoA equals this year's version.
            $rows = $service->rows($org->id);
            $summary = $service->summary($rows);
            foreach ([[1, 13], [2, 5]] as [$no, $monthsAgo]) {
                SoaVersion::withoutGlobalScopes()->create([
                    'organization_id' => $org->id, 'version_no' => $no, 'status' => 'approved',
                    'snapshot' => $service->snapshot($rows), 'summary' => $summary + ['changes' => 0],
                    'notes' => $no === 1 ? 'Annual review following the ISO 27001:2022 transition.' : 'Annual review; exclusions re-examined.',
                    'prepared_by' => $preparer, 'prepared_at' => now()->subMonths($monthsAgo)->subDays(6),
                    'decided_by' => $approver, 'decided_at' => now()->subMonths($monthsAgo),
                    'decision_notes' => 'Approved at the ISMS management review.',
                    'created_at' => now()->subMonths($monthsAgo)->subDays(6), 'updated_at' => now()->subMonths($monthsAgo),
                ]);
            }

            $this->changesSince($org->id, $preparer, $service);
        }
    }

    private function decisions(int $orgId, array $bank, ?int $preparer, StatementOfApplicabilityService $service): void
    {
        $assessment = DB::table('compliance_assessments')->where('organization_id', $orgId)->where('framework_id', $service->frameworkId())
            ->where('status', 'completed')->orderByDesc('end_date')->orderByDesc('id')->value('id');
        $results = $assessment ? DB::table('compliance_results')->where('assessment_id', $assessment)->pluck('status', 'requirement_id') : collect();

        $entries = DB::table('statements_of_applicability as s')->join('framework_requirements as r', 'r.id', '=', 's.requirement_id')
            ->where('s.organization_id', $orgId)->get(['s.id', 's.requirement_id', 's.is_applicable', 's.justification', 's.implementation_status', 'r.requirement_code', 'r.title']);

        foreach ($entries as $e) {
            $theme = substr($e->requirement_code, 0, 3);
            $update = ['updated_by' => $preparer, 'updated_at' => now()->subMonths(5)->subDays(8)];

            if (isset($bank['exclude'][$e->requirement_code])) {
                $update += ['is_applicable' => false, 'implementation_status' => 'not_applicable', 'justification' => $bank['exclude'][$e->requirement_code]];
            } else {
                if (! $e->is_applicable) {
                    $update['is_applicable'] = true;
                    $update['implementation_status'] = match ($results[$e->requirement_id] ?? null) {
                        'compliant' => 'implemented', 'partially_compliant' => 'partial', default => 'planned',
                    };
                }
                $boilerplate = collect(self::BOILERPLATE)->contains(fn ($b) => str_starts_with((string) $e->justification, $b));
                if (blank($e->justification) || $boilerplate || ! $e->is_applicable) {
                    $update['justification'] = "Applicable: {$e->title} is ".(self::DRIVERS[$theme] ?? 'required by the ISMS risk assessment').'.';
                }
            }
            DB::table('statements_of_applicability')->where('id', $e->id)->update($update);
        }
    }

    /** Three entries updated after this year's approval, so the review queue has something in it. */
    private function changesSince(int $orgId, ?int $preparer, StatementOfApplicabilityService $service): void
    {
        $candidates = $service->rows($orgId)
            ->filter(fn ($r) => $r['is_applicable'] && in_array($r['implementation_status'], ['planned', 'partial'], true))
            ->sortBy(fn ($r) => $r['assessment_result'] === 'compliant' ? 0 : 1)
            ->take(3);

        foreach ($candidates as $r) {
            DB::table('statements_of_applicability')->where('id', $r['soa_id'])->update([
                'implementation_status' => $r['implementation_status'] === 'planned' ? 'partial' : 'implemented',
                'justification' => $r['justification'].' Implementation progressed after the Q3 remediation sprint.',
                'updated_by' => $preparer, 'updated_at' => now()->subDays(12),
            ]);
        }
    }
}
