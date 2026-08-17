<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\Subscription;
use App\Models\Ea\Survey;
use App\Models\Ea\SurveyCampaign;
use App\Models\Ea\SurveyResponse;
use App\Services\Ea\EntityRegistry;
use App\Services\Ea\SurveyEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * SurveyController — WS 1.2, the authenticated half of the campaign engine.
 * The respondent-facing half lives in PortalController and is deliberately
 * unauthenticated.
 */
class SurveyController extends Controller
{
    public function __construct(private SurveyEngine $engine)
    {
    }

    public function index()
    {
        Gate::authorize('viewAny', \App\Models\Ea\Capability::class);

        $surveys = Survey::with('latestCampaign')->orderByDesc('updated_at')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'description' => $s->description,
                'entity_type' => $s->entity_type,
                'entity_label' => EntityRegistry::label($s->entity_type),
                'cadence' => $s->cadence,
                'is_active' => $s->is_active,
                'field_count' => count($s->fields ?? []),
                'stale_after_days' => $s->stale_after_days,
                'next_run_at' => optional($s->next_run_at)->toDateString(),
                'latest_campaign' => $s->latestCampaign ? [
                    'id' => $s->latestCampaign->id,
                    'code' => $s->latestCampaign->code,
                    'state' => $s->latestCampaign->state,
                    'completion' => $s->latestCampaign->completionRate(),
                    'recipients' => $s->latestCampaign->recipients_count,
                    'responses' => $s->latestCampaign->responses_count,
                    'closes_at' => optional($s->latestCampaign->closes_at)->toDateString(),
                ] : null,
            ]);

        $campaigns = SurveyCampaign::with('survey')->orderByDesc('created_at')->limit(25)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'code' => $c->code,
                'survey' => $c->survey?->name,
                'state' => $c->state,
                'recipients' => $c->recipients_count,
                'entities' => $c->entities_count,
                'responses' => $c->responses_count,
                'completion' => $c->completionRate(),
                'closes_at' => optional($c->closes_at)->toDateString(),
                'launched_by' => $c->launched_by,
            ]);

        return Inertia::render('Ea/Surveys', [
            'surveys' => $surveys,
            'campaigns' => $campaigns,
            'options' => $this->builderOptions(),
        ]);
    }

    /**
     * Everything the form builder needs: which entity types can be surveyed,
     * and for each, the attributes a respondent may legitimately be asked to
     * confirm.
     */
    private function builderOptions(): array
    {
        return [
            'entityTypes' => EntityRegistry::options(),
            'fieldsByType' => collect(EntityRegistry::all())
                ->map(fn ($d) => collect($d['surveyable'])
                    ->map(fn ($f, $attribute) => [
                        'attribute' => $attribute,
                        'label' => $f['label'],
                        'type' => $f['type'],
                        'options' => $f['options'] ?? null,
                    ])->values())
                ->all(),
            'scopeFieldsByType' => collect(EntityRegistry::all())
                ->map(fn ($d) => collect($d['mandatory'])->keys()->values())
                ->all(),
            'roles' => collect(Subscription::ROLES)
                ->map(fn ($r) => ['value' => $r, 'label' => Subscription::ROLE_LABELS[$r]])->values(),
            'cadences' => Survey::CADENCES,
        ];
    }

    public function store(Request $request)
    {
        Gate::authorize('create', \App\Models\Ea\Capability::class);

        $data = $this->validateSurvey($request);
        $data['code'] = $data['code'] ?? 'SUR-'.now()->format('YmdHis');

        $survey = Survey::create($data);

        return back()->with('success', "Survey {$survey->code} created. Launch it when you are ready to send.");
    }

    public function update(Request $request, Survey $survey)
    {
        Gate::authorize('update', \App\Models\Ea\Capability::class);

        $survey->update($this->validateSurvey($request, $survey));

        return back()->with('success', 'Survey updated. Changes apply to the next campaign, not to one already running.');
    }

    public function destroy(Survey $survey)
    {
        Gate::authorize('delete', \App\Models\Ea\Capability::class);

        if ($survey->campaigns()->where('state', SurveyCampaign::RUNNING)->exists()) {
            return back()->with('error', 'This survey has a campaign in flight. Close the campaign before deleting the definition.');
        }

        $survey->delete();

        return back()->with('success', 'Survey deleted.');
    }

    private function validateSurvey(Request $request, ?Survey $survey = null): array
    {
        $id = $survey?->id;

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32', "unique:ea_surveys,code,{$id}"],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'entity_type' => 'required|string',
            'scope_filter' => 'nullable|array',
            'fields' => 'required|array|min:1',
            'fields.*.attribute' => 'required|string',
            'fields.*.label' => 'required|string',
            'fields.*.type' => 'required|string',
            'audience_mode' => 'required|in:subscription,query',
            'audience_roles' => 'nullable|array',
            'audience_roles.*' => 'in:'.implode(',', Subscription::ROLES),
            'additional_recipients' => 'nullable|array',
            'cadence' => 'required|in:'.implode(',', Survey::CADENCES),
            'stale_after_days' => 'nullable|integer|min:1|max:1095',
            'window_days' => 'required|integer|min:1|max:365',
            'reminder_interval_days' => 'required|integer|min:1|max:90',
            'max_reminders' => 'required|integer|min:0|max:10',
            'is_active' => 'boolean',
        ]);

        abort_unless(EntityRegistry::supports($data['entity_type']), 422, 'Unsupported entity type.');

        // Only attributes declared surveyable for that type may be asked about.
        $surveyable = array_keys(EntityRegistry::definition($data['entity_type'])['surveyable'] ?? []);
        foreach ($data['fields'] as $field) {
            abort_unless(
                in_array($field['attribute'], $surveyable, true),
                422,
                "Attribute [{$field['attribute']}] cannot be collected by survey for this entity type.",
            );
        }

        // Normalise free-text recipients into {email, name} pairs.
        $data['additional_recipients'] = collect($data['additional_recipients'] ?? [])
            ->map(fn ($r) => is_array($r) ? $r : ['email' => trim((string) $r), 'name' => null])
            ->filter(fn ($r) => filter_var($r['email'] ?? null, FILTER_VALIDATE_EMAIL))
            ->values()->all();

        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* Campaigns                                                           */
    /* ------------------------------------------------------------------ */

    /** Dry run: how many entities and recipients would this reach? */
    public function preview(Survey $survey)
    {
        Gate::authorize('viewAny', \App\Models\Ea\Capability::class);

        $entities = $this->engine->scopeEntities($survey);
        $audience = $this->engine->resolveAudience($survey, $entities);

        return response()->json([
            'entities' => $entities->count(),
            'recipients' => $audience->count(),
            'unreachable' => $entities->count() - $audience->pluck('entity')->unique(fn ($e) => $e->getKey())->count(),
            'sample' => $audience->take(10)->map(fn ($a) => [
                'email' => $a['email'],
                'role' => $a['role'],
                'entity' => EntityRegistry::describe($survey->entity_type, $a['entity']->getKey()),
            ])->values(),
        ]);
    }

    public function launch(Request $request, Survey $survey)
    {
        Gate::authorize('create', \App\Models\Ea\Capability::class);

        $entities = $this->engine->scopeEntities($survey);
        if ($entities->isEmpty()) {
            return back()->with('error', 'Nothing is in scope for this survey — check the scope filter and the staleness trigger.');
        }

        $campaign = $this->engine->launch($survey, $request->user());

        if ($campaign->recipients_count === 0) {
            return back()->with('error',
                "Campaign {$campaign->code} was created but reached nobody: none of the {$entities->count()} record(s) "
                .'in scope has an owner in the selected roles. Assign owners, or add named recipients to the survey.');
        }

        return redirect()
            ->route('ea.surveys.campaign', $campaign->id)
            ->with('success', "Campaign {$campaign->code} launched to {$campaign->recipients_count} recipient(s) across {$campaign->entities_count} record(s).");
    }

    public function campaign(SurveyCampaign $campaign)
    {
        Gate::authorize('viewAny', \App\Models\Ea\Capability::class);

        $campaign->load('survey');

        $responses = $campaign->responses()->orderBy('recipient_email')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'entity' => $r->entityLabel(),
                'email' => $r->recipient_email,
                'name' => $r->recipient_name,
                'role' => $r->recipient_role,
                'is_licensed' => (bool) $r->recipient_user_id,
                'state' => $r->state,
                'reminders_sent' => $r->reminders_sent,
                'submitted_at' => optional($r->submitted_at)->toDateTimeString(),
                'answers' => $r->answers,
                'comment' => $r->comment,
            ]);

        return Inertia::render('Ea/SurveyCampaign', [
            'campaign' => [
                'id' => $campaign->id,
                'code' => $campaign->code,
                'state' => $campaign->state,
                'opens_at' => optional($campaign->opens_at)->toDateTimeString(),
                'closes_at' => optional($campaign->closes_at)->toDateTimeString(),
                'launched_by' => $campaign->launched_by,
                'entities_count' => $campaign->entities_count,
            ],
            'survey' => [
                'id' => $campaign->survey?->id,
                'name' => $campaign->survey?->name,
                'entity_label' => $campaign->survey ? EntityRegistry::label($campaign->survey->entity_type) : null,
                'fields' => $campaign->survey?->fields ?? [],
            ],
            'stats' => $this->engine->campaignStats($campaign),
            'responses' => $responses,
        ]);
    }

    /** Manual nudge, outside the scheduled reminder cadence. */
    public function remind(SurveyCampaign $campaign)
    {
        Gate::authorize('update', \App\Models\Ea\Capability::class);

        $outstanding = $campaign->responses()
            ->whereIn('state', [SurveyResponse::PENDING, SurveyResponse::OPENED])
            ->get();

        foreach ($outstanding as $response) {
            $response->increment('reminders_sent');
            $response->last_reminded_at = now();
            $response->save();
        }

        // Reuse the engine's delivery path so licensed and non-licensed
        // recipients are handled identically.
        $sent = 0;
        foreach ($outstanding as $response) {
            try {
                $notification = new \App\Notifications\Ea\SurveyReminderNotification($response);
                if ($response->recipient_user_id && ($u = \App\Models\User::find($response->recipient_user_id))) {
                    $u->notify($notification);
                } else {
                    \Illuminate\Support\Facades\Notification::route('mail', $response->recipient_email)
                        ->notify($notification);
                }
                $sent++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('success', "Reminder sent to {$sent} non-respondent(s).");
    }

    public function close(SurveyCampaign $campaign)
    {
        Gate::authorize('update', \App\Models\Ea\Capability::class);

        $campaign->responses()
            ->whereIn('state', [SurveyResponse::PENDING, SurveyResponse::OPENED])
            ->update(['state' => SurveyResponse::EXPIRED]);

        $campaign->update(['state' => SurveyCampaign::CLOSED, 'closes_at' => now()]);

        return back()->with('success', "Campaign {$campaign->code} closed. Outstanding invitations no longer accept responses.");
    }
}
