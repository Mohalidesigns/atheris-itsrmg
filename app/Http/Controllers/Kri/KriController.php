<?php

namespace App\Http\Controllers\Kri;

use App\Http\Controllers\Controller;
use App\Models\Kri;
use App\Models\KriBreach;
use App\Models\Risk;
use App\Models\User;
use App\Services\Kri\KriMeasures;
use App\Services\Kri\KriMetrics;
use App\Services\Kri\KriService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * KRI register (ATH-VAL-014). Replaces the PlatformController stub, which listed every bank's KRIs
 * and readings, and whose readings were generated at random with a status that ignored direction.
 */
class KriController extends Controller
{
    public function index(Request $request, KriMetrics $metrics)
    {
        $kris = Kri::query()->with(['latestReading', 'owner:id,name', 'risk:id,risk_id_code,title', 'openBreach',
            'readings' => fn ($q) => $q->orderByDesc('recorded_at')->limit(12)])
            ->when($request->category, fn ($q, $c) => $q->where('category', $c))
            ->orderBy('category')->orderBy('code')->get();
        $rows = $kris->map(fn ($k) => [
            'id' => $k->id, 'code' => $k->code, 'name' => $k->name, 'category' => $k->category, 'unit' => $k->unit, 'direction' => $k->direction,
            'threshold_green' => $k->threshold_green, 'threshold_amber' => $k->threshold_amber, 'threshold_red' => $k->threshold_red,
            'source' => $k->source, 'frequency' => $k->frequency, 'owner' => $k->owner, 'risk' => $k->risk,
            'latest' => $k->latestReading, 'stale' => $k->isStale(), 'breach' => $k->openBreach,
            'trend' => $k->readings->reverse()->values()->map(fn ($r) => ['period' => $r->period, 'value' => $r->value, 'status' => $r->status]),
        ])->filter(fn ($k) => match ($request->status) {
            'red', 'amber', 'green' => $k['latest']?->status === $request->status,
            'stale' => $k['stale'],
            'breach' => (bool) $k['breach'],
            default => true,
        })->values();

        return Inertia::render('Kri/Index', [
            'kris' => $rows,
            'summary' => $metrics->summary((int) $request->user()->organization_id),
            'categories' => Kri::query()->distinct()->orderBy('category')->pluck('category'),
            'filters' => $request->only('status', 'category'),
            'canCreate' => $request->user()->can('create risks'),
        ]);
    }

    public function show(Request $request, Kri $kri)
    {
        $kri->load(['owner:id,name', 'risk:id,risk_id_code,title']);
        $orgId = (int) $request->user()->organization_id;

        return Inertia::render('Kri/Show', [
            'kri' => $kri->toArray() + ['stale' => $kri->isStale(), 'measurable' => app(KriMeasures::class)->supports($kri->code), 'period' => $kri->periodFor(now())],
            'readings' => $kri->readings()->with('recorder:id,name')->orderByDesc('recorded_at')->limit(52)->get(),
            'breaches' => $kri->breaches()->with(['acknowledger:id,name', 'issue:id,issue_code,status'])->orderByDesc('occurred_at')->get(),
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'risks' => Risk::query()->active()->orderBy('risk_id_code')->get(['id', 'risk_id_code', 'title']),
            'options' => ['frequencies' => array_keys(Kri::FREQUENCIES), 'sources' => Kri::SOURCES],
            'can' => ['record' => $request->user()->can('edit risks'), 'edit' => $request->user()->can('edit risks')],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $kri = Kri::create($data + ['organization_id' => $request->user()->organization_id, 'source' => 'manual']);

        return redirect()->route('kri.show', $kri)->with('success', "{$kri->code} created.");
    }

    public function update(Request $request, Kri $kri)
    {
        $kri->source === 'ea' && throw ValidationException::withMessages(['kri' => 'This KRI is published from EA; edit its definition there.']);
        $kri->update($this->validated($request, $kri));

        return back()->with('success', "{$kri->code} updated.");
    }

    /** Record a reading by hand: the status comes from the thresholds, not the person entering it. */
    public function record(Request $request, Kri $kri, KriService $service)
    {
        $kri->source === 'manual' || throw ValidationException::withMessages(['value' => 'This KRI is measured automatically; use "Measure now".']);
        $data = $request->validate([
            'value' => 'required|numeric|min:0',
            'recorded_at' => 'nullable|date|before_or_equal:now|after:'.now()->subYear()->toDateString(),
            'commentary' => [Rule::requiredIf(fn () => is_numeric($request->value) && $kri->statusFor((float) $request->value) !== 'green'), 'nullable', 'string', 'max:2000'],
        ], ['commentary.required' => 'Explain an amber or red reading: cause and what is being done.']);
        $reading = $service->record($kri, (float) $data['value'], $request->user(), $data['commentary'] ?? null, $data['recorded_at'] ?? null);

        return back()->with($reading->status === 'green' ? 'success' : 'warning', "{$kri->code} {$reading->period}: ".$kri->formatValue($reading->value)." ({$reading->status}).");
    }

    public function measure(Request $request, Kri $kri, KriService $service)
    {
        $reading = $service->measure($kri, $request->user());

        return back()->with($reading->status === 'green' ? 'success' : 'warning', "{$kri->code} measured: ".$kri->formatValue($reading->value)." ({$reading->status}).");
    }

    public function acknowledge(Request $request, KriBreach $breach, KriService $service)
    {
        $data = $request->validate(['action_plan' => 'required|string|min:15|max:2000']);
        $service->acknowledge($breach, $data['action_plan'], $request->user());

        return back()->with('success', 'Action plan recorded.');
    }

    private function validated(Request $request, ?Kri $kri): array
    {
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', Rule::unique('kris', 'code')->where('organization_id', $orgId)->ignore($kri?->id)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'category' => 'required|string|max:64',
            'unit' => 'required|string|max:32',
            'direction' => ['required', Rule::in(['higher_worse', 'lower_worse'])],
            'threshold_green' => 'required|numeric',
            'threshold_amber' => 'required|numeric',
            'threshold_red' => 'required|numeric',
            'frequency' => ['required', Rule::in(array_keys(Kri::FREQUENCIES))],
            'owner_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'risk_id' => ['nullable', Rule::exists('risks', 'id')->where('organization_id', $orgId)],
        ]);
        [$g, $a, $r] = [$data['threshold_green'], $data['threshold_amber'], $data['threshold_red']];
        $ordered = $data['direction'] === 'higher_worse' ? $g <= $a && $a <= $r : $g >= $a && $a >= $r;
        $ordered || throw ValidationException::withMessages(['threshold_amber' => $data['direction'] === 'higher_worse'
            ? 'For a KRI where higher is worse, thresholds must rise: green ≤ amber ≤ red.'
            : 'For a KRI where lower is worse, thresholds must fall: green ≥ amber ≥ red.']);

        return $data;
    }
}
