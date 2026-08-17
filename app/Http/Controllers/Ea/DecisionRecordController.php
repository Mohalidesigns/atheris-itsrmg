<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\ArbSubmission;
use App\Models\Ea\DecisionRecord;
use App\Models\Ea\Initiative;
use App\Models\Ea\Principle;
use App\Models\Ea\Standard;
use App\Services\Ea\EntityRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * DecisionRecordController — WS 1.4 (B4).
 *
 * §5.4: "Forrester names ADRs a defining capability; Orbus has none, Ardoq has
 * 'Architecture Records'. Cheap to build, immediately demoable, and it is the
 * artefact CBN's governance section asks for."
 *
 * The governance loop §5.2 describes — principle → standard → submission →
 * decision → exception — was missing its fourth step: the ARB recorded a
 * verdict on a submission, but the *reasoning* had nowhere to live and could
 * not be cited by anything else.
 */
class DecisionRecordController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', DecisionRecord::class);

        $records = DecisionRecord::with(['supersedes:id,code,title', 'supersededBy:id,code,title,supersedes_id'])
            ->orderByDesc('decided_on')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => $this->shape($r));

        return Inertia::render('Ea/DecisionRecords', [
            'records' => $records,
            'byStatus' => $records->groupBy('status')->map->count(),
            'options' => $this->options(),
        ]);
    }

    public function show(DecisionRecord $record)
    {
        Gate::authorize('view', $record);

        $record->load(['supersedes', 'supersededBy', 'arbSubmission', 'initiative']);

        return Inertia::render('Ea/DecisionRecordShow', [
            'record' => $this->shape($record) + [
                'lineage' => collect($record->lineage())->map(fn ($r) => [
                    'id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'status' => $r->status,
                ])->values(),
                'arb' => $record->arbSubmission ? [
                    'id' => $record->arbSubmission->id,
                    'code' => $record->arbSubmission->code,
                    'title' => $record->arbSubmission->title,
                    'status' => $record->arbSubmission->status,
                ] : null,
                'initiative' => $record->initiative ? [
                    'id' => $record->initiative->id,
                    'code' => $record->initiative->code,
                    'name' => $record->initiative->name,
                ] : null,
                'principles' => Principle::whereIn('id', $record->impacted_principles ?? [])
                    ->get(['id', 'code', 'name']),
                'standards' => Standard::whereIn('id', $record->impacted_standards ?? [])
                    ->get(['id', 'code', 'name']),
            ],
            'options' => $this->options(),
        ]);
    }

    private function shape(DecisionRecord $r): array
    {
        return [
            'id' => $r->id,
            'code' => $r->code,
            'title' => $r->title,
            'context' => $r->context,
            'decision' => $r->decision,
            'consequences' => $r->consequences,
            'alternatives' => $r->alternatives,
            'status' => $r->status,
            'tone' => $r->tone(),
            'driver' => $r->driver,
            'decided_on' => optional($r->decided_on)->toDateString(),
            'decided_by' => $r->decided_by,
            'supersedes_id' => $r->supersedes_id,
            'supersedes' => $r->supersedes ? ['id' => $r->supersedes->id, 'code' => $r->supersedes->code, 'title' => $r->supersedes->title] : null,
            'superseded_by' => $r->supersededBy ? ['id' => $r->supersededBy->id, 'code' => $r->supersededBy->code, 'title' => $r->supersededBy->title] : null,
            'arb_submission_id' => $r->arb_submission_id,
            'initiative_id' => $r->initiative_id,
            'impacted_principles' => $r->impacted_principles ?? [],
            'impacted_standards' => $r->impacted_standards ?? [],
            'linked_entities' => collect($r->linked_entities ?? [])->map(fn ($l) => [
                'entity_type' => $l['entity_type'] ?? null,
                'entity_id' => $l['entity_id'] ?? null,
                'label' => isset($l['entity_type'], $l['entity_id'])
                    ? EntityRegistry::describe($l['entity_type'], $l['entity_id'])
                    : null,
            ])->values(),
        ];
    }

    private function options(): array
    {
        return [
            'statuses' => DecisionRecord::STATUSES,
            'principles' => Principle::orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($p) => ['value' => $p->id, 'label' => "{$p->code} — {$p->name}"]),
            'standards' => Standard::orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => "{$s->code} — {$s->name}"]),
            'submissions' => ArbSubmission::orderByDesc('created_at')->limit(200)->get(['id', 'code', 'title'])
                ->map(fn ($a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->title}"]),
            'initiatives' => Initiative::orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn ($i) => ['value' => $i->id, 'label' => "{$i->code} — {$i->name}"]),
            'records' => DecisionRecord::orderBy('code')->get(['id', 'code', 'title'])
                ->map(fn ($r) => ['value' => $r->id, 'label' => "{$r->code} — {$r->title}"]),
            'drivers' => ['Regulatory', 'Cost', 'Risk', 'Capability', 'Obsolescence', 'Security', 'Resilience'],
        ];
    }

    public function store(Request $request)
    {
        Gate::authorize('create', DecisionRecord::class);

        $data = $this->validated($request);
        $data['code'] = $data['code'] ?? $this->nextCode();

        $record = DecisionRecord::create($data);

        // Recording a decision that supersedes another closes the older one —
        // an ADR is superseded, never rewritten, so the reasoning survives.
        if ($record->supersedes_id && $record->status === DecisionRecord::ACCEPTED) {
            DecisionRecord::where('id', $record->supersedes_id)
                ->update(['status' => DecisionRecord::SUPERSEDED]);
        }

        return redirect()
            ->route('ea.decisions.show', $record->id)
            ->with('success', "Decision record {$record->code} created.");
    }

    public function update(Request $request, DecisionRecord $record)
    {
        Gate::authorize('update', $record);

        $wasAccepted = $record->status === DecisionRecord::ACCEPTED;
        $data = $this->validated($request, $record);

        // An accepted decision is a historical fact. Its body must not be
        // quietly rewritten — that is exactly what the supersession chain is
        // for, and an assessor reading an edited ADR has no way to know.
        if ($wasAccepted) {
            foreach (['context', 'decision', 'consequences', 'alternatives'] as $immutable) {
                unset($data[$immutable]);
            }
        }

        $record->update($data);

        if ($record->supersedes_id && $record->status === DecisionRecord::ACCEPTED) {
            DecisionRecord::where('id', $record->supersedes_id)
                ->update(['status' => DecisionRecord::SUPERSEDED]);
        }

        return back()->with('success', $wasAccepted
            ? 'Status and links updated. The body of an accepted decision is immutable — supersede it with a new record to change the decision itself.'
            : 'Decision record updated.');
    }

    public function destroy(DecisionRecord $record)
    {
        Gate::authorize('delete', $record);

        if ($record->status === DecisionRecord::ACCEPTED) {
            return back()->with('error',
                'An accepted decision cannot be deleted — mark it deprecated, or supersede it. The governance trail has to stay resolvable.');
        }

        $record->delete();

        return redirect()->route('ea.decisions')->with('success', 'Decision record deleted.');
    }

    /**
     * Draft an ADR straight from an ARB verdict. §5.2 puts the ARB and the
     * decision record in the same workspace precisely so the verdict does not
     * stop at "approved" with the reasoning lost in the minutes.
     */
    public function fromArb(ArbSubmission $submission)
    {
        Gate::authorize('create', DecisionRecord::class);

        $existing = DecisionRecord::where('arb_submission_id', $submission->id)->first();
        if ($existing) {
            return redirect()->route('ea.decisions.show', $existing->id)
                ->with('success', 'This submission already has a decision record.');
        }

        $record = DecisionRecord::create([
            'code' => $this->nextCode(),
            'title' => $submission->title,
            'context' => $submission->summary,
            'decision' => $submission->decision_rationale,
            'status' => match ($submission->status) {
                'approved' => DecisionRecord::ACCEPTED,
                'rejected' => DecisionRecord::REJECTED,
                default => DecisionRecord::PROPOSED,
            },
            'decided_on' => optional($submission->decided_at)->toDateString(),
            'decided_by' => $submission->decided_by,
            'arb_submission_id' => $submission->id,
            'impacted_principles' => $submission->impacted_principles ?? [],
            'impacted_standards' => $submission->impacted_standards ?? [],
        ]);

        return redirect()->route('ea.decisions.show', $record->id)
            ->with('success', "Decision record {$record->code} drafted from ARB submission {$submission->code}. Complete the consequences before accepting it.");
    }

    private function validated(Request $request, ?DecisionRecord $record = null): array
    {
        $id = $record?->id;

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32', "unique:ea_decision_records,code,{$id}"],
            'title' => 'required|string|max:255',
            'context' => 'nullable|string',
            'decision' => 'nullable|string',
            'consequences' => 'nullable|string',
            'alternatives' => 'nullable|string',
            'status' => 'required|in:'.implode(',', DecisionRecord::STATUSES),
            'decided_on' => 'nullable|date',
            'decided_by' => 'nullable|string|max:255',
            'driver' => 'nullable|string|max:64',
            'supersedes_id' => 'nullable|integer|exists:ea_decision_records,id',
            'arb_submission_id' => 'nullable|integer|exists:ea_arb_submissions,id',
            'initiative_id' => 'nullable|integer|exists:ea_initiatives,id',
            'impacted_principles' => 'nullable|array',
            'impacted_standards' => 'nullable|array',
            'linked_entities' => 'nullable|array',
            'linked_entities.*.entity_type' => 'required_with:linked_entities|string',
            'linked_entities.*.entity_id' => 'required_with:linked_entities|integer',
        ]);

        if (! empty($data['supersedes_id']) && (int) $data['supersedes_id'] === (int) $id) {
            abort(422, 'A decision record cannot supersede itself.');
        }

        return $data;
    }

    private function nextCode(): string
    {
        $n = DecisionRecord::count() + 1;

        do {
            $code = 'ADR-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (DecisionRecord::where('code', $code)->exists());

        return $code;
    }
}
