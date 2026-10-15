<?php

namespace App\Http\Controllers;

use App\Models\Capa\CapaAction;
use App\Models\Capa\ContainmentAction;
use App\Models\Capa\RootCause;
use App\Models\Issue;
use App\Models\User;
use App\Services\Issues\CapaWorkflow;
use App\Services\Issues\IssueWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The integrated CAPA stages on an issue (IT-GOV-CAPA-001): classification, containment, root
 * cause analysis, action plan, stage gates, RACI sign-off, independent verification and the CAPA
 * register export. Gate errors come back as validation errors on the `capa` key.
 */
class CapaController extends Controller
{
    public function __construct(private CapaWorkflow $capa, private IssueWorkflow $workflow) {}

    /** Choose the framework path / CBN cross-references (first call turns the issue into a CAPA). */
    public function update(Request $r, Issue $issue)
    {
        $this->authorizeWork($r, $issue);
        $data = $r->validate([
            'capa_framework' => [$issue->isCapa() ? 'sometimes' : 'required', Rule::in(array_keys(config('capa.frameworks')))],
            'capa_classification' => 'sometimes|nullable|string|max:32',
            'framework_reference' => 'sometimes|nullable|string|max:64',
            'cbn_provisions' => 'sometimes|array',
            'cbn_provisions.*' => ['string', Rule::in(array_keys(config('capa.cbn_provisions')))],
            'cbn_correlation' => 'sometimes|nullable|string|max:2000',
            'settling_days' => 'sometimes|nullable|integer',
        ]);

        return back()->with('success', $this->capa->classify($issue, $data, $r->user()));
    }

    public function advance(Request $r, Issue $issue)
    {
        $this->authorizeWork($r, $issue);
        $data = $r->validate(['note' => 'nullable|string|max:2000']);

        return back()->with('success', $this->capa->advance($issue, $r->user(), $data['note'] ?? null));
    }

    public function signoff(Request $r, Issue $issue)
    {
        $this->requireCapa($issue);
        $data = $r->validate(['note' => 'nullable|string|max:2000']);

        return back()->with('success', $this->capa->signOff($issue, $r->user(), $data['note'] ?? null));
    }

    /* ------------------------------- Containment ------------------------------- */

    public function containmentStore(Request $r, Issue $issue)
    {
        $this->authorizeWork($r, $issue);
        $this->requireStage($issue, ['identification', 'containment'], 'Containment actions are recorded during identification or containment.');
        $data = $r->validate([
            'action' => 'required|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'performed_at' => 'nullable|date|before_or_equal:now',
            'performed_by_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $issue->organization_id)],
        ]);
        $action = $issue->containmentActions()->create($data + [
            'organization_id' => $issue->organization_id,
            'performed_at' => $data['performed_at'] ?? now(),
            'performed_by_id' => $data['performed_by_id'] ?? $r->user()->id,
        ]);
        $this->workflow->log($issue, 'capa_containment', 'Containment: '.$action->action, $r->user());

        return back()->with('success', 'Containment action recorded.');
    }

    public function containmentDestroy(Request $r, Issue $issue, ContainmentAction $action)
    {
        $this->authorizeWork($r, $issue);
        abort_unless($action->issue_id === $issue->id, 404);
        $this->requireStage($issue, ['identification', 'containment'], 'Containment is complete; its record can no longer change.');
        $action->delete();
        $this->workflow->log($issue, 'capa_containment', 'Containment action removed: '.$action->action, $r->user());

        return back()->with('success', 'Containment action removed.');
    }

    /* --------------------------------- RCA ---------------------------------- */

    public function rootCause(Request $r, Issue $issue)
    {
        $this->authorizeWork($r, $issue);
        $this->requireStage($issue, ['identification', 'containment', 'rca'], 'The root cause analysis is complete and locked.');
        $data = $r->validate([
            'method' => ['required', Rule::in(RootCause::METHODS)],
            'five_whys' => 'nullable|array|max:10',
            'five_whys.*' => 'nullable|string|max:1000',
            'fishbone' => 'nullable|array',
            'fishbone.*' => 'nullable|array|max:20',
            'fishbone.*.*' => 'nullable|string|max:500',
            'root_cause_summary' => 'nullable|string|max:5000',
        ]);
        $fishbone = collect(RootCause::FISHBONE_CATEGORIES)
            ->mapWithKeys(fn ($c) => [$c => array_values(array_filter($data['fishbone'][$c] ?? [], 'filled'))])->all();

        $rca = RootCause::updateOrCreate(['issue_id' => $issue->id, 'organization_id' => $issue->organization_id], [
            'method' => $data['method'],
            'five_whys' => array_values(array_filter($data['five_whys'] ?? [], 'filled')),
            'fishbone' => $fishbone,
            'root_cause_summary' => $data['root_cause_summary'] ?? null,
            'completed_by_id' => $r->user()->id,
        ]);
        $this->workflow->log($issue, 'capa_rca', 'Root cause analysis '.($rca->wasRecentlyCreated ? 'recorded' : 'updated').($rca->isComplete() ? ' (complete).' : ' (draft).'), $r->user());

        return back()->with('success', 'Root cause analysis saved.');
    }

    /* ------------------------------- Action plan ------------------------------- */

    public function actionStore(Request $r, Issue $issue)
    {
        $this->authorizeWork($r, $issue);
        $this->requireStage($issue, ['identification', 'containment', 'rca', 'action_planning', 'implementation'], 'Corrective actions can no longer be added.');
        $data = $this->validatedAction($r, $issue);

        $n = (int) CapaAction::where('issue_id', $issue->id)->pluck('action_code')
            ->map(fn ($code) => (int) Str::afterLast((string) $code, '-A'))->max() + 1;
        $action = $issue->capaActions()->create($data + [
            'organization_id' => $issue->organization_id,
            'action_code' => $issue->issue_code.'-A'.$n,
            'status' => $data['status'] ?? 'planned',
        ]);
        $this->touchedPlan($issue, $r->user(), "Corrective action {$action->action_code} added: {$action->title}");

        return back()->with('success', "Corrective action {$action->action_code} added.");
    }

    public function actionUpdate(Request $r, Issue $issue, CapaAction $action)
    {
        $this->authorizeWork($r, $issue, $action);
        abort_unless($action->issue_id === $issue->id, 404);
        $this->requireStage($issue, ['identification', 'containment', 'rca', 'action_planning', 'implementation'], 'The action plan is locked.');
        $data = $this->validatedAction($r, $issue, false);

        if (isset($data['status']) && $data['status'] !== $action->status) {
            $data['completed_at'] = $data['status'] === 'done' ? now() : null;
        }
        $action->update($data);
        $changed = collect($action->getChanges())->except(['updated_at', 'completed_at'])->keys();
        if ($changed->isNotEmpty()) {
            $this->touchedPlan($issue, $r->user(), "Corrective action {$action->action_code} updated (".$changed->map(fn ($k) => str_replace('_', ' ', $k))->implode(', ').').',
                $changed->diff(['status'])->isNotEmpty());
        }

        return back()->with('success', "Corrective action {$action->action_code} saved.");
    }

    public function actionDestroy(Request $r, Issue $issue, CapaAction $action)
    {
        $this->authorizeWork($r, $issue);
        abort_unless($action->issue_id === $issue->id, 404);
        $this->requireStage($issue, ['identification', 'containment', 'rca', 'action_planning'], 'An approved action cannot be deleted; cancel it instead.');
        $action->delete();
        $this->touchedPlan($issue, $r->user(), "Corrective action {$action->action_code} removed.");

        return back()->with('success', 'Corrective action removed.');
    }

    /* ------------------------------- Verification ------------------------------- */

    public function verify(Request $r, Issue $issue)
    {
        $this->requireCapa($issue);
        $data = $r->validate([
            'outcome' => ['required', Rule::in(['pass', 'fail'])],
            'criteria' => 'nullable|array',
            'criteria.*' => 'boolean',
            'note' => 'nullable|string|max:2000',
        ]);

        return back()->with('success', $this->capa->verify($issue, $r->user(), $data['outcome'], $data['criteria'] ?? [], $data['note'] ?? null));
    }

    /* --------------------------------- Export --------------------------------- */

    /** The CAPA register as CSV, for the ISO 27001 management review and CBN reporting. */
    public function export(Request $r): StreamedResponse
    {
        $filters = $r->validate([
            'framework' => ['nullable', Rule::in(array_keys(config('capa.frameworks')))],
            'stage' => ['nullable', Rule::in(array_keys(config('capa.stages')))],
        ]);
        $issues = Issue::capa()
            ->when($filters['framework'] ?? null, fn ($q, $f) => $q->where('capa_framework', $f))
            ->when($filters['stage'] ?? null, fn ($q, $s) => $q->where('capa_stage', $s))
            ->with(['owner:id,name', 'verifier:id,name', 'recurrenceOf:id,issue_code', 'capaRootCause', 'capaActions'])
            ->withCount('containmentActions')
            ->orderBy('issue_code')->get();

        $frameworks = config('capa.frameworks');
        $provisions = config('capa.cbn_provisions');
        $severity = config('capa.severity_labels');
        $stages = config('capa.stages');
        $date = fn ($d) => $d?->copy()->timezone('Africa/Lagos')->format('Y-m-d H:i');

        $header = ['CAPA', 'Title', 'Severity', 'Framework', 'Classification', 'Framework reference', 'CBN provisions', 'CBN correlation',
            'Stage', 'Status', 'Owner', 'Source', 'Raised', 'SLA due', 'Containment actions', 'Root cause', 'Actions', 'Actions done',
            'Actions overdue', 'Implemented', 'Verification due', 'Verified by', 'Closed', 'Recurrence of'];

        return response()->streamDownload(function () use ($issues, $header, $frameworks, $provisions, $severity, $stages, $date) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($issues as $i) {
                $fw = $frameworks[$i->capa_framework] ?? null;
                fputcsv($out, array_map([$this, 'cell'], [
                    $i->issue_code, $i->title, $severity[$i->severity] ?? $i->severity,
                    $fw['label'] ?? $i->capa_framework,
                    $fw['classifications'][$i->capa_classification] ?? $i->capa_classification,
                    $fw['references'][$i->framework_reference] ?? $i->framework_reference,
                    collect($i->cbn_provisions ?? [])->map(fn ($p) => $provisions[$p]['label'] ?? $p)->implode('; '),
                    $i->cbn_correlation,
                    $stages[$i->capa_stage] ?? $i->capa_stage, $i->status, $i->owner?->name,
                    (Issue::SOURCES[$i->source_type]['label'] ?? $i->source_type).($i->source_reference ? ' — '.$i->source_reference : ''),
                    $date($i->created_at), $date($i->sla_due_at), $i->containment_actions_count,
                    $i->capaRootCause?->root_cause_summary,
                    $i->capaActions->where('status', '!=', 'cancelled')->count(),
                    $i->capaActions->where('status', 'done')->count(),
                    $i->capaActions->filter(fn (CapaAction $a) => $a->isOverdue())->count(),
                    $date($i->implemented_at), $date($i->verification_due_at), $i->verifier?->name, $date($i->closed_at),
                    $i->recurrenceOf?->issue_code,
                ]));
            }
            fclose($out);
        }, 'capa-register-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection in exported text. */
    public function cell($value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /* --------------------------------- Helpers --------------------------------- */

    /** Same rule as the issue itself: edit issues, or the issue owner; a corrective action's owner may update it. */
    private function authorizeWork(Request $r, Issue $issue, ?CapaAction $action = null): void
    {
        $user = $r->user();
        abort_unless($user->can('edit issues') || $issue->owner_id === $user->id || ($action && $action->owner_id === $user->id), 403);
    }

    private function requireCapa(Issue $issue): void
    {
        if (! $issue->isCapa()) {
            throw ValidationException::withMessages(['capa' => 'This issue is not a CAPA.']);
        }
    }

    private function requireStage(Issue $issue, array $stages, string $message): void
    {
        $this->requireCapa($issue);
        if (! in_array($issue->capa_stage, $stages, true)) {
            throw ValidationException::withMessages(['capa' => $message]);
        }
    }

    private function validatedAction(Request $r, Issue $issue, bool $creating = true): array
    {
        $orgId = $issue->organization_id;
        $data = $r->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => 'sometimes|nullable|string|max:5000',
            'owner_id' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_date' => 'sometimes|nullable|date',
            'status' => ['sometimes', Rule::in(CapaAction::STATUSES)],
            'change_request_id' => ['sometimes', 'nullable', Rule::exists('change_requests', 'id')->where('organization_id', $orgId)],
        ]);
        if (in_array($data['status'] ?? null, ['in_progress', 'done'], true) && $issue->capa_stage !== 'implementation') {
            throw ValidationException::withMessages(['status' => 'Actions are worked once the plan is approved and the CAPA is in implementation.']);
        }

        return $data;
    }

    /** Any change to the plan during action planning voids its sign-off. */
    private function touchedPlan(Issue $issue, User $actor, string $message, bool $planChanged = true): void
    {
        $this->workflow->log($issue, 'capa_action', $message, $actor);
        if ($planChanged && $issue->capa_stage === 'action_planning') {
            $this->capa->invalidateSignoff($issue, 'action_planning', $actor, 'The action plan changed.');
        }
    }
}
