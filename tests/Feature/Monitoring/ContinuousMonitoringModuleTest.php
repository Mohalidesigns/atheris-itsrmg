<?php

namespace Tests\Feature\Monitoring;

use App\Models\AccessReviewCampaign;
use App\Models\AccessReviewItem;
use App\Models\Asset;
use App\Models\CcmTenantTest;
use App\Models\CcmTest;
use App\Models\CcmTestRun;
use App\Models\Change\ChangeRequest;
use App\Models\ConfigDriftEvent;
use App\Models\Control;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\User;
use App\Services\Monitoring\DriftService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Continuous Monitoring (ATH-VAL-013): tenant isolation of CCM, drift and access reviews; CCM runs
 * measured from the bank's records (not random) with issues raised on failure; drift reconciled
 * with approved changes and accepted only by someone other than its owner; access reviews where
 * nobody certifies their own access and removals are confirmed by a second person.
 */
class ContinuousMonitoringModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin');
        $this->owner = $this->user('Organization Admin');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function ccm(string $code, ?Organization $org = null, array $attrs = []): CcmTenantTest
    {
        $test = CcmTest::firstOrCreate(['code' => $code], ['title' => $code, 'adapter_key' => 'self', 'default_severity' => 'high', 'cadence_cron' => '0 2 * * *']);

        return CcmTenantTest::withoutGlobalScopes()->create($attrs + ['organization_id' => ($org ?? $this->org)->id, 'ccm_test_id' => $test->id, 'status' => 'enabled']);
    }

    private function asset(array $attrs = [], ?Organization $org = null): Asset
    {
        return Asset::factory()->create($attrs + ['organization_id' => ($org ?? $this->org)->id, 'owner_id' => $this->owner->id,
            'status' => 'active', 'asset_type' => 'database', 'category' => 'Data']);
    }

    public function test_ccm_drift_and_access_reviews_are_isolated_per_bank(): void
    {
        $mine = $this->ccm('CCM-GOV-POL-021');
        $theirs = $this->ccm('CCM-GOV-POL-021', $this->other);
        $theirDrift = ConfigDriftEvent::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'drift_code' => 'DRF-0001', 'source' => 'manual',
            'title' => 'Their drift', 'severity' => 'high', 'detected_at' => now(), 'status' => 'open']);
        $theirCampaign = AccessReviewCampaign::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'campaign_code' => 'UAR-0001',
            'name' => 'Their review', 'system' => 'Finacle', 'due_at' => now()->addWeek()]);
        CcmTestRun::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'tenant_test_id' => $theirs->id, 'status' => 'fail', 'ran_at' => now()]);

        $this->actingAs($this->admin)->get(route('ccm.index'))->assertInertia(fn (Assert $p) => $p->has('tests', 1)->where('tests.0.id', $mine->id));
        $this->get(route('ccm.show', $theirs))->assertNotFound();
        $this->post(route('ccm.run', $theirs))->assertNotFound();
        $this->get(route('drift.show', $theirDrift))->assertNotFound();
        $this->get(route('access-reviews.show', $theirCampaign))->assertNotFound();
        $this->get(route('monitoring.drift'))->assertInertia(fn (Assert $p) => $p->where('events.total', 0));
        $this->get(route('monitoring.access-reviews'))->assertInertia(fn (Assert $p) => $p->has('campaigns', 0));
        $this->get(route('monitoring.dashboard'))->assertInertia(fn (Assert $p) => $p->has('recent', 0)->where('kpis.tests', 1));
    }

    public function test_a_ccm_run_measures_the_banks_records_and_raises_one_issue_on_failure(): void
    {
        $control = Control::factory()->create(['organization_id' => $this->org->id, 'owner_id' => $this->owner->id, 'effectiveness' => 'effective', 'last_tested' => null]);
        $tt = $this->ccm('CCM-GOV-POL-021', null, ['control_id' => $control->id]);
        $late = Policy::factory()->create(['organization_id' => $this->org->id, 'status' => 'active', 'review_date' => now()->subMonth()->toDateString()]);
        Policy::factory()->create(['organization_id' => $this->org->id, 'status' => 'active', 'review_date' => now()->addMonths(6)->toDateString()]);
        Policy::factory()->create(['organization_id' => $this->other->id, 'status' => 'active', 'review_date' => now()->subYear()->toDateString()]);

        $this->actingAs($this->user('Viewer'))->post(route('ccm.run', $tt))->assertForbidden();
        $this->actingAs($this->admin)->post(route('ccm.run', $tt))->assertRedirect();
        $this->post(route('ccm.run', $tt))->assertRedirect();

        $run = CcmTestRun::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(['fail', 'platform', 50.0, $this->org->id], [$run->status, $run->mode, $run->measured_value, (int) $run->organization_id]);
        $this->assertStringContainsString($late->policy_code, $run->findings[0]);
        $this->assertSame(now()->toDateString(), $control->fresh()->last_tested->toDateString());

        // One open issue per failing test, owned by the control owner; the console shows it.
        $issue = Issue::withoutGlobalScopes()->where('source_type', 'ccm_test')->sole();
        $this->assertSame([$tt->id, $this->owner->id, 'high'], [(int) $issue->source_id, (int) $issue->owner_id, $issue->severity]);
        $this->get(route('monitoring.controls', ['filter' => 'disagree']))->assertInertia(fn (Assert $p) => $p->has('controls', 1)->where('controls.0.signal', 'fail'));

        // Fixed at source: the next run passes and notes it on the issue, which stays for verification.
        $late->update(['review_date' => now()->addYear()->toDateString()]);
        $this->post(route('ccm.run', $tt))->assertRedirect();
        $this->assertSame('pass', $tt->fresh()->last_status);
        $this->assertTrue($issue->fresh()->isOpen());
        $this->assertTrue($issue->events()->where('description', 'like', '%passes again%')->exists());
    }

    public function test_ccm_settings_are_tenant_checked_and_thresholds_ordered(): void
    {
        $tt = $this->ccm('CCM-GOV-ATT-022');
        $theirControl = Control::factory()->create(['organization_id' => $this->other->id]);

        $this->actingAs($this->admin)->put(route('ccm.update', $tt), ['status' => 'enabled', 'control_id' => $theirControl->id])->assertSessionHasErrors('control_id');
        $this->put(route('ccm.update', $tt), ['status' => 'enabled', 'pass' => 80, 'warn' => 90])->assertSessionHasErrors('warn');
        $this->put(route('ccm.update', $tt), ['status' => 'paused', 'pass' => 90, 'warn' => 80])->assertRedirect();
        $this->assertSame(['paused', ['pass' => 90, 'warn' => 80]], [$tt->fresh()->status, $tt->fresh()->overrides]);
    }

    public function test_drift_is_reconciled_with_approved_changes_and_accepted_by_someone_else(): void
    {
        $asset = $this->asset();
        $other = $this->asset(['name' => 'Unchanged DB']);
        $change = ChangeRequest::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'change_code' => 'CHG-0001', 'title' => 'DB patch',
            'status' => 'closed', 'affected_asset_ids' => [$asset->id], 'scheduled_start_at' => now()->subHours(3), 'scheduled_end_at' => now()->addHour()]);
        $service = app(DriftService::class);

        $authorised = $service->record($this->org->id, ['asset' => $asset, 'source' => 'defender', 'title' => 'TLS 1.0 enabled', 'severity' => 'medium', 'detected_at' => now()], null);
        $unauthorised = $service->record($this->org->id, ['asset' => $other, 'source' => 'defender', 'title' => 'Encryption at rest disabled', 'severity' => 'critical', 'detected_at' => now()], null);
        $this->assertSame(['authorised', $change->id], [$authorised->status, (int) $authorised->change_request_id]);
        $this->assertSame('open', $unauthorised->status);
        $this->assertSame('config_drift', Issue::withoutGlobalScopes()->find($unauthorised->issue_id)->source_type);
        $this->assertNull($service->record($this->org->id, ['asset' => $other, 'source' => 'defender', 'title' => 'Encryption at rest disabled', 'severity' => 'critical', 'detected_at' => now()], null));

        $go = fn (User $u, array $data) => $this->actingAs($u)->post(route('drift.transition', $unauthorised), $data);
        $go($this->admin, ['action' => 'authorise', 'change_request_id' => $change->id])->assertSessionHasErrors('change_request_id');
        $go($this->owner, ['action' => 'accept', 'note' => 'Legacy host being retired.', 'accepted_until' => now()->addMonths(3)->toDateString()])->assertSessionHasErrors('drift');
        $go($this->admin, ['action' => 'accept', 'note' => 'Legacy host being retired.', 'accepted_until' => now()->addYears(2)->toDateString()])->assertSessionHasErrors('accepted_until');
        $go($this->admin, ['action' => 'accept', 'note' => 'Legacy host being retired.', 'accepted_until' => now()->addMonths(3)->toDateString()])->assertRedirect();
        $this->assertSame(['accepted', $this->admin->id], [$unauthorised->fresh()->status, (int) $unauthorised->fresh()->resolved_by]);

        // A scan reads only this bank's assets.
        $theirs = $this->asset(['name' => 'Their DB'], $this->other);
        $this->actingAs($this->admin)->post(route('drift.scan'), ['source' => 'defender'])->assertRedirect();
        $this->assertFalse(ConfigDriftEvent::withoutGlobalScopes()->where('asset_id', $theirs->id)->exists());
    }

    public function test_access_reviews_separate_reviewer_decider_and_confirmer(): void
    {
        $reviewer = $this->user('Compliance Officer');
        $this->actingAs($this->admin)->post(route('access-reviews.store'), ['name' => 'Q4 privileged', 'system' => 'Atheris', 'category' => 'privileged',
            'cadence' => 'quarterly', 'source' => 'atheris', 'reviewer_id' => $this->user('Viewer', $this->other)->id, 'due_at' => now()->addWeek()->toDateString()])
            ->assertSessionHasErrors('reviewer_id');
        $this->post(route('access-reviews.store'), ['name' => 'Q4 privileged', 'system' => 'Atheris', 'category' => 'privileged',
            'cadence' => 'quarterly', 'source' => 'atheris', 'reviewer_id' => $reviewer->id, 'due_at' => now()->addWeek()->toDateString()])->assertRedirect();

        $campaign = AccessReviewCampaign::withoutGlobalScopes()->sole();
        $this->assertSame(3, $campaign->items()->count()); // admin, owner and reviewer: one role each, this bank only
        $this->assertSame(2, $campaign->items()->where('privileged', true)->count());
        $own = $campaign->items()->where('user_id', $reviewer->id)->sole();
        $ownerItem = $campaign->items()->where('user_id', $this->owner->id)->sole();

        $this->actingAs($reviewer)->post(route('access-reviews.items.decide', $own), ['decision' => 'keep'])->assertSessionHasErrors('review');
        $this->post(route('access-reviews.items.decide', $ownerItem), ['decision' => 'revoke'])->assertSessionHasErrors('comment');
        $this->post(route('access-reviews.items.decide', $ownerItem), ['decision' => 'revoke', 'comment' => 'Moved to Treasury.'])->assertRedirect();
        $this->post(route('access-reviews.complete', $campaign))->assertSessionHasErrors('review');

        $this->post(route('access-reviews.items.confirm', $ownerItem))->assertSessionHasErrors('review');
        $this->actingAs($this->admin)->post(route('access-reviews.items.confirm', $ownerItem))->assertRedirect();
        $this->assertNotNull($ownerItem->fresh()->revocation_confirmed_at);

        $this->post(route('access-reviews.items.decide', $own), ['decision' => 'keep'])->assertRedirect();
        $campaign->items()->whereNull('decision')->get()->each(fn (AccessReviewItem $i) => $this->actingAs($reviewer)->post(route('access-reviews.items.decide', $i), ['decision' => 'keep']));
        $this->actingAs($this->admin)->post(route('access-reviews.complete', $campaign))->assertRedirect();
        $this->assertSame(['completed', $this->admin->id], [$campaign->fresh()->status, (int) $campaign->fresh()->completed_by]);
    }

    public function test_dashboards_read_the_monitoring_metrics(): void
    {
        $this->ccm('CCM-GOV-POL-021', null, ['last_status' => 'fail']);
        $this->ccm('CCM-GOV-ATT-022', null, ['last_status' => 'pass']);
        $this->ccm('CCM-RES-DR-038', $this->other, ['last_status' => 'fail']);
        AccessReviewCampaign::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'campaign_code' => 'UAR-0001', 'name' => 'Late',
            'system' => 'Finacle', 'status' => 'in_progress', 'due_at' => now()->subDay()]);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('monitoring.tests', 2)->where('monitoring.fail', 1)->where('monitoring.reviews_overdue', 1));
        $this->get(route('monitoring.access-reviews', ['status' => 'overdue']))->assertInertia(fn (Assert $p) => $p->has('campaigns', 1)->where('campaigns.0.overdue', true));
    }
}
