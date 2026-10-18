<?php

namespace Tests\Feature\Workflows;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Approvals\ApprovalWorkflowStage;
use App\Models\BcpPlan;
use App\Models\ConfigDriftEvent;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\User;
use App\Modules\CBNCSAT\Models\CsatApprovalStage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Workflow Studio (ATH-VAL-016): approval workflows configured per bank and process (initiators,
 * ordered stages, conditions) and enforced at runtime by the modules; the marketplace and pricing
 * removed from the product.
 */
class WorkflowStudioModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $compliance;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin', ['job_title' => 'CISO']);
        $this->compliance = $this->user('Compliance Officer', ['job_title' => 'Head of Compliance']);
        $this->author = $this->user('Compliance Officer', ['job_title' => 'Policy Author']);
    }

    private function user(string $role, array $attrs = [], ?Organization $org = null): User
    {
        $user = User::factory()->create($attrs + ['organization_id' => ($org ?? $this->org)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function configure(string $process, array $stages, array $initiators = []): void
    {
        $this->actingAs($this->admin)->put(route('workflows.process.update', $process), ['initiator_roles' => $initiators, 'is_active' => true])->assertRedirect();
        foreach ($stages as $stage) {
            $this->post(route('workflows.stages.store', $process), $stage + ['min_approvals' => 1, 'sla_hours' => 48])->assertRedirect();
        }
    }

    private function policy(array $attrs = []): Policy
    {
        return Policy::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $this->org->id, 'policy_code' => 'KHB-POL-'.fake()->unique()->numerify('###'), 'title' => 'Acceptable Use',
            'content' => 'All staff must lock their screens.', 'status' => 'draft', 'version_number' => '1.0',
            'owner_id' => $this->author->id, 'is_mandatory' => false,
        ]);
    }

    public function test_the_marketplace_and_pricing_are_gone(): void
    {
        foreach (['pricing.index', 'marketplace.index', 'marketplace.installs', 'marketplace.install', 'workflows.marketplace'] as $name) {
            $this->assertFalse(Route::has($name), "{$name} should no longer exist");
        }
        foreach (['content_marketplace_items', 'content_installs', 'pricing_tiers', 'workflows', 'workflow_instances'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped");
        }
        $this->actingAs($this->admin)->get('/marketplace')->assertNotFound();
        $this->get('/pricing')->assertNotFound();
    }

    public function test_the_studio_lists_every_process_and_edits_stages_within_the_bank(): void
    {
        $this->actingAs($this->admin)->get(route('workflows.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Workflows/Index')->where('summary.processes', 20)->has('processes.Policy Management', 2));

        $theirUser = $this->user('Organization Admin', [], $this->other);
        $this->post(route('workflows.stages.store', 'policy.approval'), ['name' => 'X', 'approver_type' => 'user', 'approver_ref' => (string) $theirUser->id, 'min_approvals' => 1, 'sla_hours' => 24])
            ->assertSessionHasErrors('approver_ref');
        $this->post(route('workflows.stages.store', 'policy.approval'), ['name' => 'X', 'approver_type' => 'role', 'approver_ref' => 'Not a role', 'min_approvals' => 1, 'sla_hours' => 24])
            ->assertSessionHasErrors('approver_ref');
        $this->post(route('workflows.stages.store', 'drift.acceptance'), ['name' => 'X', 'approver_type' => 'role', 'approver_ref' => 'Security Analyst', 'min_approvals' => 1, 'sla_hours' => 24,
            'condition_field' => 'severity', 'condition_values' => []])->assertSessionHasErrors('condition_values');

        $this->configure('policy.approval', [
            ['name' => 'Compliance review', 'approver_type' => 'role', 'approver_ref' => 'Compliance Officer'],
            ['name' => 'CISO approval', 'approver_type' => 'job_title', 'approver_ref' => 'CISO'],
        ]);
        $stages = ApprovalWorkflowStage::withoutGlobalScopes()->orderBy('sequence')->get();
        $this->assertSame(['Compliance review', 'CISO approval'], $stages->pluck('name')->all());
        $this->post(route('workflows.stages.move', $stages[1]), ['direction' => 'up']);
        $this->assertSame(['CISO approval', 'Compliance review'], ApprovalWorkflowStage::withoutGlobalScopes()->orderBy('sequence')->pluck('name')->all());
        $this->delete(route('workflows.stages.destroy', $stages[1]))->assertRedirect();
        $this->assertSame(1, ApprovalWorkflowStage::withoutGlobalScopes()->count());

        // Viewers read the configuration; only Workflow Studio editors change it; other banks cannot reach it.
        $this->actingAs($this->user('Viewer'))->get(route('workflows.process', 'policy.approval'))->assertOk();
        $this->post(route('workflows.stages.store', 'policy.approval'), ['name' => 'Y', 'approver_type' => 'owner', 'min_approvals' => 1, 'sla_hours' => 24])->assertForbidden();
        $this->actingAs($theirUser)->delete(route('workflows.stages.destroy', $stages[0]))->assertNotFound();
        $this->get(route('workflows.process', 'not.a.process'))->assertNotFound();
    }

    public function test_a_policy_passes_every_configured_stage_before_it_is_approved(): void
    {
        $this->configure('policy.approval', [
            ['name' => 'Compliance review', 'approver_type' => 'job_title', 'approver_ref' => 'Head of Compliance'],
            ['name' => 'CISO approval', 'approver_type' => 'job_title', 'approver_ref' => 'CISO'],
        ], ['Compliance Officer']);
        $policy = $this->policy();

        // Only the configured initiator roles may start it.
        $this->actingAs($this->user('Organization Admin'))->post(route('policies.transition', $policy), ['action' => 'submit'])->assertSessionHasErrors('approval');
        $this->actingAs($this->author)->post(route('policies.transition', $policy), ['action' => 'submit'])->assertRedirect();
        $request = ApprovalRequest::withoutGlobalScopes()->sole();
        $this->assertSame(['pending', $this->author->id], [$request->status, (int) $request->initiated_by]);

        // Out of order: the CISO cannot approve before the compliance review.
        $this->actingAs($this->admin)->post(route('policies.transition', $policy), ['action' => 'approve'])->assertSessionHasErrors('approval');
        $this->actingAs($this->compliance)->post(route('policies.transition', $policy), ['action' => 'approve', 'note' => 'Wording agreed.'])->assertRedirect();
        $this->assertSame('in_review', $policy->fresh()->status);

        $this->actingAs($this->admin)->get(route('workflows.inbox', ['mine' => 1]))->assertInertia(fn (Assert $p) => $p
            ->has('items', 1)->where('items.0.step', 'CISO approval')->where('items.0.progress', '1/2'));
        $this->getJson(route('workflows.trail', ['process' => 'policy.approval', 'id' => $policy->id]))->assertOk()
            ->assertJsonPath('request.steps.0.status', 'approved')->assertJsonPath('request.steps.1.can_decide', true);

        $this->post(route('policies.transition', $policy), ['action' => 'approve'])->assertRedirect();
        $this->assertSame(['approved', $this->admin->id], [$policy->fresh()->status, (int) $policy->fresh()->approved_by]);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_returning_a_policy_rejects_the_open_approval(): void
    {
        $this->configure('policy.approval', [['name' => 'Compliance review', 'approver_type' => 'role', 'approver_ref' => 'Compliance Officer']]);
        $policy = $this->policy();
        $this->actingAs($this->author)->post(route('policies.transition', $policy), ['action' => 'submit']);
        // The initiator cannot decide their own request, even holding the stage's role.
        $this->post(route('policies.transition', $policy), ['action' => 'return', 'note' => 'Self-return'])->assertSessionHasErrors('approval');
        $this->actingAs($this->compliance)->post(route('policies.transition', $policy), ['action' => 'return', 'note' => 'Add the NDPA clauses.'])->assertRedirect();
        $this->assertSame(['draft', 'rejected'], [$policy->fresh()->status, ApprovalRequest::withoutGlobalScopes()->sole()->status]);
    }

    public function test_conditional_stages_and_the_module_default(): void
    {
        $this->configure('drift.acceptance', [
            ['name' => 'Security review', 'approver_type' => 'role', 'approver_ref' => 'Compliance Officer'],
            ['name' => 'CISO acceptance', 'approver_type' => 'job_title', 'approver_ref' => 'CISO', 'condition_field' => 'severity', 'condition_values' => ['critical']],
        ]);
        $drift = fn (string $severity) => ConfigDriftEvent::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'drift_code' => 'DRF-'.fake()->unique()->numerify('###'),
            'source' => 'manual', 'title' => 'TLS 1.0 enabled', 'severity' => $severity, 'detected_at' => now(), 'status' => 'open', 'owner_id' => $this->author->id]);
        $accept = ['action' => 'accept', 'note' => 'Legacy host being retired.', 'accepted_until' => now()->addMonths(2)->toDateString()];

        $medium = $drift('medium');
        $this->actingAs($this->compliance)->post(route('drift.transition', $medium), $accept)->assertRedirect();
        $this->assertSame('accepted', $medium->fresh()->status); // the CISO stage does not apply to medium drift

        $critical = $drift('critical');
        $this->post(route('drift.transition', $critical), $accept)->assertRedirect();
        $this->assertSame('open', $critical->fresh()->status);
        $this->actingAs($this->admin)->post(route('drift.transition', $critical), $accept)->assertRedirect();
        $this->assertSame('accepted', $critical->fresh()->status);

        // No stages configured for BCP plans: the module's single approval applies.
        $plan = BcpPlan::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'plan_code' => 'BCP-001', 'title' => 'Payments BCP', 'plan_type' => 'bcp',
            'status' => 'draft', 'owner_id' => $this->author->id, 'scope' => 'NIP', 'rto_hours' => 2]);
        $this->post(route('bcp.plans.approve', $plan))->assertRedirect();
        $this->assertSame('active', $plan->fresh()->status);
        $this->assertSame(2, ApprovalRequest::withoutGlobalScopes()->count());
    }

    public function test_change_and_csat_stages_are_configured_from_the_studio(): void
    {
        $this->actingAs($this->admin)->get(route('workflows.process', 'change.approval'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('process.engine', 'change')->has('changeStages', 6)->has('matrix.normal.high'));

        $this->put(route('workflows.csat.update'), ['stages' => [['name' => 'Preparer submission', 'permission' => 'edit csat'], ['name' => 'CISO', 'permission' => 'nonexistent perm']]])
            ->assertSessionHasErrors('stages.1.permission');
        $this->put(route('workflows.csat.update'), ['stages' => [['name' => 'Preparer submission', 'permission' => 'edit csat'],
            ['name' => 'CISO review', 'permission' => 'approve csat'], ['name' => 'MD/CEO sign-off', 'permission' => 'approve csat']]])->assertRedirect();
        $this->assertSame(['Preparer submission', 'CISO review', 'MD/CEO sign-off'], CsatApprovalStage::where('organization_id', $this->org->id)->orderBy('stage_number')->pluck('stage_name')->all());
        $this->assertFalse(ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->other->id)->exists());
    }
}
