<?php

namespace App\Http\Controllers;

use App\Models\CircularAssessment;
use App\Models\CircularImpact;
use App\Models\DocIntelligenceJob;
use App\Models\RegulatoryCircular;
use App\Models\ReturnRun;
use App\Models\ReturnTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DocIntel\DocumentExtractionService;
use App\Services\ReturnsCentre\ReturnRunPopulator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Regulatory Intelligence: the circular feed with each bank's triage, Document Intelligence, and the
 * Returns Centre template runner. Architecture-compiled returns live in RegulatoryReturnController.
 */
class RegulatoryIntelligenceController extends Controller
{
    /* ============================== Circular feed ============================== */

    public function regIntelIndex(Request $r)
    {
        $regulator = $r->query('regulator');
        $status = $r->query('status');
        $search = trim((string) $r->query('q', ''));

        $base = RegulatoryCircular::query()->visibleToTenant();

        $circulars = (clone $base)
            ->with('assessment.owner:id,name')
            ->withCount('impacts')
            ->when($regulator, fn ($q) => $q->where('regulator_code', $regulator))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->whereLike('title', "%{$search}%")
                ->orWhereLike('circular_number', "%{$search}%")))
            ->when($status, function ($q) use ($status) {
                if ($status === CircularAssessment::UNREAD) {
                    return $q->where(fn ($w) => $w->doesntHave('assessment')
                        ->orWhereHas('assessment', fn ($a) => $a->where('status', CircularAssessment::UNREAD)));
                }

                return $q->whereHas('assessment', fn ($a) => $a->where('status', $status));
            })
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->take(200)
            ->get()
            ->map(fn (RegulatoryCircular $c) => $this->circularRow($c));

        $counts = (clone $base)->select('regulator_code', DB::raw('count(*) as c'))
            ->groupBy('regulator_code')->pluck('c', 'regulator_code');

        // Triage counts across the whole visible feed, independent of the active filter.
        $total = (clone $base)->count();
        $byStatus = CircularAssessment::query()
            ->whereIn('circular_id', (clone $base)->select('id'))
            ->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
        $triage = [
            'unread' => $total - $byStatus->except(CircularAssessment::UNREAD)->sum(),
            'assessed' => (int) ($byStatus[CircularAssessment::ASSESSED] ?? 0),
            'actioned' => (int) ($byStatus[CircularAssessment::ACTIONED] ?? 0),
            'not_applicable' => (int) ($byStatus[CircularAssessment::NOT_APPLICABLE] ?? 0),
            'overdue' => CircularAssessment::query()
                ->whereIn('circular_id', (clone $base)->select('id'))
                ->where('status', CircularAssessment::ASSESSED)
                ->whereDate('due_date', '<', today())->count(),
        ];

        return Inertia::render('RegulatoryIntel/Index', [
            'circulars' => $circulars,
            'counts' => $counts,
            'triage' => $triage,
            'regulators' => RegulatoryCircular::REGULATORS,
            'filters' => ['regulator' => $regulator, 'status' => $status, 'q' => $search],
        ]);
    }

    public function regIntelShow(RegulatoryCircular $circular)
    {
        abort_unless(RegulatoryCircular::query()->visibleToTenant()->whereKey($circular->id)->exists(), 404);

        $circular->load('assessment.owner:id,name', 'assessment.assessor:id,name');
        $orgId = auth()->user()->organization_id;

        $impacts = $circular->impacts()->latest()->get()->map(function (CircularImpact $i) {
            $target = $i->target();
            [, $codeCol, $labelCol, $route] = CircularImpact::TYPES[$i->impacted_type] ?? [null, null, null, null];

            return [
                'id' => $i->id,
                'type' => $i->impacted_type,
                'target_id' => $i->impacted_id,
                'code' => $target?->{$codeCol},
                'label' => $target?->{$labelCol} ?? 'Record no longer exists',
                'href' => $target && $route ? ($route === 'obligations.index' ? route($route) : route($route, $target->getKey())) : null,
                'impact_score' => $i->impact_score,
                'rationale' => $i->rationale,
                'action_required' => $i->action_required,
            ];
        });

        $options = collect(CircularImpact::TYPES)->map(function ($def) {
            [$model, $codeCol, $labelCol] = $def;
            $query = $model::query()->orderBy($codeCol)->limit(300);

            return $query->get(['id', $codeCol, $labelCol])
                ->map(fn ($m) => ['value' => $m->id, 'label' => trim(($m->{$codeCol} ? $m->{$codeCol}.' — ' : '').$m->{$labelCol})]);
        });

        $a = $circular->assessment;

        return Inertia::render('RegulatoryIntel/Show', [
            'circular' => [
                'id' => $circular->id,
                'regulator_code' => $circular->regulator_code,
                'circular_number' => $circular->circular_number,
                'title' => $circular->title,
                'issued_at' => optional($circular->issued_at)->toDateString(),
                'ingested_at' => optional($circular->ingested_at)->toDayDateTimeString(),
                'source_url' => $circular->source_url,
                'plain_text' => $circular->plain_text,
                'llm_summary' => $circular->llm_summary,
                'impact_assessment' => $circular->impact_assessment,
                'status' => $circular->status,
                'is_private' => $circular->isPrivate(),
                'doc_intelligence_job_id' => $circular->doc_intelligence_job_id,
            ],
            'assessment' => $a ? [
                'status' => $a->status,
                'applicability' => $a->applicability,
                'severity' => $a->severity,
                'owner_id' => $a->owner_id,
                'owner' => $a->owner?->name,
                'due_date' => optional($a->due_date)->toDateString(),
                'notes' => $a->notes,
                'assessed_by' => $a->assessor?->name,
                'assessed_at' => optional($a->assessed_at)->toDayDateTimeString(),
                'actioned_at' => optional($a->actioned_at)->toDayDateTimeString(),
            ] : null,
            'impacts' => $impacts,
            'impactOptions' => $options,
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            'canEditorial' => auth()->user()->hasRole('Super Admin') && ! $circular->isPrivate(),
        ]);
    }

    public function regIntelAssess(Request $r, RegulatoryCircular $circular)
    {
        abort_unless(RegulatoryCircular::query()->visibleToTenant()->whereKey($circular->id)->exists(), 404);
        $orgId = (int) auth()->user()->organization_id;

        $data = $r->validate([
            'status' => ['required', Rule::in(CircularAssessment::STATUSES)],
            'applicability' => ['nullable', Rule::in(CircularAssessment::APPLICABILITY)],
            'severity' => ['nullable', Rule::in(CircularAssessment::SEVERITIES)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = $data['status'];
        $errors = [];
        if (in_array($status, [CircularAssessment::ASSESSED, CircularAssessment::ACTIONED], true)) {
            if (empty($data['applicability']) || $data['applicability'] === 'not_applicable') {
                $errors['applicability'] = 'Record whether the circular applies (applicable or partially applicable) before assessing it.';
            }
            if (empty($data['owner_id'])) {
                $errors['owner_id'] = 'Assign an owner who will action the circular.';
            }
        }
        if ($status === CircularAssessment::ACTIONED && $circular->impacts()->count() === 0) {
            $errors['status'] = 'Link at least one impacted obligation, control, policy or risk before marking the circular actioned.';
        }
        if ($status === CircularAssessment::NOT_APPLICABLE && blank($data['notes'] ?? null)) {
            $errors['notes'] = 'Record why the circular does not apply — an examiner will ask.';
        }
        if ($errors) {
            return back()->withErrors($errors);
        }

        $assessment = CircularAssessment::firstOrNew(['organization_id' => $orgId, 'circular_id' => $circular->id]);
        $previous = $assessment->status;
        $assessment->fill([
            'status' => $status,
            'applicability' => $status === CircularAssessment::NOT_APPLICABLE ? 'not_applicable' : ($data['applicability'] ?? null),
            'severity' => $data['severity'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        if ($status !== CircularAssessment::UNREAD && $previous !== $status) {
            $assessment->assessed_by_id = auth()->id();
            $assessment->assessed_at = now();
        }
        $assessment->actioned_at = $status === CircularAssessment::ACTIONED ? ($assessment->actioned_at ?? now()) : null;
        $assessment->save();

        activity()->performedOn($circular)->causedBy(auth()->user())
            ->withProperties(['status' => $status, 'from' => $previous])->log('circular.assessed');

        return back()->with('success', 'Circular triage saved — '.str_replace('_', ' ', $status).'.');
    }

    public function regIntelImpactStore(Request $r, RegulatoryCircular $circular)
    {
        abort_unless(RegulatoryCircular::query()->visibleToTenant()->whereKey($circular->id)->exists(), 404);

        $data = $r->validate([
            'impacted_type' => ['required', Rule::in(array_keys(CircularImpact::TYPES))],
            'impacted_id' => ['required', 'integer'],
            'impact_score' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'rationale' => ['required', 'string', 'max:2000'],
            'action_required' => ['nullable', 'string', 'max:255'],
        ]);

        // Resolved through the target model's tenant scope: another bank's record is not found.
        $model = CircularImpact::TYPES[$data['impacted_type']][0];
        if (! $model::query()->whereKey($data['impacted_id'])->exists()) {
            return back()->withErrors(['impacted_id' => 'Choose a record from your own register.']);
        }

        $duplicate = $circular->impacts()->where('impacted_type', $data['impacted_type'])
            ->where('impacted_id', $data['impacted_id'])->exists();
        if ($duplicate) {
            return back()->withErrors(['impacted_id' => 'That record is already linked to this circular.']);
        }

        $circular->impacts()->create($data + [
            'organization_id' => auth()->user()->organization_id,
            'impact_score' => $data['impact_score'] ?? 0,
        ]);

        return back()->with('success', 'Impact linked.');
    }

    public function regIntelImpactDestroy(RegulatoryCircular $circular, CircularImpact $impact)
    {
        abort_unless($impact->circular_id === $circular->id, 404);
        $impact->delete();

        $assessment = $circular->assessment;
        if ($assessment && $assessment->status === CircularAssessment::ACTIONED && $circular->impacts()->count() === 0) {
            $assessment->update(['status' => CircularAssessment::ASSESSED, 'actioned_at' => null]);
        }

        return back()->with('success', 'Impact removed.');
    }

    /** Content-Ops editorial decision on a platform circular (spec §4.4.4). Super Admin only. */
    public function regIntelEditorial(Request $r, RegulatoryCircular $circular)
    {
        abort_unless(auth()->user()->hasRole('Super Admin') && ! $circular->isPrivate(), 403);
        $data = $r->validate(['status' => ['required', Rule::in(['editorial_review', 'published', 'superseded'])]]);
        $circular->update(['status' => $data['status']]);

        return back()->with('success', 'Circular moved to '.str_replace('_', ' ', $data['status']).'.');
    }

    private function circularRow(RegulatoryCircular $c): array
    {
        $a = $c->assessment;

        return [
            'id' => $c->id,
            'regulator_code' => $c->regulator_code,
            'circular_number' => $c->circular_number,
            'title' => $c->title,
            'issued_at' => optional($c->issued_at)->toDateString(),
            'llm_summary' => $c->llm_summary,
            'status' => $c->status,
            'is_private' => $c->isPrivate(),
            'severity' => $a->severity ?? ($c->impact_assessment['severity'] ?? null),
            'triage' => $a->status ?? CircularAssessment::UNREAD,
            'owner' => $a?->owner?->name,
            'due_date' => optional($a?->due_date)->toDateString(),
            'is_overdue' => $a && $a->status === CircularAssessment::ASSESSED && $a->due_date && $a->due_date->lt(today()),
            'impacts_count' => $c->impacts_count,
        ];
    }

    /* ============================ Document Intelligence ============================ */

    public function docIntelIndex(Request $r)
    {
        $status = $r->query('status');
        $type = $r->query('type');

        $jobs = DocIntelligenceJob::with('uploader:id,name', 'reviewer:id,name')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($type, fn ($q) => $q->where('doc_type', $type))
            ->orderByDesc('created_at')->take(100)->get()
            ->map(fn (DocIntelligenceJob $j) => [
                'id' => $j->id,
                'doc_type' => $j->doc_type,
                'doc_type_label' => DocIntelligenceJob::TYPES[$j->doc_type] ?? $j->doc_type,
                'name' => $j->original_name ?: basename($j->source_file),
                'status' => $j->status,
                'confidence' => $j->confidence,
                'needs_confirmation' => $j->needsFieldConfirmation(),
                'uploaded_by' => $j->uploader?->name,
                'reviewed_by' => $j->reviewer?->name,
                'created_at' => $j->created_at?->toDateString(),
            ]);

        $all = DocIntelligenceJob::query();

        return Inertia::render('DocIntel/Index', [
            'jobs' => $jobs,
            'types' => DocIntelligenceJob::TYPES,
            'filters' => ['status' => $status, 'type' => $type],
            'stats' => [
                'total' => (clone $all)->count(),
                'awaiting_review' => (clone $all)->whereIn('status', ['extraction_done', 'awaiting_review'])->count(),
                'accepted' => (clone $all)->where('status', 'accepted')->count(),
                'rejected' => (clone $all)->where('status', 'rejected')->count(),
                'low_confidence' => (clone $all)->whereIn('status', ['extraction_done', 'awaiting_review'])
                    ->where('confidence', '<', DocIntelligenceJob::REVIEW_THRESHOLD)->count(),
            ],
            'threshold' => DocIntelligenceJob::REVIEW_THRESHOLD,
        ]);
    }

    public function docIntelUpload(Request $r, DocumentExtractionService $extractor)
    {
        $data = $r->validate([
            'doc_type' => ['required', Rule::in(array_keys(DocIntelligenceJob::TYPES))],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,docx,txt,md,csv'],
        ], ['file.required' => 'Choose a document to upload.']);

        $orgId = (int) auth()->user()->organization_id;
        $file = $data['file'];
        $path = $file->storeAs("doc-intel/{$orgId}", Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        $job = DocIntelligenceJob::create([
            'organization_id' => $orgId,
            'doc_type' => $data['doc_type'],
            'source_file' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'uploaded',
            'uploaded_by_id' => auth()->id(),
        ]);

        $job = $extractor->process($job);

        return redirect()->route('doc-intel.show', $job)->with(
            $job->confidence > 0 ? 'success' : 'warning',
            $job->confidence > 0
                ? 'Extracted '.round($job->confidence * 100).'% of the expected fields. Review them before accepting.'
                : 'No text layer could be read (a scanned PDF needs OCR). Enter the fields by hand to accept it.'
        );
    }

    public function docIntelShow(DocIntelligenceJob $job)
    {
        $job->load('uploader:id,name', 'reviewer:id,name', 'vendor:id,name', 'circular:id,title');
        $fields = DocumentExtractionService::FIELDS[$job->doc_type] ?? [];

        return Inertia::render('DocIntel/Show', [
            'job' => [
                'id' => $job->id,
                'doc_type' => $job->doc_type,
                'doc_type_label' => DocIntelligenceJob::TYPES[$job->doc_type] ?? $job->doc_type,
                'name' => $job->original_name ?: basename($job->source_file),
                'size_bytes' => $job->size_bytes,
                'status' => $job->status,
                'confidence' => $job->confidence,
                'needs_confirmation' => $job->needsFieldConfirmation(),
                'reviewable' => $job->isReviewable(),
                'has_file' => Storage::disk('local')->exists($job->source_file),
                'ocr_excerpt' => Str::limit((string) $job->ocr_text, 4000),
                'structured_output' => $job->structured_output ?? [],
                'uploaded_by' => $job->uploader?->name,
                'uploaded_by_id' => $job->uploaded_by_id,
                'reviewed_by' => $job->reviewer?->name,
                'reviewed_at' => optional($job->reviewed_at)->toDayDateTimeString(),
                'review_notes' => $job->review_notes,
                'vendor' => $job->vendor ? ['id' => $job->vendor->id, 'name' => $job->vendor->name] : null,
                'circular' => $job->circular ? ['id' => $job->circular->id, 'title' => $job->circular->title] : null,
                'created_at' => $job->created_at?->toDayDateTimeString(),
            ],
            'fields' => $fields,
            'required' => DocumentExtractionService::REQUIRED[$job->doc_type] ?? [],
            'vendors' => $job->doc_type === 'vendor_ddq'
                ? Vendor::orderBy('name')->get(['id', 'name'])->map(fn ($v) => ['value' => $v->id, 'label' => $v->name])
                : [],
            'regulators' => RegulatoryCircular::REGULATORS,
            'isUploader' => $job->uploaded_by_id === auth()->id(),
        ]);
    }

    /** Reviewer corrections to the extracted fields. */
    public function docIntelUpdate(Request $r, DocIntelligenceJob $job)
    {
        if (! $job->isReviewable()) {
            return back()->with('error', 'Only a job awaiting review can be edited.');
        }
        $keys = DocumentExtractionService::FIELDS[$job->doc_type] ?? [];
        $data = $r->validate(collect($keys)->mapWithKeys(fn ($k) => ["fields.{$k}" => ['nullable', 'string', 'max:2000']])->all());

        $fields = array_merge($job->structured_output ?? [], array_intersect_key($data['fields'] ?? [], array_flip($keys)));
        $job->update(['structured_output' => $fields]);

        return back()->with('success', 'Extracted fields updated.');
    }

    public function docIntelAccept(Request $r, DocIntelligenceJob $job, DocumentExtractionService $extractor)
    {
        if (! $job->isReviewable()) {
            return back()->with('error', 'This job has already been reviewed.');
        }
        if ($job->uploaded_by_id && $job->uploaded_by_id === auth()->id()) {
            return back()->with('error', 'The uploader cannot accept their own extraction — another reviewer must confirm it.');
        }

        $data = $r->validate([
            'vendor_id' => [Rule::requiredIf($job->doc_type === 'vendor_ddq'), 'nullable', 'integer',
                Rule::exists('vendors', 'id')->where('organization_id', auth()->user()->organization_id)],
            // Only an extraction below the review threshold needs the reviewer's explicit confirmation.
            'confirm_fields' => $job->needsFieldConfirmation() ? ['required', 'accepted'] : ['nullable'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'vendor_id.required' => 'Choose the vendor this DDQ belongs to.',
            'confirm_fields.required' => 'Confidence is below 90% — confirm you have checked every field.',
            'confirm_fields.accepted' => 'Confidence is below 90% — confirm you have checked every field.',
        ]);

        $missing = collect(DocumentExtractionService::REQUIRED[$job->doc_type] ?? [])
            ->filter(fn ($f) => blank($job->structured_output[$f] ?? null))->values();
        if ($missing->isNotEmpty()) {
            return back()->with('error', 'Fill in '.$missing->map(fn ($f) => str_replace('_', ' ', $f))->implode(', ').' before accepting.');
        }

        DB::transaction(function () use ($job, $data, $extractor) {
            $attributes = [
                'status' => 'accepted',
                'reviewed_by_id' => auth()->id(),
                'reviewed_at' => now(),
                'review_notes' => $data['review_notes'] ?? null,
            ];

            // "Send to Circular pipeline": the bank's own circular, triaged like any other.
            if (in_array($job->doc_type, ['circular', 'ndpc_decision'], true)) {
                $attributes['circular_id'] = $extractor->toCircular($job)->id;
            }
            // "Send to Vendor DDQ": the accepted questionnaire counts as the vendor's assessment.
            if ($job->doc_type === 'vendor_ddq') {
                $vendor = Vendor::findOrFail($data['vendor_id']);
                $vendor->update(['last_assessed' => today()]);
                $attributes['vendor_id'] = $vendor->id;
            }

            $job->update($attributes);
        });

        $job->refresh();
        $where = $job->circular_id ? ' and added to your circular feed' : ($job->vendor_id ? ' and recorded as the vendor\'s assessment' : '');

        return back()->with('success', "Extraction accepted{$where}.");
    }

    public function docIntelReject(Request $r, DocIntelligenceJob $job)
    {
        if (! $job->isReviewable()) {
            return back()->with('error', 'This job has already been reviewed.');
        }
        $data = $r->validate(['review_notes' => ['required', 'string', 'max:2000']],
            ['review_notes.required' => 'Say why the extraction is rejected.']);

        $job->update([
            'status' => 'rejected',
            'reviewed_by_id' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'],
        ]);

        return back()->with('success', 'Extraction rejected.');
    }

    public function docIntelDownload(DocIntelligenceJob $job)
    {
        abort_unless(Storage::disk('local')->exists($job->source_file), 404);

        return Storage::disk('local')->download($job->source_file, $job->original_name ?: basename($job->source_file));
    }

    /* ================================ Returns Centre ================================ */

    public function returnsIndex()
    {
        $runs = ReturnRun::with('template', 'preparer:id,name', 'approver:id,name')
            ->orderByDesc('period')->orderByDesc('id')->take(100)->get();

        $templates = ReturnTemplate::orderBy('regulator_code')->orderBy('name')->get()->map(function (ReturnTemplate $t) use ($runs) {
            $cadence = ReturnRunPopulator::cadence($t);
            $period = ReturnRunPopulator::currentPeriod($cadence);
            $current = $runs->first(fn ($run) => $run->template_id === $t->id && $run->period === $period);

            return [
                'id' => $t->id,
                'regulator_code' => $t->regulator_code,
                'return_code' => $t->return_code,
                'name' => $t->name,
                'version' => $t->version,
                'effective_from' => optional($t->effective_from)->toDateString(),
                'cadence' => $cadence,
                'current_period' => $period,
                'current_run_id' => $current?->id,
                'current_status' => $current?->status,
                'last_submitted' => $runs->where('template_id', $t->id)->where('status', ReturnRun::SUBMITTED)->max('period'),
            ];
        });

        return Inertia::render('Returns/Index', [
            'templates' => $templates,
            'runs' => $runs->map(fn (ReturnRun $run) => [
                'id' => $run->id,
                'template' => $run->template?->name,
                'regulator_code' => $run->template?->regulator_code,
                'period' => $run->period,
                'status' => $run->status,
                'prepared_by' => $run->preparer?->name,
                'approved_by' => $run->approver?->name,
                'overrides' => count($run->data['overrides'] ?? []),
                'submitted_at' => optional($run->submitted_at)->toDateString(),
                'submission_reference' => $run->submission_reference,
            ]),
        ]);
    }

    public function returnsGenerate(Request $r, ReturnRunPopulator $populator)
    {
        $data = $r->validate([
            'template_id' => ['required', 'integer', 'exists:return_templates,id'],
            'period' => ['nullable', 'string', 'max:16'],
        ]);
        $template = ReturnTemplate::findOrFail($data['template_id']);
        $cadence = ReturnRunPopulator::cadence($template);
        $period = $data['period'] ?: ReturnRunPopulator::currentPeriod($cadence);

        if (! preg_match(ReturnRunPopulator::periodPattern($cadence), $period)) {
            $example = ReturnRunPopulator::currentPeriod($cadence);

            return back()->withErrors(['period' => "This is a {$cadence} return — enter the period as {$example}."]);
        }

        $existing = ReturnRun::where('template_id', $template->id)->where('period', $period)->first();
        if ($existing) {
            return redirect()->route('returns.show', $existing)
                ->with('warning', "A {$period} run of {$template->name} already exists — opened it instead.");
        }

        $run = ReturnRun::create([
            'organization_id' => auth()->user()->organization_id,
            'template_id' => $template->id,
            'period' => $period,
            'status' => ReturnRun::DRAFT,
            'prepared_by_id' => auth()->id(),
            'data' => [],
        ]);
        $run->setRelation('template', $template);
        $run->update(['data' => $populator->populate($run)]);

        return redirect()->route('returns.show', $run)->with('success', "{$template->name} {$period} generated from your register.");
    }

    public function returnsShow(ReturnRun $run)
    {
        $run->load('template', 'preparer:id,name', 'approver:id,name', 'submitter:id,name');
        $definition = ReturnRunPopulator::definition($run->template);
        $values = $run->data['values'] ?? [];
        $overrides = $run->data['overrides'] ?? [];

        return Inertia::render('Returns/Show', [
            'run' => [
                'id' => $run->id,
                'template' => $run->template->name,
                'regulator_code' => $run->template->regulator_code,
                'return_code' => $run->template->return_code,
                'cadence' => $definition['cadence'],
                'period' => $run->period,
                'status' => $run->status,
                'prepared_by' => $run->preparer?->name,
                'prepared_by_id' => $run->prepared_by_id,
                'approved_by' => $run->approver?->name,
                'approved_at' => optional($run->approved_at)->toDayDateTimeString(),
                'submitted_by' => $run->submitter?->name,
                'submitted_at' => optional($run->submitted_at)->toDayDateTimeString(),
                'submission_reference' => $run->submission_reference,
                'populated_at' => isset($run->data['populated_at']) ? Carbon::parse($run->data['populated_at'])->toDayDateTimeString() : null,
            ],
            'fields' => collect($definition['fields'])->map(fn ($f) => $f + [
                'computed' => $values[$f['key']] ?? null,
                'override' => $overrides[$f['key']] ?? null,
            ])->values(),
            'can' => [
                'edit' => auth()->user()->can('edit platform') && $run->isEditable(),
                'approve' => auth()->user()->can('approve platform'),
                'is_preparer' => $run->prepared_by_id === auth()->id(),
            ],
        ]);
    }

    public function returnsOverride(Request $r, ReturnRun $run)
    {
        if (! $run->isEditable()) {
            return back()->with('error', 'Only a draft run can be changed. Send it back to draft first.');
        }
        $keys = collect(ReturnRunPopulator::definition($run->template)['fields'])->pluck('key')->all();
        $data = $r->validate([
            'key' => ['required', Rule::in($keys)],
            'value' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'required_unless:clear,true', 'string', 'max:500'],
            'clear' => ['nullable', 'boolean'],
        ], ['reason.required_unless' => 'Give a reason for the manual override — reviewers see it.']);

        $payload = $run->data ?? [];
        if (! empty($data['clear'])) {
            unset($payload['overrides'][$data['key']]);
        } else {
            $payload['overrides'][$data['key']] = [
                'value' => $data['value'],
                'reason' => $data['reason'],
                'by' => auth()->user()->name,
                'at' => now()->toIso8601String(),
            ];
        }
        $run->update(['data' => $payload]);

        return back()->with('success', ! empty($data['clear']) ? 'Override removed.' : 'Override recorded.');
    }

    public function returnsRefresh(ReturnRun $run, ReturnRunPopulator $populator)
    {
        if (! $run->isEditable()) {
            return back()->with('error', 'Only a draft run can be refreshed.');
        }
        $run->update(['data' => $populator->populate($run)]);

        return back()->with('success', 'Values recomputed from the current register. Manual overrides were kept.');
    }

    public function returnsTransition(Request $r, ReturnRun $run)
    {
        $data = $r->validate([
            'action' => ['required', Rule::in(['submit_for_review', 'return_to_draft', 'approve', 'submit'])],
            'submission_reference' => ['nullable', 'required_if:action,submit', 'string', 'max:128'],
        ], ['submission_reference.required_if' => 'Record the regulator portal or acknowledgement reference.']);
        $user = auth()->user();

        $transitions = [
            'submit_for_review' => [ReturnRun::DRAFT, ReturnRun::IN_REVIEW, 'edit platform'],
            'return_to_draft' => [ReturnRun::IN_REVIEW, ReturnRun::DRAFT, 'approve platform'],
            'approve' => [ReturnRun::IN_REVIEW, ReturnRun::APPROVED, 'approve platform'],
            'submit' => [ReturnRun::APPROVED, ReturnRun::SUBMITTED, 'approve platform'],
        ];
        [$from, $to, $permission] = $transitions[$data['action']];

        abort_unless($user->can($permission), 403);
        if ($run->status !== $from) {
            return back()->with('error', 'This run is '.str_replace('_', ' ', $run->status).' — that step is not available.');
        }
        if ($data['action'] === 'approve' && $run->prepared_by_id === $user->id) {
            return back()->with('error', 'The preparer cannot approve their own return — another approver must sign it off.');
        }

        $attributes = ['status' => $to];
        if ($data['action'] === 'approve') {
            $attributes += ['approved_by_id' => $user->id, 'approved_at' => now()];
        }
        if ($data['action'] === 'return_to_draft') {
            $attributes += ['approved_by_id' => null, 'approved_at' => null];
        }
        if ($data['action'] === 'submit') {
            $attributes += ['submitted_by_id' => $user->id, 'submitted_at' => now(), 'submission_reference' => $data['submission_reference']];
        }
        $run->update($attributes);

        activity()->performedOn($run)->causedBy($user)->withProperties(['from' => $from, 'to' => $to])->log('return_run.'.$data['action']);

        return back()->with('success', 'Return '.str_replace('_', ' ', $to).'.');
    }

    /** CSV of the return as filed: computed values, overrides and their reasons. */
    public function returnsExport(ReturnRun $run): StreamedResponse
    {
        $run->load('template');
        $definition = ReturnRunPopulator::definition($run->template);
        $values = $run->data['values'] ?? [];
        $overrides = $run->data['overrides'] ?? [];
        $name = "{$run->template->return_code}-{$run->period}.csv";

        return response()->streamDownload(function () use ($definition, $values, $overrides, $run) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Return', $run->template->name, 'Period', $run->period, 'Status', $run->status]);
            fputcsv($out, []);
            fputcsv($out, ['Field', 'Value', 'Source', 'Computed value', 'Override reason']);
            foreach ($definition['fields'] as $field) {
                $override = $overrides[$field['key']] ?? null;
                fputcsv($out, [
                    $field['label'],
                    $override ? $override['value'] : ($values[$field['key']] ?? ''),
                    $override ? 'manual override' : ($field['source'] ? 'auto-populated' : 'manual'),
                    $values[$field['key']] ?? '',
                    $override['reason'] ?? '',
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }
}
