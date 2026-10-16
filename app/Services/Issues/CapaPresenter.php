<?php

namespace App\Services\Issues;

use App\Models\Capa\CapaAction;
use App\Models\Capa\ContainmentAction;
use App\Models\Capa\RootCause;
use App\Models\Issue;
use App\Models\User;

/**
 * Inertia props for the CAPA panels of Issues/Show and the console. The shape is the contract in
 * docs/CAPA_PROPS_CONTRACT.md — change both together.
 */
class CapaPresenter
{
    public function __construct(private CapaWorkflow $capa) {}

    /** Static options: frameworks, CBN provisions, stages, RACI. */
    public function config(): array
    {
        return [
            'enabled' => (bool) config('capa.enabled'),
            'frameworks' => collect(config('capa.frameworks'))->map(fn ($f, $key) => [
                'key' => $key,
                'label' => $f['label'],
                'focus' => $f['focus'],
                'classification_hint' => $f['classification_hint'],
                'rca_focus' => $f['rca_focus'],
                'classifications' => $this->options($f['classifications']),
                'references' => $this->options($f['references']),
                'verification_criteria' => $this->options($f['verification_criteria']),
            ])->values()->all(),
            'cbnProvisions' => collect(config('capa.cbn_provisions'))->map(fn ($p, $key) => [
                'key' => $key, 'label' => $p['label'], 'source' => $p['source'], 'description' => $p['description'], 'verified' => (bool) $p['verified'],
            ])->values()->all(),
            'stages' => $this->options(config('capa.stages')),
            'raciRoles' => collect(config('capa.raci_roles'))->map(fn ($r, $key) => ['key' => $key, 'label' => $r['label'], 'roles' => $r['roles']])->values()->all(),
            'raci' => $this->raci(),
            'settling' => config('capa.settling_days'),
            'severityLabels' => config('capa.severity_labels'),
            'fishboneCategories' => RootCause::FISHBONE_CATEGORIES,
            'rcaMethods' => RootCause::METHODS,
            'minWhys' => RootCause::MIN_WHYS,
            'actionStatuses' => CapaAction::STATUSES,
            'kpiTargets' => config('capa.kpi_targets'),
        ];
    }

    /** Per-issue CAPA state. */
    public function forIssue(Issue $issue, User $user): array
    {
        $issue->loadMissing(['containmentActions.performer:id,name', 'capaRootCause.completedBy:id,name',
            'capaActions' => fn ($q) => $q->with(['owner:id,name', 'changeRequest:id,change_code,title,status']),
            'recurrenceOf:id,issue_code,title,status,capa_stage,closed_at', 'recurrences:id,issue_code,title,status,capa_stage,created_at,recurrence_of_id']);

        $enabled = $issue->isCapa();
        $framework = $enabled ? config('capa.frameworks.'.$issue->capa_framework) : null;
        $results = $issue->verification_criteria ?? [];
        $stage = $enabled ? $issue->capa_stage : null;
        $blockers = $enabled ? $this->capa->blockers($issue) : [];

        return [
            'capa' => [
                'enabled' => $enabled,
                'framework' => $issue->capa_framework,
                'framework_label' => $framework['label'] ?? null,
                'classification' => $issue->capa_classification,
                'classification_label' => $framework['classifications'][$issue->capa_classification] ?? null,
                'framework_reference' => $issue->framework_reference,
                'framework_reference_label' => $framework['references'][$issue->framework_reference] ?? null,
                'rca_focus' => $framework['rca_focus'] ?? null,
                'cbn_provisions' => array_values($issue->cbn_provisions ?? []),
                'cbn_correlation' => $issue->cbn_correlation,
                'stage' => $stage,
                'stage_label' => $stage ? config('capa.stages.'.$stage) : null,
                'stages' => $enabled ? $this->stages($issue) : [],
                'signoffs' => (object) ($issue->stage_signoffs ?? []),
                'signoff_required' => $stage ? $this->capa->signoffRequired($stage) : false,
                'can_sign_off' => $enabled && ! in_array($stage, ['verification', 'closed'], true) && $this->capa->isAccountable($user, $stage),
                'accountable' => $stage ? $this->capa->accountableFor($stage) : null,
                'my_raci_roles' => $this->capa->raciRolesOf($user),
                'settling_days' => $issue->settling_days,
                'implemented_at' => $issue->implemented_at?->toIso8601String(),
                'verification_due_at' => $issue->verification_due_at?->toIso8601String(),
                'verification_open' => $stage === 'verification' && $issue->verification_due_at && now()->gte($issue->verification_due_at),
                'can_verify' => $enabled && $this->capa->canVerify($issue, $user),
                'verifier_blockers' => $stage === 'verification' ? $this->capa->verifierBlockers($issue, $user) : [],
                'verification_criteria' => collect($framework['verification_criteria'] ?? [])
                    ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'checked' => (bool) ($results[$key] ?? false)])->values()->all(),
                'gate' => [
                    'can_advance' => $enabled && ! $blockers,
                    'next_stage' => $stage ? $this->capa->nextStage($stage) : null,
                    'blockers' => $blockers,
                ],
                'raci' => $this->raci(),
                'critical_notified_at' => $issue->critical_notified_at?->toIso8601String(),
            ],
            'containmentActions' => $issue->containmentActions->map(fn (ContainmentAction $c) => [
                'id' => $c->id,
                'action' => $c->action,
                'notes' => $c->notes,
                'performed_at' => $c->performed_at?->toIso8601String(),
                'performed_by' => $c->performer ? ['id' => $c->performer->id, 'name' => $c->performer->name] : null,
            ])->values()->all(),
            'rootCause' => $issue->capaRootCause ? [
                'id' => $issue->capaRootCause->id,
                'method' => $issue->capaRootCause->method,
                'five_whys' => array_values($issue->capaRootCause->five_whys ?? []),
                'fishbone' => collect(RootCause::FISHBONE_CATEGORIES)->mapWithKeys(fn ($c) => [$c => array_values($issue->capaRootCause->fishbone[$c] ?? [])])->all(),
                'root_cause_summary' => $issue->capaRootCause->root_cause_summary,
                'complete' => $issue->capaRootCause->isComplete(),
                'completed_by' => $issue->capaRootCause->completedBy ? ['id' => $issue->capaRootCause->completedBy->id, 'name' => $issue->capaRootCause->completedBy->name] : null,
                'updated_at' => $issue->capaRootCause->updated_at?->toIso8601String(),
            ] : null,
            'capaActions' => $issue->capaActions->map(fn (CapaAction $a) => [
                'id' => $a->id,
                'action_code' => $a->action_code,
                'title' => $a->title,
                'description' => $a->description,
                'owner_id' => $a->owner_id,
                'owner' => $a->owner ? ['id' => $a->owner->id, 'name' => $a->owner->name] : null,
                'due_date' => $a->due_date?->toDateString(),
                'status' => $a->status,
                'overdue' => $a->isOverdue(),
                'completed_at' => $a->completed_at?->toIso8601String(),
                'change_request' => $a->changeRequest ? [
                    'id' => $a->changeRequest->id, 'code' => $a->changeRequest->change_code, 'title' => $a->changeRequest->title,
                    'status' => $a->changeRequest->status, 'url' => route('changes.show', $a->changeRequest->id),
                ] : null,
            ])->values()->all(),
            'recurrence' => [
                'of' => $issue->recurrenceOf ? $this->issueRef($issue->recurrenceOf) : null,
                'later' => $issue->recurrences->map(fn (Issue $r) => $this->issueRef($r))->values()->all(),
            ],
        ];
    }

    private function stages(Issue $issue): array
    {
        $order = $this->capa->stages();
        $current = array_search($issue->capa_stage, $order, true);
        $identifiedAt = $issue->events()->where('event_type', 'capa_stage')->get()
            ->first(fn ($e) => ($e->payload['from'] ?? null) === 'identification')?->created_at;
        $completed = [
            'identification' => $identifiedAt,
            'containment' => $issue->containment_completed_at,
            'rca' => $issue->rca_completed_at,
            'action_planning' => $issue->plan_approved_at,
            'implementation' => $issue->implemented_at,
            'verification' => $issue->capa_stage === 'closed' ? $issue->verified_at : null,
            'closed' => $issue->closed_at,
        ];

        return collect($order)->map(fn ($key, $i) => [
            'key' => $key,
            'label' => config('capa.stages.'.$key),
            'status' => $issue->capa_stage === 'closed' || $i < $current ? 'done' : ($i === $current ? 'current' : 'pending'),
            'completed_at' => $i < $current || $issue->capa_stage === 'closed' ? $completed[$key]?->toIso8601String() : null,
        ])->all();
    }

    private function raci(): array
    {
        return collect(config('capa.raci'))->map(fn ($row, $stage) => [
            'stage' => $stage, 'label' => config('capa.stages.'.$stage), 'assignments' => $row,
        ])->values()->all();
    }

    private function options(array $map): array
    {
        return collect($map)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }

    private function issueRef(Issue $i): array
    {
        return [
            'id' => $i->id, 'issue_code' => $i->issue_code, 'title' => $i->title, 'status' => $i->status,
            'capa_stage' => $i->capa_stage, 'created_at' => $i->created_at?->toIso8601String(), 'closed_at' => $i->closed_at?->toIso8601String(),
        ];
    }
}
