<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\ComplianceAssessment;
use App\Models\Control;
use App\Models\Policy;
use App\Services\Pci\PciPosture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * PCI DSS v4.0.1 (ATH-VAL-011). Every figure comes from the bank's own records: the PCI assessment
 * on the compliance engine, control mappings, evidence, and the CDE scoping of its assets. The
 * previous version generated requirement maturity, self-assessment answers and the control
 * matrix (partly with rand()), mapped controls at random on each page load, and showed
 * organisation 1's evidence to every bank.
 */
class PciController extends Controller
{
    public function __construct(private PciPosture $pci) {}

    protected function orgId(): int
    {
        return (int) auth()->user()?->organization_id;
    }

    public function dashboard()
    {
        $orgId = $this->orgId();
        $requirements = $this->pci->requirements($orgId);
        $assessment = $this->pci->assessment($orgId);
        $completed = $assessment?->status === 'completed' ? $assessment : null;
        $tested = $requirements->sum('tested');

        return Inertia::render('PCI/Dashboard', [
            'requirements' => $requirements->map(fn ($r) => collect($r)->except('subs'))->values(),
            'overall' => $tested ? (int) round($requirements->sum('in_place') / $tested * 100) : null,
            'assessment' => $assessment?->only(['id', 'title', 'status', 'start_date', 'end_date', 'overall_score']),
            'nextValidationDue' => $completed?->end_date?->copy()->addMonths(config('pci.validation_months'))->toDateString(),
            'validationType' => config('pci.validation_type'),
            'scoping' => $this->pci->scoping($orgId),
            'controls' => [
                'mapped' => $requirements->flatMap(fn ($r) => $r['subs']->flatMap(fn ($s) => $s['controls']->pluck('id')))->unique()->count(),
                'ineffective' => $requirements->flatMap(fn ($r) => $r['subs']->flatMap(fn ($s) => $s['controls']->where('effectiveness', 'ineffective')->pluck('id')))->unique()->count(),
                'unmapped_subs' => $requirements->sum('unmapped'),
            ],
            'relevantPolicies' => Policy::query()->where('status', 'active')
                ->where(fn ($q) => $q->whereLike('title', '%PCI%')->orWhereLike('title', '%card%')->orWhereLike('title', '%cryptograph%'))->count(),
            'frameworkId' => $this->pci->frameworkId(),
        ]);
    }

    /** CDE scoping: every asset with its PCI scope decision. */
    public function cde(Request $request)
    {
        $assets = Asset::query()->where('status', '!=', 'decommissioned')->with('owner:id,name')
            ->when($request->filled('scope'), fn ($q) => $request->input('scope') === 'unscoped'
                ? $q->whereNull('pci_scope') : $q->where('pci_scope', $request->input('scope')))
            ->orderByRaw("case pci_scope when 'cde' then 0 when 'connected' then 1 when 'out_of_scope' then 3 else 2 end")
            ->orderBy('asset_id_code')
            ->get(['id', 'asset_id_code', 'name', 'asset_type', 'category', 'criticality', 'criticality_inherited', 'data_classification', 'pci_scope', 'pci_scope_note', 'owner_id']);

        return Inertia::render('PCI/Cde', [
            'assets' => $assets,
            'scoping' => $this->pci->scoping($this->orgId()),
            'scopes' => PciPosture::SCOPES,
            'filters' => $request->only('scope'),
            'canEdit' => $request->user()->can('edit pci'),
        ]);
    }

    /** Record an asset's CDE scope; a decision needs its rationale (PCI DSS 12.5.2). */
    public function scope(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'pci_scope' => ['required', Rule::in(array_keys(PciPosture::SCOPES))],
            'pci_scope_note' => 'required|string|min:10|max:1000',
        ], ['pci_scope_note.required' => 'Record why the asset is in or out of scope; PCI DSS 12.5.2 requires the scope to be documented.']);

        $asset->update($data);

        return back()->with('success', "{$asset->asset_id_code} scoped as ".PciPosture::SCOPES[$data['pci_scope']].'.');
    }

    /** Sub-requirements with the controls mapped to each; mappings are managed here. */
    public function controls()
    {
        return Inertia::render('PCI/Controls', [
            'requirements' => $this->pci->requirements($this->orgId()),
            'controls' => Control::query()->orderBy('control_code')->get(['id', 'control_code', 'title', 'effectiveness']),
            'canEdit' => request()->user()->can('edit pci'),
        ]);
    }

    public function mapControl(Request $request)
    {
        $orgId = $this->orgId();
        $data = $request->validate([
            'requirement_id' => ['required', 'integer', Rule::exists('framework_requirements', 'id')->where('framework_id', $this->pci->frameworkId())->whereNotNull('parent_id')],
            'control_id' => ['required', 'integer', Rule::exists('controls', 'id')->where('organization_id', $orgId)->whereNull('deleted_at')],
        ]);

        DB::table('control_framework_mappings')->updateOrInsert(
            ['control_id' => $data['control_id'], 'requirement_id' => $data['requirement_id']],
            ['coverage' => 'full', 'created_at' => now(), 'updated_at' => now()],
        );

        return back()->with('success', 'Control mapped.');
    }

    public function unmapControl(Request $request, int $mapping)
    {
        $row = DB::table('control_framework_mappings as m')->join('controls as c', 'c.id', '=', 'm.control_id')
            ->join('framework_requirements as r', 'r.id', '=', 'm.requirement_id')
            ->where('m.id', $mapping)->where('c.organization_id', $this->orgId())->where('r.framework_id', $this->pci->frameworkId())
            ->first(['m.id']);
        abort_unless($row, 404);
        DB::table('control_framework_mappings')->where('id', $mapping)->delete();

        return back()->with('success', 'Mapping removed.');
    }

    /** Assessment (ROC / SAQ): PCI assessments on the compliance engine and the latest results. */
    public function saq()
    {
        $orgId = $this->orgId();
        $requirements = $this->pci->requirements($orgId);
        $subs = $requirements->flatMap(fn ($r) => $r['subs']);

        return Inertia::render('PCI/Saq', [
            'requirements' => $requirements,
            'assessment' => $this->pci->assessment($orgId)?->only(['id', 'title', 'status', 'start_date', 'end_date', 'overall_score']),
            'assessments' => ComplianceAssessment::query()->where('framework_id', $this->pci->frameworkId())->orderByDesc('created_at')
                ->get(['id', 'title', 'status', 'start_date', 'end_date', 'overall_score']),
            'summary' => collect(array_keys(PciPosture::LABELS))->mapWithKeys(fn ($s) => [$s => $subs->where('status', $s)->count()])->all() + ['total' => $subs->count()],
            'labels' => PciPosture::LABELS,
            'validationType' => config('pci.validation_type'),
            'frameworkId' => $this->pci->frameworkId(),
            'canAssess' => request()->user()->can('create compliance-assessments'),
        ]);
    }

    /** Requirement × sub-requirement grid coloured by the latest result. */
    public function matrix()
    {
        return Inertia::render('PCI/Matrix', [
            'requirements' => $this->pci->requirements($this->orgId()),
            'labels' => PciPosture::LABELS,
        ]);
    }

    /** Evidence attached to PCI assessment results and to the controls mapped to PCI requirements. */
    public function evidence()
    {
        $orgId = $this->orgId();
        $requirements = $this->pci->requirements($orgId);
        $subs = $requirements->flatMap(fn ($r) => $r['subs']);
        $resultIds = $subs->pluck('result_id')->filter();
        $controlIds = $subs->flatMap(fn ($s) => $s['controls']->pluck('id'))->unique();

        $items = DB::table('evidence')->where('organization_id', $orgId)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('evidenceable_type', 'App\\Models\\ComplianceResult')->whereIn('evidenceable_id', $resultIds))
                ->orWhere(fn ($q) => $q->where('evidenceable_type', 'App\\Models\\Control')->whereIn('evidenceable_id', $controlIds)))
            ->orderByDesc('created_at')->get(['id', 'title', 'type', 'status', 'evidenceable_type', 'evidenceable_id', 'valid_until', 'file_name', 'created_at']);

        // Which sub-requirements each item supports.
        $codesFor = fn ($e) => $subs->filter(fn ($s) => ($e->evidenceable_type === 'App\\Models\\ComplianceResult' && $s['result_id'] === $e->evidenceable_id)
            || ($e->evidenceable_type === 'App\\Models\\Control' && $s['controls']->contains('id', $e->evidenceable_id)))->pluck('code')->values();

        return Inertia::render('PCI/Evidence', [
            'items' => $items->map(fn ($e) => (array) $e + ['requirements' => $codesFor($e), 'expired' => $e->valid_until && $e->valid_until < now()->toDateString()])->values(),
            'byRequirement' => $requirements->map(fn ($r) => ['code' => $r['code'], 'title' => $r['title'],
                'with' => $r['subs']->where('evidence', '>', 0)->count(), 'total' => $r['subs']->count()])->values(),
        ]);
    }
}
