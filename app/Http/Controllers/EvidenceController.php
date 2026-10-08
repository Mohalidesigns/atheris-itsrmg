<?php

namespace App\Http\Controllers;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\Control;
use App\Models\Evidence;
use App\Models\Gap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Evidence repository: files, links and attestations attached to controls, assessment results
 * and gaps. Files live on the private disk under the organisation's folder and are only served
 * through an authorised download — never from a public URL.
 */
class EvidenceController extends Controller
{
    private const DISK = 'local';

    public function index(Request $request)
    {
        $query = Evidence::with(['uploader:id,name', 'reviewer:id,name', 'evidenceable']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('subject')) {
            $query->where('evidenceable_type', Evidence::SUBJECTS[$request->subject] ?? '-');
        }
        if ($request->filled('status')) {
            if ($request->status === 'expired') {
                $query->where(fn ($q) => $q->where('status', 'expired')->orWhereDate('valid_until', '<', today()));
            } else {
                $query->where('status', $request->status)
                    ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()));
            }
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('title', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")->orWhere('file_name', 'like', "%{$s}%"));
        }

        $evidence = $query->latest()->paginate(20)->withQueryString();
        $evidence->getCollection()->transform(fn ($e) => $this->present($e));

        $all = Evidence::get(['id', 'status', 'valid_until']);
        $stats = [
            'total' => $all->count(),
            'approved' => $all->where('status', 'approved')->filter(fn ($e) => ! $e->is_expired)->count(),
            'pending' => $all->where('status', 'pending')->filter(fn ($e) => ! $e->is_expired)->count(),
            'rejected' => $all->where('status', 'rejected')->count(),
            'expired' => $all->filter(fn ($e) => $e->is_expired)->count(),
        ];

        $user = auth()->user();

        return Inertia::render('Compliance/Evidence/Index', [
            'evidence' => $evidence,
            'stats' => $stats,
            'filters' => $request->only(['type', 'subject', 'status', 'search']),
            'types' => Evidence::TYPES,
            'subjects' => [
                'control' => Control::orderBy('control_code')->get(['id', 'control_code', 'title'])
                    ->map(fn ($c) => ['id' => $c->id, 'label' => "{$c->control_code} — {$c->title}"]),
                'gap' => Gap::open()->orderBy('gap_code')->get(['id', 'gap_code', 'title'])
                    ->map(fn ($g) => ['id' => $g->id, 'label' => "{$g->gap_code} — {$g->title}"]),
            ],
            'can' => [
                'create' => $user->can('create evidence'),
                'review' => $user->can('approve evidence'),
                'delete' => $user->can('delete evidence'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'type' => ['required', Rule::in(Evidence::TYPES)],
            'subject' => ['required', Rule::in(array_keys(Evidence::SUBJECTS))],
            'subject_id' => 'required|integer',
            'url' => 'nullable|required_if:type,url|url|max:2048',
            'file' => 'nullable|required_if:type,document,screenshot,log|file|max:20480|mimes:pdf,png,jpg,jpeg,gif,doc,docx,xls,xlsx,csv,txt,log,json,xml,zip,msg,eml',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
        ], [
            'file.required_if' => 'Attach the file for this evidence type.',
            'url.required_if' => 'Enter the link for URL evidence.',
        ]);

        $subject = $this->resolveSubject($validated['subject'], (int) $validated['subject_id']);
        $orgId = (int) auth()->user()->organization_id;

        $record = [
            'organization_id' => $orgId,
            'evidenceable_type' => $subject::class,
            'evidenceable_id' => $subject->getKey(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'url' => $validated['url'] ?? null,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'status' => 'pending',
            'uploaded_by' => auth()->id(),
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $record['file_path'] = $file->store("evidence/{$orgId}", self::DISK);
            $record['file_name'] = $file->getClientOriginalName();
            $record['file_size'] = $file->getSize();
            $record['mime_type'] = $file->getMimeType();
        }

        Evidence::create($record);

        return back()->with('success', 'Evidence uploaded — awaiting review.');
    }

    /** Approve or reject (with notes). The uploader cannot review their own evidence. */
    public function review(Request $request, Evidence $evidence)
    {
        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'review_notes' => 'nullable|required_if:decision,rejected|string|max:2000',
        ], ['review_notes.required_if' => 'Give the reason for rejecting this evidence.']);

        if ((int) $evidence->uploaded_by === (int) auth()->id() && ! auth()->user()->hasRole('Super Admin')) {
            throw ValidationException::withMessages(['decision' => 'Segregation of duties: evidence must be reviewed by someone other than the uploader.']);
        }

        $evidence->update([
            'status' => $validated['decision'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return back()->with('success', 'Evidence '.$validated['decision'].'.');
    }

    public function download(Evidence $evidence)
    {
        if ($evidence->type === 'url' && $evidence->url) {
            return redirect()->away($evidence->url);
        }

        $disk = collect([self::DISK, 'public'])->first(fn ($d) => $evidence->file_path && Storage::disk($d)->exists($evidence->file_path));
        abort_unless($disk, 404, 'The file for this evidence item is not available.');

        return Storage::disk($disk)->download($evidence->file_path, $evidence->file_name ?: basename($evidence->file_path));
    }

    public function destroy(Evidence $evidence)
    {
        if ($evidence->status === 'approved' && ! auth()->user()->hasRole(['Super Admin', 'Organization Admin'])) {
            throw ValidationException::withMessages(['evidence' => 'Approved evidence is part of the audit trail and can only be removed by an administrator.']);
        }

        if ($evidence->file_path) {
            foreach ([self::DISK, 'public'] as $disk) {
                Storage::disk($disk)->delete($evidence->file_path);
            }
        }
        $evidence->delete();

        return back()->with('success', 'Evidence removed.');
    }

    /** Only whitelisted subject types, and only records in the user's organisation. */
    private function resolveSubject(string $key, int $id): Model
    {
        $subject = match ($key) {
            'control' => Control::find($id),
            'gap' => Gap::find($id),
            'compliance_result' => ($r = ComplianceResult::find($id)) && ComplianceAssessment::whereKey($r->assessment_id)->exists() ? $r : null,
        };

        if (! $subject) {
            throw ValidationException::withMessages(['subject_id' => 'Choose a valid item to attach this evidence to.']);
        }

        return $subject;
    }

    /** Adds a readable label and link for the item the evidence supports. */
    private function present(Evidence $e): array
    {
        $s = $e->evidenceable;
        $subject = match (true) {
            $s instanceof Control => ['label' => "{$s->control_code} — {$s->title}", 'href' => route('controls.show', $s->id), 'kind' => 'Control'],
            $s instanceof Gap => ['label' => "{$s->gap_code} — {$s->title}", 'href' => route('gap-analysis.index', ['search' => $s->gap_code]), 'kind' => 'Gap'],
            $s instanceof ComplianceResult => ['label' => optional($s->requirement)->requirement_code.' in '.optional($s->assessment)->title, 'href' => $s->assessment_id ? route('compliance-assessments.show', $s->assessment_id) : null, 'kind' => 'Assessment result'],
            default => null,
        };

        return array_merge($e->makeHidden('evidenceable')->toArray(), [
            'subject' => $subject,
            'has_file' => (bool) $e->file_path,
        ]);
    }
}
