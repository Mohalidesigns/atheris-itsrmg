<?php

namespace App\Services\Ea;

use App\Models\Ea\QualitySeal;
use App\Models\Ea\Subscription;
use App\Models\Ea\Survey;
use App\Models\Ea\SurveyCampaign;
use App\Models\Ea\SurveyResponse;
use App\Models\User;
use App\Notifications\Ea\SurveyInvitationNotification;
use App\Notifications\Ea\SurveyReminderNotification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * SurveyEngine — B2. §8.3: "Build campaigns, resolve audiences
 * (subscription-based or saved-query-based), issue magic links, ingest
 * responses, track completion, fire reminders."
 *
 * Modelled on Ardoq Broadcasts (§3.3), which ATH-EAR-002 calls "the single most
 * valuable thing to copy":
 *   • audience resolution from graph references (here: subscriptions) or from
 *     an arbitrary saved query;
 *   • trigger conditions on staleness ("not updated in 6 months");
 *   • one-time or recurring schedules;
 *   • automated reminders to non-respondents at 7–14 day intervals;
 *   • completion tracking.
 *
 * With one deliberate improvement over both incumbents: responses arrive by
 * magic link from people who hold no licence at all (§3.4 — LeanIX "can only
 * send surveys to active licensed users", a structural crowdsourcing ceiling).
 */
class SurveyEngine
{
    public function __construct(
        private ?OwnershipService $ownership = null,
        private ?QualitySealService $seals = null,
    ) {
        $this->ownership ??= new OwnershipService();
        $this->seals ??= new QualitySealService();
    }

    /* ------------------------------------------------------------------ */
    /* Scope and audience                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The entities a survey asks about: the catalogue narrowed by the saved
     * scope filter, then by the staleness trigger.
     */
    public function scopeEntities(Survey $survey): EloquentCollection
    {
        $type = $survey->entity_type;
        if (! EntityRegistry::supports($type)) {
            return new EloquentCollection();
        }

        $query = $type::query();

        foreach (($survey->scope_filter ?? []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            is_array($value)
                ? $query->whereIn($column, $value)
                : $query->where($column, $value);
        }

        $entities = $query->get();

        // Staleness trigger. Ardoq's named filter is "not updated in 6 months";
        // here "stale" means the quality seal has not been approved inside the
        // window — an entity nobody has *confirmed* is stale even if a job
        // touched a column yesterday.
        if ($survey->stale_after_days) {
            $cutoff = now()->subDays($survey->stale_after_days);
            $fresh = QualitySeal::where('entity_type', $type)
                ->where('state', QualitySeal::APPROVED)
                ->where('approved_at', '>=', $cutoff)
                ->pluck('entity_id')
                ->flip();

            $entities = $entities->reject(fn ($e) => $fresh->has($e->getKey()))->values();
        }

        return new EloquentCollection($entities->all());
    }

    /**
     * Who to ask about each entity.
     *
     * Subscription mode walks the ownership graph — the Ardoq mechanic where
     * "you add someone to an audience simply by drawing an Owns relationship".
     * Query mode falls back to the survey's named recipients, which is how a
     * campaign reaches people who are not yet in the graph at all (typically
     * the very campaign that is trying to discover who the owner is).
     *
     * @return Collection<int, array{entity:mixed, email:string, name:?string, user_id:?int, role:?string}>
     */
    public function resolveAudience(Survey $survey, EloquentCollection $entities): Collection
    {
        $roles = $survey->audience_roles ?: Subscription::APPROVER_ROLES;
        $type = $survey->entity_type;
        $additional = collect($survey->additional_recipients ?? [])
            ->map(fn ($r) => is_array($r) ? $r : ['email' => $r, 'name' => null])
            ->filter(fn ($r) => filter_var($r['email'] ?? null, FILTER_VALIDATE_EMAIL));

        // One query for every subscription in scope rather than one per entity.
        $subscriptions = Subscription::with('user')
            ->where('entity_type', $type)
            ->whereIn('entity_id', $entities->pluck('id'))
            ->whereIn('role', $roles)
            ->get()
            ->groupBy('entity_id');

        $audience = collect();

        foreach ($entities as $entity) {
            $targets = collect();

            if ($survey->audience_mode === 'subscription') {
                foreach ($subscriptions->get($entity->getKey(), collect()) as $subscription) {
                    if ($subscription->user?->email) {
                        $targets->push([
                            'email' => $subscription->user->email,
                            'name' => $subscription->user->name,
                            'user_id' => $subscription->user_id,
                            'role' => $subscription->role,
                        ]);
                    }
                }
            }

            foreach ($additional as $extra) {
                $targets->push([
                    'email' => $extra['email'],
                    'name' => $extra['name'] ?? null,
                    // Match to a user account if one happens to exist, so the
                    // response shows up in their My Tasks queue too.
                    'user_id' => User::where('email', $extra['email'])->value('id'),
                    'role' => null,
                ]);
            }

            foreach ($targets->unique(fn ($t) => strtolower($t['email'])) as $target) {
                $audience->push($target + ['entity' => $entity]);
            }
        }

        return $audience;
    }

    /* ------------------------------------------------------------------ */
    /* Launch                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Launch a campaign: snapshot the audience, mint one magic-link token per
     * (recipient × entity), and send the invitations.
     */
    public function launch(Survey $survey, ?User $launchedBy = null, bool $notify = true): SurveyCampaign
    {
        $entities = $this->scopeEntities($survey);
        $audience = $this->resolveAudience($survey, $entities);

        $campaign = DB::transaction(function () use ($survey, $entities, $audience, $launchedBy) {
            $campaign = SurveyCampaign::create([
                'survey_id' => $survey->id,
                'code' => $survey->code.'-'.now()->format('Ymd-His'),
                'state' => SurveyCampaign::RUNNING,
                'opens_at' => now(),
                'closes_at' => now()->addDays($survey->window_days ?: 14),
                'audience_snapshot' => $audience
                    ->map(fn ($a) => [
                        'email' => $a['email'],
                        'entity_id' => $a['entity']->getKey(),
                        'role' => $a['role'],
                    ])->values()->all(),
                'recipients_count' => $audience->count(),
                'entities_count' => $entities->count(),
                'responses_count' => 0,
                'launched_by' => $launchedBy?->name,
            ]);

            foreach ($audience as $target) {
                SurveyResponse::create([
                    'campaign_id' => $campaign->id,
                    'entity_type' => $survey->entity_type,
                    'entity_id' => $target['entity']->getKey(),
                    'recipient_user_id' => $target['user_id'],
                    'recipient_email' => $target['email'],
                    'recipient_name' => $target['name'],
                    'recipient_role' => $target['role'],
                    'token' => SurveyResponse::newToken(),
                    'state' => SurveyResponse::PENDING,
                ]);
            }

            if ($survey->isRecurring()) {
                $survey->next_run_at = $survey->advanceFrom(now());
                $survey->save();
            }

            return $campaign;
        });

        AuditLogger::logBare('survey.launch', Survey::class, $survey->id, [
            'campaign' => $campaign->code,
            'entities' => $entities->count(),
            'recipients' => $audience->count(),
        ]);

        if ($notify) {
            $this->sendInvitations($campaign);
        }

        return $campaign;
    }

    public function sendInvitations(SurveyCampaign $campaign): int
    {
        $sent = 0;

        foreach ($campaign->responses()->where('state', SurveyResponse::PENDING)->get() as $response) {
            $this->notify($response, new SurveyInvitationNotification($response));
            $sent++;
        }

        return $sent;
    }

    /**
     * Reminders to non-respondents. Ardoq reminds at 7–14 day intervals up to a
     * configured cap; R5 makes this a first-class mitigation for the risk that
     * recipients ignore magic links.
     */
    public function sendDueReminders(): int
    {
        $sent = 0;

        $campaigns = SurveyCampaign::with('survey')
            ->where('state', SurveyCampaign::RUNNING)
            ->get();

        foreach ($campaigns as $campaign) {
            $survey = $campaign->survey;
            if (! $survey) {
                continue;
            }

            $interval = $survey->reminder_interval_days ?: 7;
            $max = $survey->max_reminders ?? 2;

            $due = $campaign->responses()
                ->whereIn('state', [SurveyResponse::PENDING, SurveyResponse::OPENED])
                ->where('reminders_sent', '<', $max)
                ->where(function ($q) use ($interval) {
                    $q->whereNull('last_reminded_at')
                        ->orWhere('last_reminded_at', '<=', now()->subDays($interval));
                })
                ->get();

            foreach ($due as $response) {
                // Do not remind on the day of launch.
                if ($response->reminders_sent === 0 && $response->created_at?->diffInDays(now()) < $interval) {
                    continue;
                }

                $this->notify($response, new SurveyReminderNotification($response));
                $response->increment('reminders_sent');
                $response->last_reminded_at = now();
                $response->save();
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Deliver to a user's notification channels when they hold an account, and
     * to a bare email address when they do not — the non-licensed-respondent
     * path that beats LeanIX.
     */
    private function notify(SurveyResponse $response, $notification): void
    {
        try {
            if ($response->recipient_user_id && ($user = User::find($response->recipient_user_id))) {
                $user->notify($notification);

                return;
            }

            Notification::route('mail', $response->recipient_email)->notify($notification);
        } catch (\Throwable $e) {
            // A mail transport failure must not roll back a launch. The
            // campaign dashboard shows delivery counts either way, and the
            // magic link stays valid for a manual resend.
            report($e);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Ingest                                                              */
    /* ------------------------------------------------------------------ */

    public function findByToken(string $token): ?SurveyResponse
    {
        return SurveyResponse::withoutGlobalScopes()
            ->with(['campaign.survey'])
            ->where('token', $token)
            ->first();
    }

    public function markOpened(SurveyResponse $response): void
    {
        if ($response->state === SurveyResponse::PENDING) {
            $response->state = SurveyResponse::OPENED;
            $response->opened_at = now();
            $response->save();
        }
    }

    /**
     * Ingest a submission: validate the answers against the survey's field
     * schema, write them onto the entity, then re-score the seal.
     *
     * §5.4 B2 requires the form builder to write "directly to entity fields" —
     * a survey that lands in a separate answers table and needs an architect to
     * transcribe it does not keep a repository fresh.
     *
     * @return array{written:array<string,mixed>, seal:QualitySeal}
     */
    public function submit(SurveyResponse $response, array $answers, ?string $comment = null): array
    {
        $survey = $response->campaign?->survey;
        if (! $survey) {
            throw new \RuntimeException('This survey is no longer available.');
        }

        if (! $response->isOutstanding()) {
            throw new \RuntimeException('This response has already been submitted.');
        }

        if (! $response->campaign->isOpen()) {
            throw new \RuntimeException('This campaign has closed.');
        }

        $entity = EntityRegistry::find($response->entity_type, $response->entity_id);
        if (! $entity) {
            throw new \RuntimeException('The record this survey asks about no longer exists.');
        }

        // Only attributes the survey actually declared may be written — a
        // magic-link holder must not be able to set arbitrary columns.
        $allowed = collect($survey->fields ?? [])->pluck('attribute')->filter()->all();
        $written = [];

        foreach ($answers as $attribute => $value) {
            if (! in_array($attribute, $allowed, true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $entity->{$attribute} = $value;
            $written[$attribute] = $value;
        }

        DB::transaction(function () use ($entity, $response, $answers, $comment, $written) {
            if (! empty($written)) {
                // Saving fires ArchitectureEntityChanged → BreakQualitySeal.
                // recordVerification() below then re-states the seal, so the
                // net effect of a survey is a verification, not a breakage.
                $entity->save();
            }

            $response->fill([
                'state' => SurveyResponse::SUBMITTED,
                'answers' => $answers,
                'comment' => $comment,
                'submitted_at' => now(),
            ])->save();

            $response->campaign()->increment('responses_count');
        });

        $seal = $this->seals->recordVerification(
            $response->entity_type,
            $response->entity_id,
            $response->recipient_user_id ? User::find($response->recipient_user_id) : null,
            $response->recipient_name ?: $response->recipient_email,
        );

        AuditLogger::logBare('survey.respond', $response->entity_type, (int) $response->entity_id, [
            'campaign' => $response->campaign->code,
            'respondent' => $response->recipient_email,
            'attributes_written' => array_keys($written),
            'seal_state' => $seal->state,
        ]);

        return ['written' => $written, 'seal' => $seal];
    }

    public function decline(SurveyResponse $response, ?string $comment = null): void
    {
        $response->fill([
            'state' => SurveyResponse::DECLINED,
            'comment' => $comment,
            'submitted_at' => now(),
        ])->save();
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle & reporting                                               */
    /* ------------------------------------------------------------------ */

    /** Close campaigns past their window and expire outstanding responses. */
    public function closeExpiredCampaigns(): int
    {
        $closed = 0;

        $due = SurveyCampaign::where('state', SurveyCampaign::RUNNING)
            ->whereNotNull('closes_at')
            ->where('closes_at', '<=', now())
            ->get();

        foreach ($due as $campaign) {
            $campaign->responses()
                ->whereIn('state', [SurveyResponse::PENDING, SurveyResponse::OPENED])
                ->update(['state' => SurveyResponse::EXPIRED]);

            $campaign->state = SurveyCampaign::CLOSED;
            $campaign->save();
            $closed++;
        }

        return $closed;
    }

    /** Launch any recurring survey whose next run has come due. */
    public function launchDueRecurring(): int
    {
        $launched = 0;

        $due = Survey::where('is_active', true)
            ->where('cadence', '!=', 'once')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->get();

        foreach ($due as $survey) {
            $this->launch($survey);
            $launched++;
        }

        return $launched;
    }

    /** Completion analytics for the campaign dashboard. */
    public function campaignStats(SurveyCampaign $campaign): array
    {
        $byState = $campaign->responses()
            ->selectRaw('state, count(*) as c')
            ->groupBy('state')
            ->pluck('c', 'state');

        $total = (int) $byState->sum();
        $submitted = (int) ($byState[SurveyResponse::SUBMITTED] ?? 0);

        return [
            'total' => $total,
            'pending' => (int) ($byState[SurveyResponse::PENDING] ?? 0),
            'opened' => (int) ($byState[SurveyResponse::OPENED] ?? 0),
            'submitted' => $submitted,
            'declined' => (int) ($byState[SurveyResponse::DECLINED] ?? 0),
            'expired' => (int) ($byState[SurveyResponse::EXPIRED] ?? 0),
            'completion_rate' => $total ? round($submitted / $total * 100, 1) : 0.0,
            'days_remaining' => $campaign->daysRemaining(),
        ];
    }
}
