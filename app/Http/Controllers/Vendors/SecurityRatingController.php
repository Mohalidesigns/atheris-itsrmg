<?php

namespace App\Http\Controllers\Vendors;

use App\Http\Controllers\Controller;
use App\Models\TprmBreachEvent;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use App\Services\Vendors\SecurityRatingService;
use App\Services\Vendors\VendorMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Security Ratings and the breach watch (plan gap D8.3). Replaces the PlatformController stub,
 * which listed every bank's ratings and breach headlines (the tables had no organisation) against
 * vendor ids that no longer existed.
 */
class SecurityRatingController extends Controller
{
    public function index(Request $request)
    {
        $vendors = Vendor::query()->where('status', '!=', 'terminated')
            ->with(['latestRating', 'ratings' => fn ($q) => $q->where('captured_at', '>=', now()->subMonths(6))->orderBy('captured_at')])
            ->when($request->filled('tier'), fn ($q) => $q->where('tier', $request->integer('tier')))
            ->orderBy('tier')->orderBy('name')
            ->get(['id', 'vendor_code', 'name', 'category', 'tier', 'risk_level', 'domain', 'status'])
            ->map(fn ($v) => [
                'id' => $v->id, 'code' => $v->vendor_code, 'name' => $v->name, 'category' => $v->category,
                'tier' => $v->tier, 'domain' => $v->domain,
                'grade' => $v->latestRating?->grade, 'rating' => $v->latestRating?->rating_value,
                'delta' => $v->latestRating?->delta_from_previous, 'captured_at' => $v->latestRating?->captured_at,
                'provider' => $v->latestRating?->provider,
                'trend' => $v->ratings->pluck('rating_value')->values(),
            ]);

        $breaches = TprmBreachEvent::query()->with(['vendor:id,name,vendor_code,tier', 'reviewer:id,name', 'reassessment:id,breach_event_id,status'])
            ->when($request->input('events') !== 'all', fn ($q) => $q->where('status', 'new'))
            ->orderByDesc('discovered_at')->limit(50)->get();

        return Inertia::render('Tprm/Ratings', [
            'vendors' => $vendors,
            'breaches' => $breaches,
            'metrics' => app(VendorMetrics::class)->summary(),
            'filters' => $request->only(['tier', 'events']),
            'dropAlert' => config('tprm.rating_drop_alert'),
            'can' => ['edit' => $request->user()->can('edit vendors')],
        ]);
    }

    public function refresh(Request $request, SecurityRatingService $ratings)
    {
        $result = $ratings->refresh($request->user());

        return back()->with('success', "Ratings refreshed (sandbox provider): {$result['captured']} captured, {$result['skipped']} skipped (already rated today or no domain on record).");
    }

    /**
     * Triage a breach-watch item. "Reassess" opens an incident-driven assessment of the vendor,
     * linked to the event, so the follow-up is tracked in the assessment register.
     */
    public function triage(Request $request, TprmBreachEvent $event)
    {
        abort_unless($event->status === 'new', 422, 'This event has already been triaged.');

        $data = $request->validate([
            'decision' => ['required', Rule::in(['acknowledged', 'dismissed', 'reassessment'])],
            'notes' => 'required_if:decision,dismissed|nullable|string|max:2000',
        ]);

        DB::transaction(function () use ($event, $data, $request) {
            $event->update([
                'status' => $data['decision'],
                'review_notes' => $data['notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            if ($data['decision'] === 'reassessment') {
                VendorAssessment::create([
                    'organization_id' => $event->organization_id,
                    'vendor_id' => $event->vendor_id,
                    'breach_event_id' => $event->id,
                    'title' => 'Incident-driven reassessment — '.$event->headline,
                    'assessment_type' => 'incident_driven',
                    'status' => 'pending',
                    'assessed_by' => $request->user()->id,
                    'assessment_date' => now()->toDateString(),
                    'findings' => $data['notes'] ?? null,
                ]);
            }
        });

        return back()->with('success', match ($data['decision']) {
            'reassessment' => 'Incident-driven reassessment opened for '.$event->vendor->name.'.',
            'dismissed' => 'Event dismissed.',
            default => 'Event acknowledged.',
        });
    }
}
