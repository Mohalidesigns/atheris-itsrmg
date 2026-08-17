<?php

namespace App\Http\Controllers\Returns;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Models\Returns\RegulatoryReturn;
use App\Services\Returns\ReturnCompiler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * RegulatoryReturnController — the suite-level Regulatory Returns module (A1).
 *
 * §5.5: "**Regulatory Returns is promoted out of EA.** The CSAT return, NDPC
 * CAR, localisation gap report and quarterly board pack draw on EA, CSAT, Risk,
 * Control, Vendor, Incident and BCP data. **Burying them inside EA hides the
 * product's best feature from the CISO who buys it.** It becomes a suite-level
 * module that *consumes* the EA graph."
 *
 * §1.4 is the commercial argument: "Do not sell an EA tool. Sell the regulatory
 * architecture return, and ship an EA repository as the machine that produces
 * it."
 */
class RegulatoryReturnController extends Controller
{
    public function __construct(private ReturnCompiler $compiler)
    {
    }

    public function index()
    {
        Gate::authorize('viewAny', \App\Models\Ea\Capability::class);

        $returns = RegulatoryReturn::with('legalEntity:id,name,jurisdiction')
            ->orderByDesc('period')
            ->orderBy('template')
            ->get()
            ->map(fn ($r) => $this->shape($r));

        return Inertia::render('RegulatoryReturns/Index', [
            'returns' => $returns,
            'templates' => ReturnCompiler::templateOptions(),
            'entities' => LegalEntity::orderBy('code')->get(['id', 'code', 'name', 'jurisdiction'])
                ->map(fn ($e) => ['value' => $e->id, 'label' => "{$e->name} ({$e->jurisdiction})"]),
            'calendar' => $this->calendar(),
            'summary' => [
                'total' => $returns->count(),
                'in_preparation' => $returns->where('state', RegulatoryReturn::IN_PREPARATION)->count(),
                'signed' => $returns->whereIn('state', [RegulatoryReturn::SIGNED, RegulatoryReturn::SUBMITTED])->count(),
                'overdue' => $returns->where('is_overdue', true)->count(),
                'low_confidence' => $returns->filter(fn ($r) => $r['evidence_confidence'] < 30)->count(),
            ],
        ]);
    }

    /**
     * §6.2 — "the compliance calendar as the product's clock".
     *
     * "Build this calendar into the platform as a first-class object … The EA
     * repository is what the returns read from — which is what keeps the
     * repository current."
     *
     * ⚠️ Several dates carry verification warnings in §6.2 and are read from
     * config so a design partner's confirmed date can be applied without a
     * deploy.
     */
    private function calendar(): array
    {
        $year = now()->year;

        $obligations = [
            [
                'obligation' => 'CBN CSAT self-assessment, CISO-signed',
                'instrument' => 'Cybersecurity Framework §2.5',
                'due' => "{$year}-".config('ea.returns.csat_due', '02-28'),
                'cadence' => 'Annual',
                'template' => 'csat',
                'caveat' => 'Date unverified — the exposure draft said 31 March. Confirm against the signed circular.',
            ],
            [
                'obligation' => 'NDPC Compliance Audit Return, filed via a licensed DPCO',
                'instrument' => 'NDPA / GAID 2025',
                'due' => "{$year}-".config('ea.returns.ndpc_car_due', '03-31'),
                'cadence' => 'Annual',
                'template' => 'ndpc_car',
                'caveat' => 'The 2025 cycle was extended to 30 May 2026; extensions appear to be a pattern.',
            ],
            [
                'obligation' => 'Payment data localisation compliance',
                'instrument' => 'Data localisation directive, 15 June 2026',
                'due' => config('ea.localisation.deadline', '2027-01-01'),
                'cadence' => 'One-off deadline',
                'template' => 'localisation_gap',
                'caveat' => null,
            ],
            [
                'obligation' => 'Market structure compliance',
                'instrument' => 'Market structure directive',
                'due' => config('ea.localisation.market_structure_deadline', '2026-12-31'),
                'cadence' => 'One-off deadline',
                'template' => null,
                'caveat' => null,
            ],
            [
                'obligation' => 'Architecture maturity assessment',
                'instrument' => 'ITSB v2.1',
                'due' => null,
                'cadence' => 'Periodic',
                'template' => 'itsb_maturity',
                'caveat' => 'Current enforcement posture of the IT Standards Governance Council is unverified.',
            ],
            [
                'obligation' => 'Cyber-threat intelligence return',
                'instrument' => 'App. II §1.3',
                'due' => null,
                'cadence' => 'Monthly, by the 5th',
                'template' => null,
                'caveat' => null,
            ],
            [
                'obligation' => 'Board report and vulnerability assessment of all IT assets',
                'instrument' => 'RBCF §1.1(viii), §1.2(iii), App. II §1.2',
                'due' => null,
                'cadence' => 'Quarterly',
                'template' => null,
                'caveat' => null,
            ],
            [
                'obligation' => 'Cyber incident notification',
                'instrument' => 'RBCF §5.0 / App. VII',
                'due' => null,
                'cadence' => 'Within 24 hours',
                'template' => null,
                'caveat' => null,
            ],
        ];

        return collect($obligations)->map(function ($o) {
            $due = $o['due'] ? \Illuminate\Support\Carbon::parse($o['due']) : null;

            return $o + [
                'days_remaining' => $due ? (int) round(now()->diffInDays($due, false)) : null,
            ];
        })->all();
    }

    public function show(RegulatoryReturn $return)
    {
        Gate::authorize('viewAny', \App\Models\Ea\Capability::class);

        $return->load(['legalEntity', 'owner:id,name,email', 'previous:id,code,period']);

        $citations = $return->citations()->orderBy('question_ref')->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'question_ref' => $c->question_ref,
                'label' => $c->label(),
                'entity_type' => $c->entity_type ? class_basename($c->entity_type) : null,
                'seal_state' => $c->seal_state_at_capture,
                'completeness' => $c->completeness_at_capture,
                'was_approved' => $c->wasApproved(),
                'note' => $c->note,
            ]);

        return Inertia::render('RegulatoryReturns/Show', [
            'return' => $this->shape($return) + [
                'sections' => $return->payload ?? [],
                'signoff_chain' => $return->signoff_chain ?? [],
                'archive_hash' => $return->archive_hash,
                'previous' => $return->previous ? [
                    'id' => $return->previous->id,
                    'code' => $return->previous->code,
                    'period' => $return->previous->period,
                ] : null,
            ],
            'citations' => $citations,
            'citationSummary' => [
                'total' => $citations->count(),
                'approved' => $citations->where('was_approved', true)->count(),
                'by_question' => $citations->groupBy('question_ref')->map->count(),
            ],
            'diff' => $this->compiler->diff($return),
            'minimumConfidence' => (int) config('ea.returns.minimum_evidence_confidence', 30),
        ]);
    }

    private function shape(RegulatoryReturn $return): array
    {
        return [
            'id' => $return->id,
            'code' => $return->code,
            'name' => $return->name,
            'template' => $return->template,
            'period' => $return->period,
            'regulator' => $return->regulator,
            'state' => $return->state,
            'tone' => $return->tone(),
            'due_date' => optional($return->due_date)->toDateString(),
            'days_to_due' => $return->daysToDue(),
            'is_overdue' => $return->isOverdue(),
            'is_sealed' => $return->isSealed(),
            'completeness' => $return->completeness,
            'evidence_confidence' => $return->evidence_confidence,
            'confidence_band' => $return->confidenceBand(),
            'summary' => $return->summary ?? [],
            'legal_entity' => $return->legalEntity?->name,
            'jurisdiction' => $return->legalEntity?->jurisdiction,
            'owner' => $return->owner?->name,
            'signed_by' => $return->signed_by,
            'signed_role' => $return->signed_role,
            'signed_at' => optional($return->signed_at)->toDayDateTimeString(),
            'generated_at' => optional($return->generated_at)->toDayDateTimeString(),
        ];
    }

    public function compile(Request $request)
    {
        Gate::authorize('create', \App\Models\Ea\Capability::class);

        $data = $request->validate([
            'template' => 'required|string|in:'.implode(',', array_keys(ReturnCompiler::TEMPLATES)),
            'period' => 'required|string|max:16',
            'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
        ]);

        $entity = $data['legal_entity_id'] ? LegalEntity::find($data['legal_entity_id']) : null;

        try {
            $return = $this->compiler->compile($data['template'], $data['period'], $entity, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('regulatory-returns.show', $return->id)->with('success',
            "{$return->code} compiled — {$return->completeness}% complete, "
            ."{$return->evidence_confidence}% of cited evidence approved.");
    }

    public function sign(Request $request, RegulatoryReturn $return)
    {
        Gate::authorize('approve', \App\Models\Ea\Capability::class);

        $data = $request->validate([
            'role' => 'required|string|max:64',
            'confirm' => 'required|accepted',
        ]);

        try {
            $this->compiler->sign($return, $request->user(), $data['role']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success',
            'Return signed and hash-sealed. It is now immutable — open a new period to supersede it.');
    }

    public function submit(RegulatoryReturn $return)
    {
        Gate::authorize('approve', \App\Models\Ea\Capability::class);

        if ($return->state !== RegulatoryReturn::SIGNED) {
            return back()->with('error', 'Only a signed return can be marked submitted.');
        }

        $return->update(['state' => RegulatoryReturn::SUBMITTED]);

        return back()->with('success', 'Marked as submitted to the regulator.');
    }

    /** The hash-sealed machine-readable artefact. */
    public function download(RegulatoryReturn $return)
    {
        Gate::authorize('export', \App\Models\Ea\Capability::class);

        if (! $return->archive_path || ! Storage::exists($return->archive_path)) {
            return back()->with('error', 'No sealed archive exists yet. Sign the return to generate one.');
        }

        return Storage::download($return->archive_path, "{$return->code}.json");
    }
}
