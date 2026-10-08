<?php

namespace App\Modules\CBNCSAT\Services;

use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatApprovalRecord;
use App\Modules\CBNCSAT\Models\CsatApprovalStage;
use App\Modules\CBNCSAT\Models\CsatAssessment;
use App\Modules\CBNCSAT\Models\CsatIrQuestion;
use App\Modules\CBNCSAT\Models\CsatMaStatement;
use App\Modules\CBNCSAT\Models\CsatStakeholderEngagement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * CBN-CSAT approval routing (BRD BR-AW-01) and the Sheet 6 "Items to Submit" checklist (BR-AW-04).
 *
 * Lifecycle: draft → in_progress → pending_approval → approved → submitted.
 * Stage 1 is the preparer's submission; stages 2..n are sign-offs. Each stage is signed by a
 * different person (segregation of duties) holding the stage's permission, and a return for
 * revision (comments mandatory) sends the cycle back to in_progress.
 */
class CsatWorkflowService
{
    /** Used when an organisation has not configured csat_approval_stages. */
    public const DEFAULT_STAGES = [
        1 => ['name' => 'Preparer submission', 'permission' => 'edit csat'],
        2 => ['name' => 'CISO review & approval', 'permission' => 'approve csat'],
        3 => ['name' => 'CRO / CIO / COO review', 'permission' => 'approve csat'],
        4 => ['name' => 'MD/CEO sign-off', 'permission' => 'approve csat'],
    ];

    public const EDITABLE_STATUSES = ['draft', 'in_progress'];

    public function isEditable(CsatAssessment $assessment): bool
    {
        return in_array($assessment->status, self::EDITABLE_STATUSES, true);
    }

    /** @return array<int, array{number:int, name:string, permission:string}> */
    public function stages(CsatAssessment $assessment): array
    {
        $configured = CsatApprovalStage::where('organization_id', $assessment->organization_id)
            ->orderBy('stage_number')->get();

        if ($configured->isEmpty()) {
            return collect(self::DEFAULT_STAGES)
                ->map(fn ($s, $n) => ['number' => $n, 'name' => $s['name'], 'permission' => $s['permission']])
                ->values()->all();
        }

        return $configured->map(fn ($s) => [
            'number' => (int) $s->stage_number, 'name' => $s->stage_name, 'permission' => $s->role_required,
        ])->values()->all();
    }

    /** Records of the current submission cycle (from the latest stage-1 submission onwards). */
    public function currentCycle(CsatAssessment $assessment): Collection
    {
        $records = $assessment->approvalRecords()->orderBy('id')->get();
        $start = $records->where('stage_number', 1)->where('action', 'approved')->keys()->last();

        return $start === null ? collect() : $records->slice($start)->values();
    }

    /** The stage awaiting action, or null when nothing is pending. */
    public function nextStage(CsatAssessment $assessment): ?array
    {
        if ($assessment->status !== 'pending_approval') {
            return null;
        }
        $done = (int) $this->currentCycle($assessment)->where('action', 'approved')->max('stage_number');

        return collect($this->stages($assessment))->firstWhere('number', $done + 1);
    }

    /** Sheet 6 checklist with live validation per item. */
    public function checklist(CsatAssessment $assessment): array
    {
        $assessment->loadMissing('institutionProfile');
        $profile = $assessment->institutionProfile;

        $stakeholders = $assessment->stakeholderEngagement()->get()->keyBy('role_key');
        $stakeholderGaps = collect(CsatStakeholderEngagement::ROLES)->keys()->filter(function ($role) use ($stakeholders) {
            $row = $stakeholders->get($role);

            return ! $row || ! $row->engagement_status
                || (in_array($row->engagement_status, ['no', 'yes_with_comment'], true) && blank($row->comment));
        });

        $irTotal = CsatIrQuestion::where('is_active', true)->count();
        $irAnswered = $assessment->irResponses()->count();

        $irNarrativeCategories = $assessment->irNarratives()->whereNotNull('narrative_value')
            ->where('narrative_value', '!=', '')->distinct()->count('category_code');

        $maTotal = CsatMaStatement::where('is_active', true)->count();
        $maAnswered = $assessment->maResponses()->whereNotNull('response')->count();

        $ccMissing = $assessment->maResponses()->where('response', 'yes_cc')->doesntHave('compensatingControl')->count();

        $narratives = $assessment->maNarratives()->get();
        $narrativesShort = $narratives->filter(fn ($n) => $n->is_mandatory
            && mb_strlen(trim((string) $n->response_text)) < (int) $n->min_characters)->count();

        $targetsSet = $assessment->maScores()->where('score_type', 'domain')->whereNotNull('target_maturity_level')->count();

        $items = [
            ['key' => 'profile', 'label' => 'Institution profile & CISO details (Sheet 7)',
                'done' => $profile && filled($profile->institution_name) && filled($profile->ciso_email) && filled($profile->cbn_licence_type),
                'detail' => $profile ? 'Completed' : 'Not started', 'route' => 'csat.institution-profile'],
            ['key' => 'stakeholders', 'label' => 'Stakeholder engagement matrix — all 10 roles attested',
                'done' => $stakeholderGaps->isEmpty(),
                'detail' => $stakeholderGaps->isEmpty() ? 'All roles attested' : $stakeholderGaps->count().' role(s) missing an attestation or required comment', 'route' => 'csat.institution-profile'],
            ['key' => 'inherent_risk', 'label' => 'Inherent risk profile — every question answered',
                'done' => $irTotal > 0 && $irAnswered >= $irTotal,
                'detail' => "{$irAnswered} / {$irTotal} answered", 'route' => 'csat.ir.questions'],
            ['key' => 'ir_narratives', 'label' => 'Inherent risk narratives — each category described (Sheet 12)',
                'done' => $irNarrativeCategories >= 5,
                'detail' => "{$irNarrativeCategories} / 5 categories", 'route' => 'csat.ir.narratives'],
            ['key' => 'maturity', 'label' => 'Maturity assessment — every declarative statement answered',
                'done' => $maTotal > 0 && $maAnswered >= $maTotal,
                'detail' => "{$maAnswered} / {$maTotal} answered", 'route' => 'csat.ma.assessment'],
            ['key' => 'compensating_controls', 'label' => 'Compensating control documented for every Yes [CC]',
                'done' => $ccMissing === 0,
                'detail' => $ccMissing ? "{$ccMissing} Yes [CC] answer(s) without a compensating control" : 'All documented', 'route' => 'csat.ma.assessment'],
            ['key' => 'ma_narratives', 'label' => 'Mandatory maturity narratives meet the minimum length',
                'done' => $narratives->isNotEmpty() && $narrativesShort === 0,
                'detail' => $narratives->isEmpty() ? 'Narratives not provisioned' : ($narrativesShort ? "{$narrativesShort} narrative(s) too short or empty" : 'All complete'), 'route' => 'csat.ma.narratives'],
            ['key' => 'targets', 'label' => 'Target maturity set for all 5 domains (Sheet 16)',
                'done' => $targetsSet >= 5,
                'detail' => "{$targetsSet} / 5 domains", 'route' => 'csat.ma.targets'],
            ['key' => 'threats', 'label' => 'Threat register populated',
                'done' => $assessment->threats()->exists(),
                'detail' => $assessment->threats()->count().' threat(s)', 'route' => 'csat.threats'],
            ['key' => 'vulnerabilities', 'label' => 'Vulnerability register populated',
                'done' => $assessment->vulnerabilities()->exists(),
                'detail' => $assessment->vulnerabilities()->count().' vulnerability(ies)', 'route' => 'csat.vulnerabilities'],
            ['key' => 'deadline', 'label' => 'CBN submission deadline recorded',
                'done' => $assessment->submission_deadline !== null,
                'detail' => $assessment->submission_deadline?->format('d M Y') ?? 'Not set', 'route' => 'csat.workflow'],
        ];

        return $items;
    }

    public function isReady(CsatAssessment $assessment): bool
    {
        return collect($this->checklist($assessment))->every('done');
    }

    public function submit(CsatAssessment $assessment, User $user, ?string $comments = null): void
    {
        if (! $this->isEditable($assessment)) {
            throw ValidationException::withMessages(['workflow' => 'Only a draft or in-progress assessment can be submitted.']);
        }
        $missing = collect($this->checklist($assessment))->reject(fn ($i) => $i['done'])->pluck('label');
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['workflow' => 'Submission checklist incomplete: '.$missing->implode('; ').'.']);
        }

        $this->record($assessment, $user, 1, 'approved', $comments ?: 'Submitted for approval');
        $assessment->update(['status' => 'pending_approval']);
    }

    public function approve(CsatAssessment $assessment, User $user, ?string $comments = null): array
    {
        $stage = $this->nextStage($assessment);
        if (! $stage) {
            throw ValidationException::withMessages(['workflow' => 'This assessment is not awaiting approval.']);
        }
        $this->assertCanAct($assessment, $user, $stage);

        $this->record($assessment, $user, $stage['number'], 'approved', $comments);

        $last = collect($this->stages($assessment))->max('number');
        if ($stage['number'] >= $last) {
            $assessment->update(['status' => 'approved']);
        }

        return $stage;
    }

    public function returnForRevision(CsatAssessment $assessment, User $user, string $comments): array
    {
        $stage = $this->nextStage($assessment);
        if (! $stage) {
            throw ValidationException::withMessages(['workflow' => 'This assessment is not awaiting approval.']);
        }
        $this->assertCanAct($assessment, $user, $stage);

        $this->record($assessment, $user, $stage['number'], 'returned_for_revision', $comments);
        $assessment->update(['status' => 'in_progress']);

        return $stage;
    }

    public function submitToCbn(CsatAssessment $assessment, User $user): void
    {
        if ($assessment->status !== 'approved') {
            throw ValidationException::withMessages(['workflow' => 'Only a fully approved assessment can be submitted to the CBN.']);
        }
        $assessment->update(['status' => 'submitted', 'submitted_at' => now()]);
    }

    private function assertCanAct(CsatAssessment $assessment, User $user, array $stage): void
    {
        if (! $user->hasRole('Super Admin') && ! $user->can($stage['permission'])) {
            throw ValidationException::withMessages(['workflow' => "You do not hold the permission required for \"{$stage['name']}\"."]);
        }
        $signedEarlier = $this->currentCycle($assessment)->where('action', 'approved')->pluck('approver_id');
        if ($signedEarlier->contains($user->id)) {
            throw ValidationException::withMessages(['workflow' => 'Segregation of duties: you already signed an earlier stage of this submission. A different officer must sign "'.$stage['name'].'".']);
        }
    }

    private function record(CsatAssessment $assessment, User $user, int $stage, string $action, ?string $comments): CsatApprovalRecord
    {
        $at = now();
        $sequence = $assessment->approvalRecords()->count() + 1;

        return CsatApprovalRecord::create([
            'assessment_id' => $assessment->id,
            'stage_number' => $stage,
            'action' => $action,
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_role' => $user->job_title ?: ($user->getRoleNames()->first() ?? 'User'),
            // HMAC-SHA256(user, assessment, stage, action, sequence, timestamp) keyed with the app key (TRD signature token).
            'digital_signature_token' => hash_hmac('sha256', implode('|', [$user->id, $assessment->id, $stage, $action, $sequence, $at->format('Y-m-d\TH:i:s.uP')]), (string) config('app.key')),
            'comments' => $comments,
            'actioned_at' => $at,
        ]);
    }
}
