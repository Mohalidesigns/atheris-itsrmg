<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Models\Ea\SurveyResponse;
use App\Services\Ea\EntityRegistry;
use App\Services\Ea\SurveyEngine;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * PortalController — WS 1.6, the business-user surface.
 *
 * ATH-EAR-002 §5.4 B16: "Ardoq Discover equivalent; required for B2 to land."
 * A survey engine with nowhere for the respondent to arrive is a mail merge.
 *
 * The respond action is deliberately **unauthenticated**. §3.4 identifies
 * LeanIX's licensed-user-only surveys as "a structural crowdsourcing ceiling",
 * and §5.4 B2 requires responses from non-licensed users. The token is the
 * credential:
 *   • 64 random characters, one per (recipient × entity) — a leaked link
 *     exposes exactly one record to one question set, not an account;
 *   • it grants no read access to the repository, only the fields the survey
 *     declared;
 *   • it stops working when the response is submitted or the campaign closes.
 */
class PortalController extends Controller
{
    public function __construct(private SurveyEngine $engine)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Public — magic link                                                 */
    /* ------------------------------------------------------------------ */

    public function respond(string $token)
    {
        $response = $this->engine->findByToken($token);

        if (! $response) {
            return Inertia::render('Ea/Portal/Invalid', [
                'reason' => 'This link is not recognised. It may have been mistyped, or the campaign may have been deleted.',
            ]);
        }

        $survey = $response->campaign?->survey;

        if ($response->state === SurveyResponse::SUBMITTED) {
            return Inertia::render('Ea/Portal/Thanks', [
                'entity' => $response->entityLabel(),
                'alreadyDone' => true,
                'submittedAt' => optional($response->submitted_at)->toDayDateTimeString(),
            ]);
        }

        if (! $response->campaign?->isOpen()) {
            return Inertia::render('Ea/Portal/Invalid', [
                'reason' => 'This response window has closed. Thank you for your time — if the record still needs correcting, contact your architecture team.',
            ]);
        }

        $this->engine->markOpened($response);

        // Pre-fill with what the repository currently believes, so the
        // respondent confirms rather than retypes. This is the difference
        // between a 20% and an 80% completion rate.
        $entity = EntityRegistry::find($response->entity_type, $response->entity_id);
        $current = [];
        foreach (($survey->fields ?? []) as $field) {
            $value = $entity?->{$field['attribute']} ?? null;
            $current[$field['attribute']] = is_array($value) ? $value : ($value === null ? '' : (string) $value);
        }

        return Inertia::render('Ea/Portal/Respond', [
            'token' => $token,
            'survey' => [
                'name' => $survey?->name,
                'description' => $survey?->description,
                'fields' => $survey?->fields ?? [],
            ],
            'entity' => [
                'label' => $response->entityLabel(),
                'type_label' => EntityRegistry::label($response->entity_type),
            ],
            'recipient' => [
                'name' => $response->recipient_name,
                'email' => $response->recipient_email,
                'role' => $response->recipient_role,
            ],
            'current' => $current,
            'closesAt' => optional($response->campaign->closes_at)->toFormattedDateString(),
        ]);
    }

    public function submit(Request $request, string $token)
    {
        $response = $this->engine->findByToken($token);
        abort_if(! $response, 404);

        $data = $request->validate([
            'answers' => 'required|array',
            'comment' => 'nullable|string|max:2000',
        ]);

        try {
            $result = $this->engine->submit($response, $data['answers'], $data['comment'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return Inertia::render('Ea/Portal/Thanks', [
            'entity' => $response->entityLabel(),
            'alreadyDone' => false,
            'updated' => array_keys($result['written']),
            'sealState' => $result['seal']->stateLabel(),
            'completeness' => $result['seal']->completeness,
        ]);
    }

    public function decline(Request $request, string $token)
    {
        $response = $this->engine->findByToken($token);
        abort_if(! $response, 404);

        $data = $request->validate(['comment' => 'nullable|string|max:2000']);

        $this->engine->decline($response, $data['comment'] ?? null);

        return Inertia::render('Ea/Portal/Thanks', [
            'entity' => $response->entityLabel(),
            'declined' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Authenticated — My Tasks                                            */
    /* ------------------------------------------------------------------ */

    /**
     * The Discover-style "My Tasks" queue. Ardoq delivers broadcasts to email
     * *and* to an in-product task list; a recipient who has already logged in
     * should not have to go hunting through their inbox.
     */
    public function myTasks(Request $request)
    {
        $user = $request->user();

        $open = SurveyResponse::with('campaign.survey')
            ->where('recipient_user_id', $user->id)
            ->whereIn('state', [SurveyResponse::PENDING, SurveyResponse::OPENED])
            ->get();

        $done = SurveyResponse::with('campaign.survey')
            ->where('recipient_user_id', $user->id)
            ->whereIn('state', [SurveyResponse::SUBMITTED, SurveyResponse::DECLINED, SurveyResponse::EXPIRED])
            ->orderByDesc('submitted_at')
            ->limit(25)
            ->get();

        $shape = fn ($r) => [
            'id' => $r->id,
            'entity' => $r->entityLabel(),
            'type_label' => EntityRegistry::label($r->entity_type),
            'survey' => $r->campaign?->survey?->name,
            'state' => $r->state,
            'closes_at' => optional($r->campaign?->closes_at)->toDateString(),
            'days_remaining' => $r->campaign?->daysRemaining(),
            'submitted_at' => optional($r->submitted_at)->toDateString(),
            'href' => route('ea.portal.respond', $r->token),
        ];

        return Inertia::render('Ea/Portal/MyTasks', [
            'open' => $open->map($shape)->values(),
            'done' => $done->map($shape)->values(),
        ]);
    }
}
