<?php

namespace App\Services\Ea\Maturity;

use App\Models\Ea\MaturityAssessment;
use App\Models\Ea\MaturityDomain;
use App\Models\Ea\MaturityQuestion;
use App\Models\Ea\MaturityResponse;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CBN EA maturity assessments (ATH-VAL-015).
 *
 * Each of the five blueprint domains has five practice areas, rated on the CMMI scale (Cmmi). A
 * domain's level is the CMMI staged rating: the lowest of its practice levels, since a domain is
 * only as mature as its weakest practice; the average is shown beside it.
 *
 * Evidence: a rating of 3 or more needs an evidence note; 4 or more also needs at least one
 * measured indicator from the repository (DomainEvidence), since "quantitatively managed" means
 * measured. A domain rated two or more levels above what the repository supports is flagged, and
 * the approver must say why they accept it.
 *
 * Workflow: draft -> submitted (all practices answered) -> approved by someone other than the
 * submitter, or returned to draft with a note. Approval snapshots the results, which the ITSB
 * maturity return and the evidence pack read.
 */
class MaturityAssessmentService
{
    public const FRAMEWORK = 'cbn-ea';

    public function __construct(private DomainEvidence $evidence) {}

    public function domains()
    {
        return MaturityDomain::where('framework', self::FRAMEWORK)->with(['questions' => fn ($q) => $q->orderBy('code')])->orderBy('sort_order')->orderBy('code')->get();
    }

    /** The bank's ITSB target: the highest category target across its Nigerian licensed entities, else Category One (3). */
    public function defaultTarget(int $orgId): int
    {
        $targets = LegalEntity::withoutGlobalScopes()->where('organization_id', $orgId)->get()->map->itsbTargetLevel()->filter();

        return (int) ($targets->max() ?? 3);
    }

    public function start(User $actor, int $year, ?string $title = null): MaturityAssessment
    {
        $orgId = (int) $actor->organization_id;
        MaturityAssessment::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['draft', 'submitted'])->exists()
            && throw ValidationException::withMessages(['assessment' => 'An assessment is already in progress; finish or approve it first.']);
        $target = $this->defaultTarget($orgId);

        return MaturityAssessment::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'title' => $title ?: "CBN EA maturity assessment {$year}",
            'framework' => self::FRAMEWORK,
            'year' => $year,
            'status' => 'draft',
            'targets' => $this->domains()->mapWithKeys(fn ($d) => [$d->code => $target])->all(),
            'created_by' => $actor->id,
        ]);
    }

    public function answer(MaturityAssessment $assessment, MaturityQuestion $question, array $data, User $actor): MaturityResponse
    {
        $assessment->status === 'draft' || throw ValidationException::withMessages(['assessment' => 'Only a draft assessment can be answered.']);
        $level = (int) $data['level'];
        $level >= Cmmi::EVIDENCE_NOTE && blank($data['evidence_note'] ?? null)
            && throw ValidationException::withMessages(['evidence_note' => 'Level '.Cmmi::EVIDENCE_NOTE.' or above needs an evidence note: what shows the practice is standard across the bank.']);

        $available = collect($this->evidence->practices((int) $assessment->organization_id)[$question->code]['indicators'] ?? [])->keyBy('key');
        $refs = collect($data['evidence_refs'] ?? [])->filter(fn ($k) => $available->has($k))
            ->map(fn ($k) => $available[$k] + ['captured_at' => now()->toDateString()])->values()->all();
        $level >= Cmmi::MEASURED && empty($refs)
            && throw ValidationException::withMessages(['evidence_refs' => 'Level '.Cmmi::MEASURED.' or above means the practice is measured: cite at least one indicator from the repository.']);

        return MaturityResponse::updateOrCreate(
            ['assessment_id' => $assessment->id, 'question_id' => $question->id],
            ['selected_level' => $level, 'comment' => $data['comment'] ?? null, 'evidence_note' => $data['evidence_note'] ?? null,
                'evidence_refs' => $refs ?: null, 'answered_by' => $actor->id, 'answered_at' => now()],
        );
    }

    public function setTargets(MaturityAssessment $assessment, array $targets): void
    {
        $assessment->status === 'draft' || throw ValidationException::withMessages(['assessment' => 'Targets are set while the assessment is a draft.']);
        $codes = $this->domains()->pluck('code');
        $assessment->update(['targets' => $codes->mapWithKeys(fn ($c) => [$c => max(1, min(5, (int) ($targets[$c] ?? $assessment->targets[$c] ?? 3)))])->all()]);
    }

    public function submit(MaturityAssessment $assessment, User $actor): void
    {
        $assessment->status === 'draft' || throw ValidationException::withMessages(['assessment' => 'Only a draft can be submitted.']);
        $results = $this->results($assessment);
        $missing = $results['total'] - $results['answered'];
        $missing > 0 && throw ValidationException::withMessages(['assessment' => "{$missing} practice area(s) still need a rating."]);

        $assessment->update(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'results' => $results, 'overall_score' => $results['overall']]);
    }

    public function approve(MaturityAssessment $assessment, User $actor, ?string $note): void
    {
        $assessment->status === 'submitted' || throw ValidationException::withMessages(['assessment' => 'Only a submitted assessment can be approved.']);
        (int) $assessment->submitted_by === (int) $actor->id
            && throw ValidationException::withMessages(['assessment' => 'The person who submitted the assessment cannot approve it.']);
        $results = $this->results($assessment);
        $flagged = collect($results['domains'])->where('overstated', true)->pluck('name');
        $flagged->isNotEmpty() && blank($note)
            && throw ValidationException::withMessages(['approval_note' => 'Rated well above the repository evidence: '.$flagged->implode(', ').'. Record why you accept the rating.']);

        DB::transaction(function () use ($assessment, $actor, $note, $results) {
            MaturityAssessment::withoutGlobalScopes()->where('organization_id', $assessment->organization_id)->where('status', 'approved')
                ->where('id', '!=', $assessment->id)->where('year', $assessment->year)->update(['status' => 'superseded']);
            $assessment->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'approval_note' => $note,
                'results' => $results, 'overall_score' => $results['overall']]);
        });
    }

    public function returnToDraft(MaturityAssessment $assessment, User $actor, string $note): void
    {
        $assessment->status === 'submitted' || throw ValidationException::withMessages(['assessment' => 'Only a submitted assessment can be returned.']);
        $assessment->update(['status' => 'draft', 'approval_note' => "Returned by {$actor->name}: {$note}", 'submitted_by' => null, 'submitted_at' => null]);
    }

    /**
     * Per-domain results: practice levels, the CMMI staged level (lowest practice), the average,
     * what the repository supports, the target and the gap.
     */
    public function results(MaturityAssessment $assessment): array
    {
        $responses = MaturityResponse::where('assessment_id', $assessment->id)->get()->keyBy('question_id');
        $evidence = $this->evidence->domains((int) $assessment->organization_id);
        $practiceEvidence = $this->evidence->practices((int) $assessment->organization_id);
        $domains = [];
        $answered = 0;
        $total = 0;

        foreach ($this->domains() as $d) {
            $levels = [];
            $practices = [];
            foreach ($d->questions as $q) {
                $total++;
                $r = $responses->get($q->id);
                $r && $answered++;
                $r && $levels[] = (int) $r->selected_level;
                $practices[] = ['code' => $q->code, 'level' => $r?->selected_level, 'evidence_score' => $practiceEvidence[$q->code]['score'] ?? 0];
            }
            $complete = count($levels) === $d->questions->count() && $levels;
            $staged = $complete ? min($levels) : null;
            $indicative = $evidence[$d->code]['level'] ?? 1;
            $target = (int) ($assessment->targets[$d->code] ?? 3);
            $domains[$d->code] = [
                'code' => $d->code, 'name' => $d->name,
                'level' => $staged, 'average' => $levels ? round(array_sum($levels) / count($levels), 1) : null,
                'answered' => count($levels), 'total' => $d->questions->count(),
                'indicative' => $indicative, 'documentation_score' => $evidence[$d->code]['score'] ?? 0,
                'target' => $target, 'gap' => $staged !== null ? max(0, $target - $staged) : null,
                'overstated' => $staged !== null && $staged - $indicative >= Cmmi::OVERSTATEMENT_GAP,
                'weakest' => collect($practices)->filter(fn ($p) => $p['level'] !== null)->sortBy('level')->take(2)->pluck('code')->values()->all(),
            ];
        }
        $rated = collect($domains)->whereNotNull('level');

        return [
            'domains' => $domains,
            'answered' => $answered,
            'total' => $total,
            'overall' => $rated->count() === count($domains) ? round($rated->avg('level'), 1) : null,
            'at_target' => $rated->filter(fn ($d) => $d['level'] >= $d['target'])->count(),
            'computed_at' => now()->toDateTimeString(),
        ];
    }
}
