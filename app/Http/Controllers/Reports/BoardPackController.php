<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\BoardPackRun;
use App\Models\BoardPackTemplate;
use App\Services\Finance\FinanceMasking;
use App\Services\Reports\BoardPackBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Board packs (ATH-VAL-014). Replaces the PlatformController stub, which recorded every pack against
 * organisation 1 with PPTX/PDF paths that were never written. A pack is now a snapshot of the
 * bank's figures, printable to PDF, prepared by one person and approved by another.
 */
class BoardPackController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('BoardPacks/Index', [
            'templates' => BoardPackTemplate::orderBy('name')->get()->map(fn ($t) => $t->toArray() + [
                'section_titles' => collect($t->sections)->map(fn ($s) => BoardPackBuilder::SECTIONS[$s] ?? $s)->unique()->values()]),
            'runs' => BoardPackRun::query()->with(['template:id,name', 'preparer:id,name', 'approver:id,name'])->orderByDesc('generated_at')->limit(50)
                ->get(['id', 'template_id', 'title', 'period', 'status', 'generated_at', 'prepared_by', 'approved_by', 'approved_at']),
            'canCreate' => $request->user()->can('create reports'),
        ]);
    }

    public function generate(Request $request, BoardPackBuilder $builder)
    {
        $data = $request->validate([
            'template_id' => 'required|exists:board_pack_templates,id',
            'period' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);
        $run = $builder->generate(BoardPackTemplate::findOrFail($data['template_id']), $request->user(), $data['period'] ?? null);

        return redirect()->route('board-packs.show', $run)->with('success', 'Board pack generated from current figures. Review it, then pass it to an approver.');
    }

    public function show(Request $request, BoardPackRun $run)
    {
        $run->load(['template:id,name,navy_variant', 'preparer:id,name', 'approver:id,name']);
        if (is_array($run->content)) {
            $run->content = app(FinanceMasking::class)->boardContent($run->content, $request->user());
        }

        return Inertia::render('BoardPacks/Show', [
            'run' => $run,
            'can' => ['approve' => $request->user()->can('approve reports') && (int) $run->prepared_by !== (int) $request->user()->id],
        ]);
    }

    public function approve(Request $request, BoardPackRun $run, BoardPackBuilder $builder)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:2000']);
        if ($pending = $builder->approve($run, $request->user(), $data['notes'] ?? null)) {
            return back()->with('success', $pending);
        }

        return back()->with('success', 'Board pack approved for the board.');
    }
}
