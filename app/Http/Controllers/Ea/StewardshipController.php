<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\SealPolicy;
use App\Models\Ea\Subscription;
use App\Models\Ea\SurveyResponse;
use App\Models\User;
use App\Services\Ea\EntityRegistry;
use App\Services\Ea\OwnershipService;
use App\Services\Ea\QualitySealService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * StewardshipController — WS 1.1 (ownership) and WS 1.3 (quality seal).
 *
 * These two mechanics share a surface: an owner is the person who clears a
 * seal, and the seal is the reason ownership matters. Splitting them across two
 * controllers would mean every screen loading both anyway.
 */
class StewardshipController extends Controller
{
    public function __construct(
        private OwnershipService $ownership,
        private QualitySealService $seals,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* My Architecture — the personal work list                            */
    /* ------------------------------------------------------------------ */

    public function myArchitecture(Request $request)
    {
        $user = $request->user();

        $subscriptions = Subscription::where('user_id', $user->id)->get();

        // Decorate each subscription with its entity and seal in bulk rather
        // than N+1 per row.
        $byType = $subscriptions->groupBy('entity_type');
        $items = collect();

        foreach ($byType as $type => $subs) {
            if (! EntityRegistry::supports($type)) {
                continue;
            }
            $definition = EntityRegistry::definition($type);
            $ids = $subs->pluck('entity_id');
            $entities = $type::whereIn('id', $ids)->get()->keyBy('id');
            $sealMap = $this->seals->mapFor($type, $ids);

            foreach ($subs as $sub) {
                $entity = $entities->get($sub->entity_id);
                if (! $entity) {
                    continue;
                }

                $items->push([
                    'id' => $sub->id,
                    'entity_type' => $type,
                    'entity_id' => $sub->entity_id,
                    'type_label' => $definition['label'],
                    'label' => EntityRegistry::describe($type, $sub->entity_id),
                    'role' => $sub->role,
                    'role_label' => $sub->roleLabel(),
                    'business_role' => $sub->business_role,
                    'is_approver' => $sub->isApprover(),
                    'seal' => $sealMap[$sub->entity_id] ?? null,
                    'href' => ($definition['showRoute'] ?? null)
                        ? route($definition['showRoute'], $sub->entity_id)
                        : (($definition['route'] ?? null) ? route($definition['route']) : null),
                ]);
            }
        }

        // Outstanding survey asks addressed to this person.
        $tasks = SurveyResponse::with('campaign.survey')
            ->where('recipient_user_id', $user->id)
            ->whereIn('state', [SurveyResponse::PENDING, SurveyResponse::OPENED])
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'label' => $r->entityLabel(),
                'survey' => $r->campaign?->survey?->name,
                'closes_at' => optional($r->campaign?->closes_at)->toDateString(),
                'href' => route('ea.portal.respond', $r->token),
            ]);

        $needsAttention = $items
            ->filter(fn ($i) => $i['is_approver'] && in_array(
                $i['seal']['state'] ?? 'none',
                [QualitySeal::CHECK_NEEDED, QualitySeal::DRAFT],
                true,
            ))
            ->values();

        return Inertia::render('Ea/MyArchitecture', [
            'items' => $items->sortBy('type_label')->values(),
            'tasks' => $tasks,
            'needsAttention' => $needsAttention,
            'summary' => [
                'owned' => $items->count(),
                'approver_of' => $items->where('is_approver', true)->count(),
                'needs_attention' => $needsAttention->count(),
                'open_tasks' => $tasks->count(),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Ownership dashboard                                                 */
    /* ------------------------------------------------------------------ */

    public function ownership(Request $request)
    {
        Gate::authorize('viewAny', \App\Models\Ea\Capability::class);

        $focusType = $request->query('type');
        $unowned = collect();

        if (EntityRegistry::supports($focusType)) {
            $unowned = $this->ownership->unownedOf($focusType, 200)->map(fn ($e) => [
                'id' => $e->getKey(),
                'label' => EntityRegistry::describe($focusType, $e->getKey()),
            ]);
        }

        return Inertia::render('Ea/Ownership', [
            'completeness' => $this->ownership->completenessByType(),
            'posture' => $this->seals->posture(),
            'focusType' => $focusType,
            'unowned' => $unowned,
            'entityTypes' => EntityRegistry::options(),
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'job_title'])
                ->map(fn ($u) => ['value' => $u->id, 'label' => $u->name.' ('.$u->email.')']),
            'roles' => collect(Subscription::ROLES)->map(fn ($r) => [
                'value' => $r,
                'label' => Subscription::ROLE_LABELS[$r],
            ]),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Subscriptions                                                       */
    /* ------------------------------------------------------------------ */

    /** JSON panel used by the seal/owner drawer on entity pages. */
    public function panel(Request $request)
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 404);

        $seal = $this->seals->sealFor($data['entity_type'], $data['entity_id']);
        $this->seals->recomputeCompleteness($seal);

        $definition = EntityRegistry::definition($data['entity_type']);
        $labels = ($definition['mandatory'] ?? []) + ($definition['optional'] ?? []);

        return response()->json([
            'entity_label' => EntityRegistry::describe($data['entity_type'], $data['entity_id']),
            'subscriptions' => $this->ownership->forEntity($data['entity_type'], $data['entity_id'])
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'user_id' => $s->user_id,
                    'name' => $s->user?->name,
                    'email' => $s->user?->email,
                    'role' => $s->role,
                    'role_label' => $s->roleLabel(),
                    'business_role' => $s->business_role,
                ]),
            'seal' => [
                'state' => $seal->state,
                'label' => $seal->stateLabel(),
                'tone' => $seal->tone(),
                'completeness' => $seal->completeness,
                'approved_by' => $seal->approved_by_name,
                'approved_at' => optional($seal->approved_at)->toDateTimeString(),
                'expires_at' => optional($seal->expires_at)->toDateString(),
                'days_to_expiry' => $seal->daysToExpiry(),
                'break_reason' => $seal->break_reason,
                'missing' => collect($seal->missing_attributes ?? [])
                    ->map(fn ($a) => $a === '__owner'
                        ? 'No Responsible or Accountable owner'
                        : ($labels[$a] ?? $a))
                    ->values(),
            ],
            'can_approve' => $this->ownership->canApprove(
                $request->user(), $data['entity_type'], $data['entity_id']
            ),
        ]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'user_id' => 'required|integer|exists:users,id',
            'role' => 'required|in:'.implode(',', Subscription::ROLES),
            'business_role' => 'nullable|string|max:64',
            'notes' => 'nullable|string',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 404);
        Gate::authorize('update', $data['entity_type']);

        $this->ownership->subscribe(
            $data['entity_type'],
            $data['entity_id'],
            $data['user_id'],
            $data['role'],
            $data['business_role'] ?? null,
            $data['notes'] ?? null,
        );

        // Adding an owner changes completeness (it is worth two points), but
        // per §3.4 subscriptions explicitly do NOT break the seal.
        $this->seals->recomputeCompleteness(
            $this->seals->sealFor($data['entity_type'], $data['entity_id'])
        );

        return back()->with('success', 'Owner assigned.');
    }

    public function unsubscribe(Subscription $subscription)
    {
        Gate::authorize('update', $subscription->entity_type);

        $type = $subscription->entity_type;
        $id = $subscription->entity_id;

        $this->ownership->unsubscribe($subscription);
        $this->seals->recomputeCompleteness($this->seals->sealFor($type, $id));

        return back()->with('success', 'Owner removed.');
    }

    /* ------------------------------------------------------------------ */
    /* Seal transitions                                                    */
    /* ------------------------------------------------------------------ */

    public function approveSeal(Request $request)
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 404);

        try {
            $seal = $this->seals->approve($data['entity_type'], $data['entity_id'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $until = optional($seal->expires_at)->toFormattedDateString();

        return back()->with('success', $until
            ? "Quality seal approved. It expires on {$until} and will need re-validating then."
            : 'Quality seal approved.');
    }

    public function flagSeal(Request $request)
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 404);

        $this->seals->flag($data['entity_type'], $data['entity_id'], $data['reason'], $request->user());

        return back()->with('success', 'Record flagged for review.');
    }

    public function rejectSeal(Request $request)
    {
        $data = $request->validate([
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 404);

        try {
            $this->seals->reject($data['entity_type'], $data['entity_id'], $data['reason'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Record marked as not trustworthy.');
    }

    /* ------------------------------------------------------------------ */
    /* Seal policy administration                                          */
    /* ------------------------------------------------------------------ */

    public function policies()
    {
        Gate::authorize('admin', \App\Models\Ea\Capability::class);

        $policies = collect(EntityRegistry::all())->map(function ($definition, $type) {
            $policy = SealPolicy::effectiveFor($type);

            return [
                'entity_type' => $type,
                'label' => $definition['plural'],
                'renewal_interval_days' => $policy->renewal_interval_days,
                'auto_expiry_enabled' => (bool) $policy->auto_expiry_enabled,
                'break_on_edit' => (bool) $policy->break_on_edit,
                'mandatory' => $policy->mandatory(),
                'mandatory_labels' => $definition['mandatory'],
                'optional_labels' => $definition['optional'],
                'configured' => $policy->exists,
            ];
        })->values();

        return Inertia::render('Ea/SealPolicies', [
            'policies' => $policies,
            'intervals' => SealPolicy::INTERVALS,
            'posture' => $this->seals->posture(),
        ]);
    }

    public function savePolicy(Request $request)
    {
        Gate::authorize('admin', \App\Models\Ea\Capability::class);

        $data = $request->validate([
            'entity_type' => 'required|string',
            'renewal_interval_days' => 'required|integer|in:'.implode(',', SealPolicy::INTERVALS),
            'auto_expiry_enabled' => 'boolean',
            'break_on_edit' => 'boolean',
            'mandatory_attributes' => 'nullable|array',
            'mandatory_attributes.*' => 'string',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 404);

        $policy = SealPolicy::firstOrNew(['entity_type' => $data['entity_type']]);
        $policy->fill([
            'renewal_interval_days' => $data['renewal_interval_days'],
            'auto_expiry_enabled' => $request->boolean('auto_expiry_enabled'),
            'break_on_edit' => $request->boolean('break_on_edit'),
            'mandatory_attributes' => $data['mandatory_attributes'] ?? [],
        ])->save();

        return back()->with('success', 'Seal policy saved. It applies at the next approval or scheduled run.');
    }
}
