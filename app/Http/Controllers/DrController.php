<?php

namespace App\Http\Controllers;

use App\Models\DrExercise;
use App\Models\DrRunbook;
use App\Services\Continuity\ContinuityMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * DR runbooks and exercises (ATH-VAL-012). Replaces the PlatformController stubs, which listed
 * every bank's runbooks and exercises; both are now tenant-scoped, and an exercise records the
 * recovery time it achieved against the runbook's target.
 */
class DrController extends Controller
{
    public function runbooks()
    {
        $runbooks = DrRunbook::query()->withCount('exercises')->with(['exercises' => fn ($q) => $q->whereNotNull('result')->latest('finished_at')])->orderBy('name')->get();

        return Inertia::render('Dr/Runbooks', [
            'runbooks' => $runbooks->map(fn ($r) => $r->toArray() + ['last_result' => $r->exercises->first()?->result, 'last_exercised' => $r->exercises->first()?->finished_at]),
            'metrics' => app(ContinuityMetrics::class)->summary(),
        ]);
    }

    public function show(DrRunbook $runbook)
    {
        return Inertia::render('Dr/RunbookShow', [
            'runbook' => $runbook,
            'exercises' => $runbook->exercises()->orderByDesc('scheduled_at')->get(),
            'canRecord' => request()->user()->can('edit bcp-plans'),
        ]);
    }

    public function exercises()
    {
        return Inertia::render('Dr/Exercises', [
            'exercises' => DrExercise::query()->with('runbook:id,name,estimated_duration_min')->orderByDesc('scheduled_at')->limit(100)->get(),
        ]);
    }

    /** Record an exercise: it passes when recovery was achieved within the runbook's RTO. */
    public function record(Request $request, DrRunbook $runbook)
    {
        $data = $request->validate([
            'started_at' => 'required|date|before_or_equal:now',
            'rto_achieved_min' => 'required|integer|min:1|max:10080',
            'participants' => 'nullable|string|max:1000',
            'outcome_notes' => 'required|string|min:10',
            'lessons' => 'nullable|string',
        ]);
        $target = $runbook->estimated_duration_min;
        $started = Carbon::parse($data['started_at']);

        $exercise = DrExercise::create([
            'organization_id' => $runbook->organization_id, 'runbook_id' => $runbook->id,
            'scheduled_at' => $started, 'started_at' => $started, 'finished_at' => $started->copy()->addMinutes($data['rto_achieved_min']),
            'participants' => array_values(array_filter(array_map('trim', explode(',', $data['participants'] ?? '')))),
            'outcome_notes' => $data['outcome_notes'], 'lessons' => $data['lessons'] ?? null, 'status' => 'completed',
            'rto_target_min' => $target, 'rto_achieved_min' => $data['rto_achieved_min'],
            'result' => $target === null ? null : ($data['rto_achieved_min'] <= $target ? 'pass' : ($data['rto_achieved_min'] <= $target * 1.5 ? 'partial' : 'fail')),
        ]);

        return back()->with('success', $exercise->result === 'pass'
            ? "Exercise recorded: recovered in {$exercise->rto_achieved_min} min, within the {$target}-minute target."
            : "Exercise recorded: {$exercise->rto_achieved_min} min against a {$target}-minute target.");
    }
}
