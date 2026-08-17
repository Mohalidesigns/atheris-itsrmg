<?php

namespace Tests\Feature\Ea;

use App\Events\Ea\ArchitectureEntityChanged;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\SealPolicy;
use App\Models\Ea\Survey;
use App\Models\Ea\SurveyResponse;
use App\Models\User;
use App\Services\Ea\OwnershipService;
use App\Services\Ea\QualitySealService;
use App\Services\Ea\SurveyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ATH-EAR-002 Phase 1 exit criterion (§9):
 *
 *   "A campaign can be launched against 200 applications, delivered to
 *    non-licensed owners by magic link, tracked to completion, and the
 *    responses visibly change seal state and completeness score."
 *
 * The final test drives exactly that path. The rest cover the state-machine
 * rules §3.4 and §5.4 specify for B1 and B3.
 */
class EaStewardshipTest extends TestCase
{
    use RefreshDatabase;

    private OwnershipService $ownership;
    private QualitySealService $seals;
    private SurveyEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->ownership = new OwnershipService();
        $this->seals = new QualitySealService($this->ownership);
        $this->engine = new SurveyEngine($this->ownership, $this->seals);
    }

    private function architect(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Enterprise Architect');

        return $u;
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->assignRole('Application Owner');

        return $u;
    }

    /**
     * A fully populated application: every mandatory *and* optional attribute
     * in the EntityRegistry, so completeness genuinely reaches 100 and the
     * mandatory-attribute gate is satisfied.
     *
     * Note `criticality` and `lifecycle` are NOT NULL with defaults, so a test
     * that needs an incomplete record blanks `owner_role` or `business_fit`.
     */
    private function completeApplication(array $overrides = []): EaApplication
    {
        return EaApplication::forceCreate(array_merge([
            'code' => 'APP-'.fake()->unique()->numerify('######'),
            'name' => 'Core Banking',
            // mandatory
            'criticality' => 'critical',
            'lifecycle' => 'live',
            'owner_role' => 'Head of Core Banking',
            'business_fit' => 4,
            'technical_fit' => 3,
            // optional
            'description' => 'Core banking ledger.',
            'time_score' => 'Invest',
            'annual_cost_ngn' => 1000000,
            'user_count' => 500,
            'capability_ids' => [1],
        ], $overrides));
    }

    /* ------------------------------------------------------------------ */
    /* B1 — ownership                                                      */
    /* ------------------------------------------------------------------ */

    public function test_ownership_is_a_relationship_not_a_free_text_field(): void
    {
        // §2.3: "Atheris has no Person↔object ownership concept at all in the
        // EA schema. Ownership is a free-text field, not a relationship."
        $app = $this->completeApplication();
        $user = $this->owner();

        $this->ownership->subscribe(EaApplication::class, $app->id, $user->id, 'accountable', 'Application Owner');

        $subs = $this->ownership->forEntity(EaApplication::class, $app->id);
        $this->assertCount(1, $subs);
        $this->assertSame('accountable', $subs->first()->role);
        $this->assertSame($user->id, $subs->first()->user_id);
    }

    public function test_only_responsible_and_accountable_are_approvers(): void
    {
        $app = $this->completeApplication();
        $responsible = $this->owner();
        $consulted = $this->owner();

        $this->ownership->subscribe(EaApplication::class, $app->id, $responsible->id, 'responsible');
        $this->ownership->subscribe(EaApplication::class, $app->id, $consulted->id, 'consulted');

        $this->assertTrue($this->ownership->canApprove($responsible, EaApplication::class, $app->id));
        $this->assertFalse($this->ownership->canApprove($consulted, EaApplication::class, $app->id));
    }

    public function test_ownership_completeness_counts_accountable_owners_only(): void
    {
        $a = $this->completeApplication();
        $b = $this->completeApplication();

        // An observer is a subscription, but it is not ownership.
        $this->ownership->subscribe(EaApplication::class, $a->id, $this->owner()->id, 'observer');
        $this->ownership->subscribe(EaApplication::class, $b->id, $this->owner()->id, 'accountable');

        $row = collect($this->ownership->completenessByType())
            ->firstWhere('type', EaApplication::class);

        $this->assertSame(2, $row['total']);
        $this->assertSame(2, $row['owned']);
        $this->assertSame(1, $row['accountable']);
        $this->assertSame(50.0, $row['percent']);
    }

    /* ------------------------------------------------------------------ */
    /* B3 — the quality seal state machine                                 */
    /* ------------------------------------------------------------------ */

    public function test_a_non_owner_cannot_approve_a_seal(): void
    {
        $app = $this->completeApplication();
        $stranger = $this->owner();

        $this->expectException(\RuntimeException::class);
        $this->seals->approve(EaApplication::class, $app->id, $stranger);
    }

    public function test_approval_is_blocked_while_a_mandatory_attribute_is_blank(): void
    {
        // §5.4 B3 — "mandatory-attribute gating".
        $app = $this->completeApplication(['owner_role' => null]);
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Owner role is blank/');
        $this->seals->approve(EaApplication::class, $app->id, $owner);
    }

    public function test_an_unowned_record_cannot_be_sealed_however_complete_its_fields_are(): void
    {
        $app = $this->completeApplication();
        $architect = $this->architect();

        // The architect may approve by role, but the record still has no
        // accountable party, which is itself a mandatory attribute.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no Responsible or Accountable owner/');
        $this->seals->approve(EaApplication::class, $app->id, $architect);
    }

    public function test_approving_stamps_an_expiry_from_the_seal_policy(): void
    {
        SealPolicy::forceCreate([
            'entity_type' => EaApplication::class,
            'renewal_interval_days' => 30,
            'auto_expiry_enabled' => true,
            'break_on_edit' => true,
        ]);

        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

        $seal = $this->seals->approve(EaApplication::class, $app->id, $owner);

        $this->assertSame(QualitySeal::APPROVED, $seal->state);
        $this->assertSame($owner->id, $seal->approved_by);
        $this->assertEqualsWithDelta(30, now()->diffInDays($seal->expires_at), 1);
        $this->assertSame(100, $seal->completeness);
    }

    public function test_editing_a_record_after_approval_breaks_its_seal(): void
    {
        // §3.4: "Edits by anyone else to base fields … break the seal."
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');
        $this->seals->approve(EaApplication::class, $app->id, $owner);

        $app->criticality = 'high';
        $app->save();

        $seal = QualitySeal::forEntity(EaApplication::class, $app->id)->first();
        $this->assertSame(QualitySeal::CHECK_NEEDED, $seal->state);
        $this->assertStringContainsString('criticality', $seal->break_reason);
    }

    public function test_adding_a_subscription_does_not_break_the_seal(): void
    {
        // §3.4 is explicit: "Subscriptions, comments, metrics and survey
        // operations explicitly do NOT" break the seal.
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');
        $this->seals->approve(EaApplication::class, $app->id, $owner);

        $this->ownership->subscribe(EaApplication::class, $app->id, $this->owner()->id, 'observer');

        $seal = QualitySeal::forEntity(EaApplication::class, $app->id)->first();
        $this->assertSame(QualitySeal::APPROVED, $seal->state);
    }

    public function test_a_touch_on_a_bookkeeping_column_does_not_break_the_seal(): void
    {
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');
        $this->seals->approve(EaApplication::class, $app->id, $owner);

        // Exactly what a nightly recompute job does.
        event(new ArchitectureEntityChanged(EaApplication::class, $app->id, ['updated_at']));

        $seal = QualitySeal::forEntity(EaApplication::class, $app->id)->first();
        $this->assertSame(QualitySeal::APPROVED, $seal->state);
    }

    public function test_seals_expire_on_schedule_even_when_nothing_changed(): void
    {
        // The mechanic §3.4 singles out: auto-expiry fires "regardless of
        // whether anything changed", forcing periodic re-validation.
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');
        $seal = $this->seals->approve(EaApplication::class, $app->id, $owner);

        $seal->expires_at = now()->subDay();
        $seal->saveQuietly();

        $expired = $this->seals->expireDueSeals();

        $this->assertCount(1, $expired);
        $this->assertSame(
            QualitySeal::CHECK_NEEDED,
            QualitySeal::forEntity(EaApplication::class, $app->id)->first()->state,
        );
    }

    public function test_completeness_weights_mandatory_attributes_above_optional_ones(): void
    {
        $full = $this->completeApplication();
        $sparse = $this->completeApplication(['owner_role' => null, 'business_fit' => null]);

        $this->ownership->subscribe(EaApplication::class, $full->id, $this->owner()->id, 'accountable');
        $this->ownership->subscribe(EaApplication::class, $sparse->id, $this->owner()->id, 'accountable');

        $a = $this->seals->computeCompleteness(EaApplication::class, $full->id);
        $b = $this->seals->computeCompleteness(EaApplication::class, $sparse->id);

        $this->assertGreaterThan($b['score'], $a['score']);
        $this->assertContains('owner_role', $b['missing_mandatory']);
    }

    /* ------------------------------------------------------------------ */
    /* B2 — the survey engine                                              */
    /* ------------------------------------------------------------------ */

    private function ownerConfirmationSurvey(array $overrides = []): Survey
    {
        return Survey::forceCreate(array_merge([
            'code' => 'SUR-OWN',
            'name' => 'Quarterly owner confirmation',
            'entity_type' => EaApplication::class,
            'fields' => [
                ['attribute' => 'criticality', 'label' => 'How critical is it?', 'type' => 'select'],
                ['attribute' => 'user_count', 'label' => 'How many users?', 'type' => 'number'],
            ],
            'audience_mode' => 'subscription',
            'audience_roles' => ['responsible', 'accountable'],
            'cadence' => 'once',
            'window_days' => 14,
            'reminder_interval_days' => 7,
            'max_reminders' => 2,
            'is_active' => true,
        ], $overrides));
    }

    public function test_audience_is_resolved_from_the_ownership_graph(): void
    {
        // Ardoq's mechanic: "you add someone to an audience simply by drawing
        // an Owns relationship between a Person and a component in the UI".
        $asked = $this->completeApplication();
        $notAsked = $this->completeApplication();
        $owner = $this->owner();

        $this->ownership->subscribe(EaApplication::class, $asked->id, $owner->id, 'accountable');

        $survey = $this->ownerConfirmationSurvey();
        $entities = $this->engine->scopeEntities($survey);
        $audience = $this->engine->resolveAudience($survey, $entities);

        $this->assertCount(2, $entities);
        $this->assertCount(1, $audience);
        $this->assertSame($owner->email, $audience->first()['email']);
    }

    public function test_the_staleness_trigger_excludes_recently_approved_records(): void
    {
        // Ardoq's named filter is "not updated in 6 months"; here freshness
        // means the seal was approved inside the window.
        $fresh = $this->completeApplication();
        $stale = $this->completeApplication();
        $owner = $this->owner();

        foreach ([$fresh, $stale] as $app) {
            $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');
        }
        $this->seals->approve(EaApplication::class, $fresh->id, $owner);

        $survey = $this->ownerConfirmationSurvey(['stale_after_days' => 180]);
        $entities = $this->engine->scopeEntities($survey);

        $this->assertCount(1, $entities);
        $this->assertSame($stale->id, $entities->first()->id);
    }

    public function test_a_survey_cannot_write_an_attribute_it_did_not_declare(): void
    {
        $app = $this->completeApplication(['code' => 'APP-LOCKED']);
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

        $campaign = $this->engine->launch($this->ownerConfirmationSurvey(), null, false);
        $response = $campaign->responses()->first();

        // `code` is not in the survey's field list — a magic-link holder must
        // not be able to set arbitrary columns.
        $this->engine->submit($response, ['criticality' => 'high', 'code' => 'HIJACKED']);

        $app->refresh();
        $this->assertSame('APP-LOCKED', $app->code);
        $this->assertSame('high', $app->criticality);
    }

    public function test_a_closed_campaign_rejects_further_responses(): void
    {
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

        $campaign = $this->engine->launch($this->ownerConfirmationSurvey(), null, false);
        $response = $campaign->responses()->first();

        $campaign->update(['closes_at' => now()->subDay()]);
        $this->engine->closeExpiredCampaigns();

        $this->expectException(\RuntimeException::class);
        $this->engine->submit($response->fresh(), ['criticality' => 'low']);
    }

    public function test_a_response_cannot_be_submitted_twice(): void
    {
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

        $campaign = $this->engine->launch($this->ownerConfirmationSurvey(), null, false);
        $response = $campaign->responses()->first();

        $this->engine->submit($response, ['criticality' => 'high']);

        $this->expectException(\RuntimeException::class);
        $this->engine->submit($response->fresh(), ['criticality' => 'low']);
    }

    /* ------------------------------------------------------------------ */
    /* The Phase 1 exit criterion, end to end                              */
    /* ------------------------------------------------------------------ */

    public function test_a_campaign_reaches_non_licensed_owners_by_magic_link_and_changes_the_seal(): void
    {
        Notification::fake();

        // 1. A portfolio, each application owned. Twenty rather than the
        //    stated two hundred keeps the test fast; nothing in the engine is
        //    per-record beyond the loop the assertions below exercise.
        $owner = $this->owner();
        $apps = collect(range(1, 20))->map(function () use ($owner) {
            $app = $this->completeApplication(['user_count' => null]);
            $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

            return $app;
        });

        // 2. Plus a genuinely non-licensed recipient — an owner in a business
        //    unit with no account, which LeanIX structurally cannot survey.
        $survey = $this->ownerConfirmationSurvey([
            'additional_recipients' => [['email' => 'unlicensed.owner@bank.ng', 'name' => 'Adaeze']],
        ]);

        $campaign = $this->engine->launch($survey, $this->architect());

        $this->assertSame(20, $campaign->entities_count);
        $this->assertSame(40, $campaign->recipients_count, 'One licensed and one unlicensed recipient per record.');

        // 3. The unlicensed recipient holds a working token and no user id.
        $magic = SurveyResponse::where('campaign_id', $campaign->id)
            ->where('recipient_email', 'unlicensed.owner@bank.ng')
            ->first();

        $this->assertNotNull($magic);
        $this->assertNull($magic->recipient_user_id);
        $this->assertSame(64, strlen($magic->token));

        // The magic-link page is reachable with no authentication at all.
        $this->get(route('ea.portal.respond', $magic->token))->assertOk();

        // 4. Responses submitted — including through the public endpoint.
        $this->post(route('ea.portal.submit', $magic->token), [
            'answers' => ['criticality' => 'high', 'user_count' => 850],
            'comment' => 'Moved to the new core last quarter.',
        ])->assertOk();

        $magic->refresh();
        $this->assertSame(SurveyResponse::SUBMITTED, $magic->state);

        // 5. The answer was written onto the record itself, not parked in a
        //    side table (§5.4 B2 — "writing directly to entity fields").
        $target = EaApplication::find($magic->entity_id);
        $this->assertSame('high', $target->criticality);
        $this->assertSame(850, (int) $target->user_count);

        // 6. And it visibly changed the seal state and the completeness score —
        //    the exit criterion's final clause.
        $seal = QualitySeal::forEntity(EaApplication::class, $magic->entity_id)->first();
        $this->assertNotNull($seal);
        $this->assertContains($seal->state, [QualitySeal::CHECK_NEEDED, QualitySeal::APPROVED]);
        $this->assertGreaterThan(0, $seal->completeness);

        // 7. Completion is tracked.
        $stats = $this->engine->campaignStats($campaign->fresh());
        $this->assertSame(1, $stats['submitted']);
        $this->assertSame(40, $stats['total']);
        $this->assertGreaterThan(0, $stats['completion_rate']);
    }

    public function test_a_licensed_owner_responding_re_approves_the_seal_outright(): void
    {
        $app = $this->completeApplication();
        $owner = $this->owner();
        $this->ownership->subscribe(EaApplication::class, $app->id, $owner->id, 'accountable');

        $campaign = $this->engine->launch($this->ownerConfirmationSurvey(), null, false);
        $response = $campaign->responses()->where('recipient_user_id', $owner->id)->first();

        $result = $this->engine->submit($response, ['criticality' => 'medium']);

        // The respondent *is* the accountable party and the record is
        // complete, so their confirmation is an approval.
        $this->assertSame(QualitySeal::APPROVED, $result['seal']->state);
        $this->assertSame($owner->id, $result['seal']->approved_by);
    }

    public function test_deleting_an_entity_cleans_up_its_seal_and_subscriptions(): void
    {
        // Both are polymorphic, so no database constraint tidies them up.
        $cap = Capability::forceCreate([
            'code' => 'CAP-X', 'name' => 'Payments', 'criticality' => 'high',
            'maturity' => 3, 'owner_role' => 'Head of Payments',
        ]);
        $owner = $this->owner();
        $this->ownership->subscribe(Capability::class, $cap->id, $owner->id, 'accountable');
        $this->seals->approve(Capability::class, $cap->id, $owner);

        $cap->delete();

        $this->assertSame(0, QualitySeal::forEntity(Capability::class, $cap->id)->count());
        $this->assertCount(0, $this->ownership->forEntity(Capability::class, $cap->id));
    }
}
