<?php

namespace App\Http\Controllers;

use App\Models\Risk;
use App\Models\Threat;
use App\Models\ThreatAssessment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ThreatController extends Controller
{
    /** Threat-intel taxonomy used by the seeded Nigerian-bank catalogue (and its MITRE ATT&CK tactics). */
    public const CATEGORIES = [
        'ai-threat', 'api', 'c2', 'cloud', 'credential', 'crypto', 'data-loss', 'discovery', 'evasion',
        'execution', 'fraud', 'insider', 'integrity', 'malware', 'natural', 'network', 'operational',
        'persistence', 'phishing', 'physical', 'regulatory', 'supply-chain', 'third-party',
    ];

    /** Intelligence feeds the threat was sourced from. */
    public const SOURCES = ['internal', 'ngcert', 'nitda', 'cbn', 'mitre-attck', 'ibm-x-force', 'external', 'partner'];

    private const SORTABLE = ['created_at', 'threat_id_code', 'name', 'severity', 'likelihood', 'last_seen'];

    public const TYPES = ['deliberate', 'accidental', 'environmental'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public function index(Request $request)
    {
        $query = Threat::withCount('assessments');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('threat_id_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDir = $request->get('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDir)->orderByDesc('id');

        $threats = $query->paginate(20)->withQueryString();

        return Inertia::render('Risks/Threats/Index', [
            'threats' => $threats,
            'filters' => $request->only(['category', 'severity', 'source', 'is_active', 'search', 'sort', 'direction']),
            'categories' => self::CATEGORIES,
            'severities' => self::SEVERITIES,
            'sources' => self::SOURCES,
        ]);
    }

    public function create()
    {
        return Inertia::render('Risks/Threats/Create', [
            'nextCode' => Threat::generateNextCode(auth()->user()->organization_id),
            'categories' => self::CATEGORIES,
            'sources' => self::SOURCES,
            'types' => self::TYPES,
            'severities' => self::SEVERITIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['threat_id_code'] = Threat::generateNextCode(auth()->user()->organization_id);

        $threat = Threat::create($validated);

        return redirect()->route('threats.show', $threat)
            ->with('success', "Threat {$threat->threat_id_code} registered successfully.");
    }

    public function show(Threat $threat)
    {
        $threat->load([
            'assessments' => fn ($q) => $q->with(['risk:id,title,risk_id_code', 'assessor:id,name'])->latest('assessment_date'),
        ]);

        return Inertia::render('Risks/Threats/Show', [
            'threat' => $threat,
            'risks' => Risk::active()->select('id', 'title', 'risk_id_code')->orderBy('risk_id_code')->get(),
        ]);
    }

    public function edit(Threat $threat)
    {
        return Inertia::render('Risks/Threats/Edit', [
            'threat' => $threat,
            'categories' => self::CATEGORIES,
            'sources' => self::SOURCES,
            'types' => self::TYPES,
            'severities' => self::SEVERITIES,
        ]);
    }

    public function update(Request $request, Threat $threat)
    {
        $threat->update($this->validated($request));

        return redirect()->route('threats.show', $threat)
            ->with('success', 'Threat updated successfully.');
    }

    public function destroy(Threat $threat)
    {
        $threat->delete();

        return redirect()->route('threats.index')
            ->with('success', "Threat {$threat->threat_id_code} deleted.");
    }

    /**
     * Record an assessment linking this threat to a risk.
     */
    public function storeAssessment(Request $request, Threat $threat)
    {
        $validated = $request->validate([
            'risk_id' => ['nullable', Rule::exists('risks', 'id')
                ->where('organization_id', auth()->user()->organization_id)->whereNull('deleted_at')],
            'likelihood' => 'nullable|integer|min:1|max:5|required_with:impact',
            'impact' => 'nullable|integer|min:1|max:5|required_with:likelihood',
            'analysis' => 'nullable|string',
            'recommendations' => 'nullable|string',
        ]);

        $validated['organization_id'] = auth()->user()->organization_id;
        $validated['threat_id'] = $threat->id;
        $validated['assessed_by'] = auth()->id();
        $validated['assessment_date'] = now()->toDateString();

        if (! empty($validated['likelihood']) && ! empty($validated['impact'])) {
            $validated['score'] = $validated['likelihood'] * $validated['impact'];
        }

        ThreatAssessment::create($validated);

        return back()->with('success', "Threat {$threat->threat_id_code} assessment recorded.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|in:'.implode(',', self::CATEGORIES),
            'source' => 'nullable|in:'.implode(',', self::SOURCES),
            'type' => 'nullable|in:'.implode(',', self::TYPES),
            'likelihood' => 'nullable|integer|min:1|max:5',
            'capability' => 'nullable|integer|min:1|max:5',
            'intent' => 'nullable|integer|min:1|max:5',
            'severity' => 'nullable|in:'.implode(',', self::SEVERITIES),
            'is_active' => 'nullable|boolean',
            'countermeasures' => 'nullable|string',
            'tags' => 'nullable|array',
            'last_seen' => 'nullable|date',
        ]);
    }
}
