<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\AccessReviewCampaign;
use App\Models\AccessReviewItem;
use App\Models\Asset;
use App\Models\User;
use App\Services\Monitoring\AccessReviewService;
use App\Services\Monitoring\MonitoringMetrics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Access-review campaigns (ATH-VAL-013). The previous page was a hard-coded list with random
 * reviewers; campaigns now hold real entitlements and the reviewer's decision on each.
 */
class AccessReviewController extends Controller
{
    public function index(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $campaigns = AccessReviewCampaign::query()->with(['owner:id,name', 'reviewer:id,name'])
            ->withCount(['items', 'items as decided_count' => fn ($q) => $q->whereNotNull('decision'),
                'items as revoke_count' => fn ($q) => $q->whereIn('decision', ['revoke', 'modify']),
                'items as pending_confirmation_count' => fn ($q) => $q->whereIn('decision', ['revoke', 'modify'])->whereNull('revocation_confirmed_at')])
            ->when($request->status === 'overdue', fn ($q) => $q->where('status', 'in_progress')->whereDate('due_at', '<', today()))
            ->when(in_array($request->status, ['in_progress', 'completed'], true), fn ($q) => $q->where('status', $request->status))
            ->orderByRaw("case status when 'in_progress' then 0 else 1 end")->orderBy('due_at')->get()
            ->map(fn ($c) => $c->toArray() + ['overdue' => $c->isOverdue()]);

        return Inertia::render('Monitoring/AccessReviews', [
            'campaigns' => $campaigns,
            'metrics' => app(MonitoringMetrics::class)->summary($orgId),
            'users' => User::where('organization_id', $orgId)->orderBy('name')->get(['id', 'name']),
            'assets' => Asset::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'options' => ['categories' => AccessReviewCampaign::CATEGORIES, 'cadences' => AccessReviewCampaign::CADENCES, 'sources' => AccessReviewCampaign::SOURCES],
            'filters' => $request->only('status'),
            'canCreate' => $request->user()->can('create monitoring'),
        ]);
    }

    public function store(Request $request, AccessReviewService $service)
    {
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'system' => 'required|string|max:255',
            'asset_id' => ['nullable', Rule::exists('assets', 'id')->where('organization_id', $orgId)],
            'category' => ['required', Rule::in(AccessReviewCampaign::CATEGORIES)],
            'cadence' => ['required', Rule::in(AccessReviewCampaign::CADENCES)],
            'source' => ['required', Rule::in(array_keys(AccessReviewCampaign::SOURCES))],
            'reviewer_id' => ['required', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'due_at' => 'required|date|after_or_equal:today',
        ]);
        $campaign = $service->launch($data, $request->user());

        return redirect()->route('access-reviews.show', $campaign)->with('success', "{$campaign->campaign_code} launched with {$campaign->items()->count()} entitlement(s) to review.");
    }

    public function show(Request $request, AccessReviewCampaign $campaign)
    {
        $campaign->load(['owner:id,name', 'reviewer:id,name', 'asset:id,name', 'completer:id,name']);
        $items = $campaign->items()->with(['user:id,name', 'decider:id,name', 'confirmer:id,name'])
            ->when($request->filter === 'undecided', fn ($q) => $q->whereNull('decision'))
            ->when($request->filter === 'privileged', fn ($q) => $q->where('privileged', true))
            ->when($request->filter === 'to_confirm', fn ($q) => $q->whereIn('decision', ['revoke', 'modify'])->whereNull('revocation_confirmed_at'))
            ->orderByDesc('privileged')->orderBy('account_name')->get();
        $all = $campaign->items()->get(['decision', 'revocation_confirmed_at', 'privileged']);

        return Inertia::render('Monitoring/AccessReviewShow', [
            'campaign' => $campaign->toArray() + ['overdue' => $campaign->isOverdue(), 'source_label' => AccessReviewCampaign::SOURCES[$campaign->source] ?? $campaign->source],
            'items' => $items,
            'counts' => [
                'total' => $all->count(), 'decided' => $all->whereNotNull('decision')->count(),
                'keep' => $all->where('decision', 'keep')->count(), 'revoke' => $all->whereIn('decision', ['revoke', 'modify'])->count(),
                'to_confirm' => $all->filter(fn ($i) => in_array($i->decision, ['revoke', 'modify'], true) && ! $i->revocation_confirmed_at)->count(),
                'privileged' => $all->where('privileged', true)->count(),
            ],
            'filter' => $request->filter,
            'me' => $request->user()->id,
            'can' => ['decide' => $request->user()->can('edit monitoring'), 'complete' => $request->user()->can('approve monitoring')],
        ]);
    }

    public function addItem(Request $request, AccessReviewCampaign $campaign)
    {
        abort_unless($campaign->status === 'in_progress', 422, 'This campaign is closed.');
        $orgId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'account_name' => 'required|string|max:255',
            'entitlement' => 'required|string|max:255',
            'privileged' => 'boolean',
            'user_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $orgId)],
        ]);
        $campaign->items()->create($data + ['organization_id' => $campaign->organization_id, 'reviewer_id' => $campaign->reviewer_id, 'privileged' => false]);

        return back()->with('success', 'Entitlement added.');
    }

    public function decide(Request $request, AccessReviewItem $item, AccessReviewService $service)
    {
        $data = $request->validate(['decision' => ['required', Rule::in(AccessReviewItem::DECISIONS)], 'comment' => 'nullable|string|max:1000']);
        $service->decide($item, $data['decision'], $data['comment'] ?? null, $request->user());

        return back()->with('success', "{$item->account_name}: {$data['decision']}.");
    }

    public function confirm(Request $request, AccessReviewItem $item, AccessReviewService $service)
    {
        $service->confirm($item, $request->user());

        return back()->with('success', "Removal of {$item->entitlement} from {$item->account_name} confirmed.");
    }

    public function complete(Request $request, AccessReviewCampaign $campaign, AccessReviewService $service)
    {
        if ($pending = $service->complete($campaign, $request->user())) {
            return back()->with('success', "{$campaign->campaign_code}: {$pending}");
        }

        return back()->with('success', "{$campaign->campaign_code} completed.");
    }
}
