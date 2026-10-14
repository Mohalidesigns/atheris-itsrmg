<?php

namespace Tests\Feature\Continuity;

use App\Models\BcpPlan;
use App\Models\BcpTest;
use App\Models\BiaRecord;
use App\Models\DrExercise;
use App\Models\DrRunbook;
use App\Models\Ea\BusinessService;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Business Continuity (ATH-VAL-012): tenant isolation of plans, tests, BIA and DR; plan approval
 * by someone other than the owner; recording tests; BIA objectives applied to the business
 * service by a second person; DR exercises measured against the runbook's RTO; dashboard feed.
 */
class BusinessContinuityModuleTest extends TestCase
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

    private function plan(array $attrs = [], ?Organization $org = null): BcpPlan
    {
        return BcpPlan::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id, 'plan_code' => 'KHB-BCP-'.fake()->unique()->numerify('###'),
            'title' => 'Payments BCP', 'plan_type' => 'bcp', 'status' => 'draft', 'owner_id' => $this->owner->id,
            'scope' => 'NIBSS and Interswitch payment flows.', 'rto_hours' => 2, 'rpo_hours' => 1,
        ]);
    }

    private function service(array $attrs = [], ?Organization $org = null): BusinessService
    {
        return BusinessService::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id, 'code' => 'BS-'.fake()->unique()->numerify('###'),
            'name' => 'NIBSS NIP', 'criticality' => 'critical', 'status' => 'active', 'rto_minutes' => 15,
        ]);
    }

    private function runbook(?Organization $org = null): DrRunbook
    {
        return DrRunbook::withoutGlobalScopes()->create(['organization_id' => ($org ?? $this->org)->id, 'name' => 'NIBSS NIP Failover',
            'steps' => [['title' => 'Fail over']], 'estimated_duration_min' => 60, 'category' => 'payments']);
    }

    public function test_plans_tests_bia_and_dr_are_isolated_per_bank(): void
    {
        $mine = $this->plan();
        $theirs = $this->plan(['title' => 'Their plan'], $this->other);
        BcpTest::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'plan_id' => $theirs->id, 'title' => 'Their test', 'test_type' => 'tabletop', 'status' => 'completed', 'scheduled_date' => now()]);
        $theirRunbook = $this->runbook($this->other);
        DrExercise::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'runbook_id' => $theirRunbook->id, 'status' => 'completed']);
        $theirBia = BiaRecord::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'process_name' => 'Their process', 'criticality' => 'high']);

        $this->actingAs($this->admin)->get(route('bcp.plans'))->assertInertia(fn (Assert $p) => $p->where('plans.total', 1)->where('plans.data.0.id', $mine->id));
        $this->get(route('bcp.plans.show', $theirs))->assertNotFound();
        $this->get(route('bcp.tests'))->assertInertia(fn (Assert $p) => $p->has('tests', 0));
        $this->get(route('dr.runbooks'))->assertInertia(fn (Assert $p) => $p->has('runbooks', 0));
        $this->get(route('dr.exercises'))->assertInertia(fn (Assert $p) => $p->has('exercises', 0));
        $this->get(route('dr.runbook.show', $theirRunbook))->assertNotFound();
        $this->post(route('bcp.bia.apply', $theirBia))->assertNotFound();
    }

    public function test_a_plan_is_approved_by_someone_other_than_its_owner(): void
    {
        $plan = $this->plan();
        $theirService = $this->service([], $this->other);

        $this->actingAs($this->owner)->post(route('bcp.plans.approve', $plan))->assertSessionHasErrors('plan');
        $unscoped = $this->plan(['scope' => null]);
        $this->actingAs($this->admin)->post(route('bcp.plans.approve', $unscoped))->assertSessionHasErrors('plan');

        $this->post(route('bcp.plans.approve', $plan))->assertRedirect();
        $plan->refresh();
        $this->assertSame(['active', $this->admin->id], [$plan->status, (int) $plan->approved_by]);

        // A material change to an approved plan sends it back for approval.
        $payload = ['title' => $plan->title, 'plan_type' => 'bcp', 'scope' => $plan->scope, 'rto_hours' => 4, 'rpo_hours' => 1];
        $this->put(route('bcp.plans.update', $plan), $payload + ['service_ids' => [$theirService->id]])->assertSessionHasErrors('service_ids.0');
        $this->put(route('bcp.plans.update', $plan), $payload)->assertRedirect();
        $this->assertSame(['under_review', null], [$plan->fresh()->status, $plan->fresh()->approved_by]);
    }

    public function test_recording_a_test_sets_the_plans_last_tested_date(): void
    {
        $plan = $this->plan(['status' => 'active', 'last_tested' => now()->subMonths(14)]);
        $base = ['title' => 'Annual tabletop', 'test_type' => 'tabletop', 'completed_date' => now()->subDay()->toDateString(), 'results' => 'Walked through the NIP failover.'];

        $this->actingAs($this->admin)->get(route('bcp.plans.show', $plan))->assertInertia(fn (Assert $p) => $p->where('testOverdue', true));
        $this->post(route('bcp.plans.tests.store', $plan), $base + ['pass_fail' => 'fail'])->assertSessionHasErrors('findings');
        $this->post(route('bcp.plans.tests.store', $plan), ['pass_fail' => 'pass', 'completed_date' => now()->addDay()->toDateString()] + $base)->assertSessionHasErrors('completed_date');
        $this->post(route('bcp.plans.tests.store', $plan), $base + ['pass_fail' => 'partial', 'findings' => 'Call tree out of date.', 'rto_achieved_hours' => 3])->assertRedirect();

        $test = BcpTest::withoutGlobalScopes()->sole();
        $this->assertSame(['completed', 'partial', $this->admin->id], [$test->status, $test->pass_fail, (int) $test->conducted_by]);
        $this->assertStringContainsString('Recovered in 3h against an RTO of 2h', $test->results);
        $this->assertSame(now()->subDay()->toDateString(), $plan->fresh()->last_tested->toDateString());
        $this->get(route('bcp.plans.show', $plan))->assertInertia(fn (Assert $p) => $p->where('testOverdue', false));
    }

    public function test_an_approved_bia_sets_the_services_recovery_objectives(): void
    {
        $service = $this->service(['rto_minutes' => 15]);
        $theirService = $this->service([], $this->other);

        $this->actingAs($this->admin)->post(route('bcp.bia.store'), ['process_name' => 'Payments — NIP', 'criticality' => 'critical', 'business_service_id' => $theirService->id])
            ->assertSessionHasErrors('business_service_id');
        $this->post(route('bcp.bia.store'), ['process_name' => 'Payments — NIP', 'criticality' => 'critical', 'rto_hours' => 4, 'mtpd_hours' => 2])
            ->assertSessionHasErrors('mtpd_hours');
        $this->post(route('bcp.bia.store'), ['process_name' => 'Payments — NIP', 'criticality' => 'critical', 'business_service_id' => $service->id,
            'rto_hours' => 1, 'rpo_hours' => 0, 'mtpd_hours' => 2, 'owner_id' => $this->owner->id])->assertRedirect();
        $bia = BiaRecord::withoutGlobalScopes()->sole();

        $this->get(route('bcp.bia'))->assertInertia(fn (Assert $p) => $p->where('records.data.0.service_mismatch', true)->has('unassessed', 0));

        $this->actingAs($this->owner)->post(route('bcp.bia.apply', $bia))->assertSessionHasErrors('bia');
        $this->actingAs($this->admin)->post(route('bcp.bia.apply', $bia))->assertRedirect();
        $this->assertSame([60, 0], [(int) $service->fresh()->rto_minutes, (int) $service->fresh()->rpo_minutes]);
        $this->assertNotNull($bia->fresh()->applied_at);

        // Changing the objectives needs approving and applying again.
        $this->put(route('bcp.bia.update', $bia), ['process_name' => 'Payments — NIP', 'criticality' => 'critical', 'business_service_id' => $service->id, 'rto_hours' => 2, 'rpo_hours' => 0])->assertRedirect();
        $this->assertNull($bia->fresh()->applied_at);
    }

    public function test_dr_exercises_are_measured_against_the_runbooks_rto(): void
    {
        $runbook = $this->runbook();
        $base = ['started_at' => now()->subDay()->format('Y-m-d H:i'), 'outcome_notes' => 'Failed over to the DR switch.'];

        $this->actingAs($this->admin)->post(route('dr.exercises.store', $runbook), $base + ['rto_achieved_min' => 45])->assertRedirect();
        $this->post(route('dr.exercises.store', $runbook), $base + ['rto_achieved_min' => 80])->assertRedirect();
        $this->post(route('dr.exercises.store', $runbook), $base + ['rto_achieved_min' => 200])->assertRedirect();
        $this->post(route('dr.exercises.store', $runbook), ['rto_achieved_min' => 30])->assertSessionHasErrors(['started_at', 'outcome_notes']);

        $this->assertSame(['pass', 'partial', 'fail'], DrExercise::withoutGlobalScopes()->orderBy('id')->pluck('result')->all());
        $this->assertSame([60, $this->org->id], [DrExercise::withoutGlobalScopes()->first()->rto_target_min, (int) DrExercise::withoutGlobalScopes()->first()->organization_id]);
        $this->actingAs($this->user('Viewer'))->post(route('dr.exercises.store', $runbook), $base + ['rto_achieved_min' => 45])->assertForbidden();
    }

    public function test_dashboard_feeds_continuity_metrics(): void
    {
        $critical = $this->service();
        $this->service(['name' => 'Loan Origination', 'criticality' => 'medium']);
        $plan = $this->plan(['status' => 'active', 'last_tested' => now()->subMonths(13)]);
        $this->plan(['status' => 'active', 'last_tested' => now()->subMonth()]);
        DB::table('bcp_plan_service')->insert(['organization_id' => $this->org->id, 'plan_id' => $plan->id, 'business_service_id' => $critical->id]);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('continuity.active', 2)->where('continuity.test_overdue', 1)
            ->where('continuity.critical_services', 1)->where('continuity.critical_without_bia', 1)->where('continuity.critical_without_plan', 0));
    }
}
