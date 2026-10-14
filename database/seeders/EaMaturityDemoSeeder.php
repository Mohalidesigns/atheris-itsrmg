<?php

namespace Database\Seeders;

use App\Models\Ea\EvidencePack;
use App\Models\Ea\MaturityAssessment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ea\EvidencePackGenerator;
use App\Services\Ea\Maturity\Cmmi;
use App\Services\Ea\Maturity\DomainEvidence;
use App\Services\Ea\Maturity\MaturityAssessmentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CBN EA maturity demo data (ATH-VAL-015). Runs after KriDashboardsDemoSeeder.
 *
 * Through MaturityAssessmentService, so every rating obeys the evidence rules:
 * - First Bank (holds the EA repository): the last two years approved (a level lower the year
 *   before, so the trend shows improvement) and this year's in progress with three domains rated.
 *   Ratings follow what the repository documents, never below level 2.
 * - Kano: this year's assessment approved, rated mostly at what its thinner repository supports,
 *   with Security rated two levels above the documentation and the approver's note accepting it.
 * - First Bank's evidence packs pointed at PDFs that were never written; they are replaced by one
 *   generated from the approved assessment.
 */
class EaMaturityDemoSeeder extends Seeder
{
    private const BANKS = [
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'assessor' => 'sysadmin', 'approver' => 'ciso'],
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'assessor' => 'sysadmin', 'approver' => 'admin'],
    ];

    public function run(): void
    {
        $service = app(MaturityAssessmentService::class);
        if ($service->domains()->isEmpty()) {
            return;
        }

        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            $assessor = User::where('email', "{$bank['assessor']}@{$bank['domain']}")->first();
            $approver = User::where('email', "{$bank['approver']}@{$bank['domain']}")->first();
            if (! $org || ! $assessor || ! $approver
                || MaturityAssessment::withoutGlobalScopes()->where('organization_id', $org->id)->whereIn('status', ['draft', 'submitted', 'approved'])->exists()) {
                continue;
            }

            if ($slug === 'first-bank-nigeria') {
                $this->assess($org->id, $assessor, $approver, now()->year - 2, -1, null, now()->subMonths(22), floor: 2);
                $this->assess($org->id, $assessor, $approver, now()->year - 1, 0, null, now()->subMonths(10), floor: 2);
                $this->assess($org->id, $assessor, null, now()->year, 0, ['BA', 'AA', 'DA'], null, floor: 2);
                $this->evidencePack($org->id, $approver);
            } else {
                $this->assess($org->id, $assessor, $approver, now()->year, 0, null, now()->subMonth(), ['SA' => 2],
                    'Security rated above the repository: zone assignments and control mappings are maintained in the CISO\'s GRC tooling and were reviewed in the internal-audit walkthrough. Repository to be brought up to date this quarter.');
            }
        }
    }

    /**
     * One assessment: each practice rated at the level its repository evidence supports, shifted by
     * `$shift` and `$uplift` (per domain), with the evidence the rules require.
     */
    private function assess(int $orgId, User $assessor, ?User $approver, int $year, int $shift, ?array $onlyDomains, $approvedAt, array $uplift = [], ?string $note = null, int $floor = 1): void
    {
        $service = app(MaturityAssessmentService::class);
        $practices = app(DomainEvidence::class)->practices($orgId);
        $assessment = $service->start($assessor, $year);

        foreach ($service->domains() as $domain) {
            if ($onlyDomains && ! in_array($domain->code, $onlyDomains, true)) {
                continue;
            }
            foreach ($domain->questions as $q) {
                $evidence = $practices[$q->code] ?? ['score' => 0, 'indicators' => []];
                // The assessor rates from the repository evidence, knowing practices it does not
                // capture (hence the floor), shifted for earlier years and any domain uplift.
                $level = max(1, min(4, max($floor, Cmmi::indicativeLevel($evidence['score'])) + $shift + ($uplift[$domain->code] ?? 0)));
                $refs = collect($evidence['indicators'])->sortByDesc('score')->take(2)->pluck('key')->all();
                $service->answer($assessment, $q, [
                    'level' => $level,
                    'evidence_note' => $level >= Cmmi::EVIDENCE_NOTE ? "{$q->guidance} maintained in the EA repository with named owners; reviewed at the quarterly ARB." : null,
                    'evidence_refs' => $level >= Cmmi::MEASURED ? $refs : [],
                    'comment' => $level < 3 ? 'Practice performed by the EA team but not yet standard across the bank.' : null,
                ], $assessor);
            }
        }
        DB::table('ea_maturity_assessments')->where('id', $assessment->id)->update(['created_at' => ($approvedAt ?? now())->copy()->subWeeks(3)]);

        if (! $approver) {
            return;
        }
        $service->submit($assessment->fresh(), $assessor);
        $service->approve($assessment->fresh(), $approver, $note);
        DB::table('ea_maturity_assessments')->where('id', $assessment->id)->update([
            'submitted_at' => $approvedAt->copy()->subWeek(), 'approved_at' => $approvedAt,
        ]);
    }

    /** Replace evidence packs whose files were never written with one generated from the approved assessment. */
    private function evidencePack(int $orgId, User $signer): void
    {
        EvidencePack::withoutGlobalScopes()->where('organization_id', $orgId)->get()
            ->filter(fn ($p) => ! $p->pdf_path || ! Storage::disk('local')->exists($p->pdf_path))
            ->each->delete();

        // The generator reads the signed-in user (tenant scope, "generated by").
        Auth::setUser($signer);
        try {
            app(EvidencePackGenerator::class)->generate(null, $signer->email);
        } finally {
            Auth::forgetUser();
        }
    }
}
