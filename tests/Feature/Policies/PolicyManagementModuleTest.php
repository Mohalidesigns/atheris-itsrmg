<?php

namespace Tests\Feature\Policies;

use App\Models\Control;
use App\Models\Organization;
use App\Models\Policy;
use App\Models\PolicyAttestation;
use App\Models\PolicyException;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Policy Management (ATH-VAL-009): tenant isolation; the policy lifecycle with segregated
 * approval; publishing launching an attestation campaign; attestation by any member of staff;
 * revisions re-opening approval; policy→control mapping; exceptions with segregated decisions,
 * term limits and expiry; and the dashboard feed.
 */
class PolicyManagementModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $author;

    private User $approver;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->author = $this->user('Compliance Officer');
        $this->approver = $this->user('Organization Admin');
        $this->staff = $this->user('Security Analyst');
    }

    private function user(string $role, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id, 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function policy(array $attrs = [], ?Organization $org = null): Policy
    {
        $org ??= $this->org;

        return Policy::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $org->id,
            'policy_code' => 'KHB-POL-'.fake()->unique()->numerify('###'),
            'title' => 'Policy '.fake()->unique()->numerify('###'),
            'content' => 'All staff must lock their screens.',
            'status' => 'draft',
            'version_number' => '1.0',
            'owner_id' => $this->author->id,
            'is_mandatory' => true,
        ]);
    }

    private function control(?Organization $org = null): Control
    {
        return Control::withoutGlobalScopes()->create([
            'organization_id' => ($org ?? $this->org)->id, 'control_code' => 'CTL-'.fake()->unique()->numerify('###'),
            'title' => 'Screen lock enforced by GPO', 'domain' => 'Identity & Access', 'status' => 'active', 'effectiveness' => 'effective',
        ]);
    }

    public function test_policies_attestations_and_exceptions_are_isolated_per_bank(): void
    {
        $mine = $this->policy(['title' => 'Kano policy']);
        $theirs = $this->policy(['title' => 'Lagos policy', 'status' => 'active'], $this->other);
        $exception = PolicyException::withoutGlobalScopes()->create(['organization_id' => $this->other->id, 'exception_code' => 'EXC-0001',
            'policy_id' => $theirs->id, 'title' => 'x', 'justification' => 'y', 'expires_on' => now()->addMonth()]);

        $this->actingAs($this->approver)->get(route('policies.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('policies.total', 1)->where('policies.data.0.id', $mine->id));
        $this->get(route('policies.show', $theirs))->assertNotFound();
        $this->post(route('policies.attest', $theirs))->assertNotFound();
        $this->post(route('policy-exceptions.decide', $exception), ['decision' => 'approve'])->assertNotFound();
        $this->get(route('policy-exceptions.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('exceptions.total', 0));
    }

    public function test_store_starts_a_draft_in_the_bank_series_with_tenant_checked_pickers(): void
    {
        $this->policy(['policy_code' => 'KHB-POL-025']);
        $outsider = User::factory()->create(['organization_id' => $this->other->id]);
        $theirControl = $this->control($this->other);

        $payload = ['title' => 'Clean Desk Policy', 'content' => 'Clear desks at end of day.', 'category' => 'information_security', 'is_mandatory' => true];
        $this->actingAs($this->author)->post(route('policies.store'), $payload + ['owner_id' => $outsider->id])->assertSessionHasErrors('owner_id');
        $this->post(route('policies.store'), $payload + ['control_ids' => [$theirControl->id]])->assertSessionHasErrors('control_ids.0');
        $this->post(route('policies.store'), ['category' => 'security'] + $payload)->assertSessionHasErrors('category');

        $this->post(route('policies.store'), $payload + ['status' => 'active'])->assertRedirect();
        $policy = Policy::withoutGlobalScopes()->where('title', 'Clean Desk Policy')->sole();
        $this->assertSame(['KHB-POL-026', 'draft', '1.0'], [$policy->policy_code, $policy->status, $policy->version_number]);

        $this->get(route('policies.index', ['sort' => 'title; drop table policies', 'direction' => 'x']))->assertOk();
        $this->actingAs($this->staff)->post(route('policies.store'), $payload)->assertForbidden();
    }

    public function test_lifecycle_segregates_approval_and_publishing_launches_a_campaign(): void
    {
        $policy = $this->policy(['owner_id' => $this->approver->id, 'content' => null]);
        $retiree = User::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);

        // A draft needs its text before it can be submitted.
        $this->actingAs($this->author)->post(route('policies.transition', $policy), ['action' => 'submit'])->assertSessionHasErrors('action');
        $policy->update(['content' => 'Text.', 'owner_id' => $this->author->id]);
        $this->post(route('policies.transition', $policy), ['action' => 'publish'])->assertSessionHasErrors('action');
        $this->post(route('policies.transition', $policy), ['action' => 'submit'])->assertRedirect();
        $this->assertSame('in_review', $policy->fresh()->status);

        // The submitter (also the owner here) cannot approve.
        $this->post(route('policies.transition', $policy), ['action' => 'approve'])->assertSessionHasErrors('action');
        $this->actingAs($this->approver)->post(route('policies.transition', $policy), ['action' => 'return'])->assertSessionHasErrors('note');
        $this->post(route('policies.transition', $policy), ['action' => 'approve'])->assertRedirect();
        $this->assertSame(['approved', $this->approver->id], [$policy->fresh()->status, (int) $policy->fresh()->approved_by]);

        $this->post(route('policies.transition', $policy), ['action' => 'publish'])->assertRedirect();
        $policy->refresh();
        $this->assertSame('active', $policy->status);
        $this->assertNotNull($policy->published_at);
        $this->assertTrue($policy->review_date->gt(today()));

        $requests = PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)->get();
        $this->assertCount(3, $requests, 'Every active member of staff, and only them, is asked to attest.');
        $this->assertFalse($requests->contains('user_id', $retiree->id));
        $this->assertTrue($requests->every(fn ($a) => $a->status === 'pending' && $a->version_number === '1.0' && $a->due_date->isSameDay(today()->addDays(30))));

        // Staff without any policy permission are blocked from the workflow.
        $this->actingAs($this->staff)->post(route('policies.transition', $policy), ['action' => 'retire', 'note' => 'x'])->assertForbidden();
    }

    public function test_any_member_of_staff_attests_from_their_inbox(): void
    {
        $policy = $this->policy(['status' => 'active']);
        $draft = $this->policy();
        PolicyAttestation::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'policy_id' => $policy->id, 'version_number' => '1.0',
            'user_id' => $this->staff->id, 'status' => 'pending', 'due_date' => today()->subDay()]);

        $this->actingAs($this->staff)->get(route('my-attestations.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('pending', 1)->where('pending.0.policy.id', $policy->id));
        $this->get(route('policies.index'))->assertForbidden();

        $this->post(route('policies.attest', $draft))->assertForbidden();
        $this->post(route('policies.attest', $policy))->assertRedirect();
        $attestation = PolicyAttestation::withoutGlobalScopes()->where('user_id', $this->staff->id)->sole();
        $this->assertSame(['completed', '1.0'], [$attestation->status, $attestation->version_number]);
        $this->assertNotNull($attestation->acknowledged_at);

        // A viewer with policy access can attest a policy in force; nobody can attest a draft.
        $this->actingAs($this->approver)->post(route('policies.attest', $draft))->assertSessionHasErrors('policy');
    }

    public function test_revising_an_active_policy_reopens_approval_and_campaigns_the_new_version(): void
    {
        $policy = $this->policy(['status' => 'active', 'version_number' => '2.0', 'approved_by' => $this->approver->id]);
        $control = $this->control();

        $this->actingAs($this->author)->put(route('policies.update', $policy), [
            'title' => $policy->title, 'content' => 'All staff must lock screens and use MFA.', 'is_mandatory' => true,
            'change_summary' => 'Added MFA', 'control_ids' => [$control->id],
        ])->assertRedirect();

        $policy->refresh();
        $this->assertSame(['in_review', '2.1', $this->author->id], [$policy->status, $policy->version_number, (int) $policy->submitted_by]);
        $this->assertNull($policy->approved_by);
        $this->assertSame([$control->id], $policy->controls()->pluck('controls.id')->all());
        $this->assertSame('Added MFA', $policy->versions()->latest('id')->value('change_summary'));

        $this->actingAs($this->approver)->post(route('policies.transition', $policy), ['action' => 'approve']);
        $this->post(route('policies.transition', $policy), ['action' => 'publish']);
        $this->assertSame(3, PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)->where('version_number', '2.1')->count());

        $this->get(route('control-standards.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('policies.0.coverage', 100)->where('totals.unmapped', 0));
    }

    public function test_exceptions_need_a_different_approver_and_respect_term_limits(): void
    {
        $policy = $this->policy(['status' => 'active']);
        $draft = $this->policy();
        $base = ['policy_id' => $policy->id, 'title' => 'Shared service account', 'justification' => 'Vendor batch job needs a shared account until the next release.',
            'risk_rating' => 'medium', 'expires_on' => today()->addMonths(4)->toDateString()];

        $this->actingAs($this->approver)->post(route('policy-exceptions.store'), ['policy_id' => $draft->id] + $base)->assertSessionHasErrors('policy_id');
        $this->post(route('policy-exceptions.store'), ['risk_rating' => 'critical', 'compensating_controls' => 'Logged'] + $base)->assertSessionHasErrors('expires_on');
        $this->post(route('policy-exceptions.store'), ['risk_rating' => 'high'] + $base)->assertSessionHasErrors('compensating_controls');
        $this->post(route('policy-exceptions.store'), $base)->assertRedirect();

        $exception = PolicyException::withoutGlobalScopes()->sole();
        $this->assertSame(['requested', $this->approver->id], [$exception->status, (int) $exception->requested_by]);
        $this->assertSame('KHB-EXC-0001', $exception->exception_code);

        $this->post(route('policy-exceptions.decide', $exception), ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $other = $this->user('Organization Admin');
        $this->actingAs($other)->post(route('policy-exceptions.decide', $exception), ['decision' => 'reject'])->assertSessionHasErrors('notes');
        $this->post(route('policy-exceptions.decide', $exception), ['decision' => 'approve', 'expires_on' => today()->addMonths(2)->toDateString()])->assertRedirect();
        $exception->refresh();
        $this->assertSame(['approved', $other->id], [$exception->status, (int) $exception->decided_by]);
        $this->assertTrue($exception->expires_on->isSameDay(today()->addMonths(2)));

        // Expiry is applied by the daily sweep.
        $exception->update(['expires_on' => today()->subDay()]);
        Artisan::call('policies:sweep');
        $this->assertSame('expired', $exception->fresh()->status);

        $this->actingAs($this->staff)->post(route('policy-exceptions.store'), $base)->assertForbidden();
    }

    public function test_retiring_a_policy_waives_its_open_attestations(): void
    {
        $policy = $this->policy(['status' => 'active']);
        PolicyAttestation::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'policy_id' => $policy->id, 'user_id' => $this->staff->id, 'status' => 'pending']);

        $this->actingAs($this->approver)->post(route('policies.transition', $policy), ['action' => 'retire'])->assertSessionHasErrors('note');
        $this->post(route('policies.transition', $policy), ['action' => 'retire', 'note' => 'Merged into the Information Security Policy'])->assertRedirect();

        $this->assertSame('retired', $policy->fresh()->status);
        $this->assertSame('waived', PolicyAttestation::withoutGlobalScopes()->where('policy_id', $policy->id)->value('status'));
        $this->get(route('policies.edit', $policy))->assertStatus(422);
        $this->delete(route('policies.destroy', $policy))->assertSessionHas('error');
    }

    public function test_dashboard_and_register_share_policy_metrics(): void
    {
        $active = $this->policy(['status' => 'active', 'review_date' => today()->subDay()]);
        $this->policy(['status' => 'in_review']);
        $this->policy(['status' => 'active'], $this->other);
        PolicyAttestation::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'policy_id' => $active->id, 'user_id' => $this->staff->id, 'status' => 'completed']);
        PolicyAttestation::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'policy_id' => $active->id, 'user_id' => $this->author->id, 'status' => 'pending', 'due_date' => today()->subDay()]);

        $this->actingAs($this->approver)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('policyPulse.active', 1)->where('policyPulse.review_overdue', 1)
                ->where('policyPulse.awaiting_approval', 1)->where('policyPulse.attestation_rate', 50)
                ->where('policyPulse.attestations_overdue', 1)->where('policyPulse.unmapped_active', 1));
        $this->get(route('policy-attestations.index', ['status' => 'overdue']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('attestations.total', 1)->where('stats.rate', 50));
    }
}
