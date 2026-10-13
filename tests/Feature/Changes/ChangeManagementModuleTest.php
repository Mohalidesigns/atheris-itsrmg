<?php

namespace Tests\Feature\Changes;

use App\Models\Asset;
use App\Models\Change\ChangeFreezeWindow;
use App\Models\Change\ChangeRequest;
use App\Models\Change\ChangeStandardTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Change\ChangeApprovalOverdueNotification;
use App\Notifications\Change\ChangeApprovalRequestedNotification;
use App\Notifications\Change\ChangeDecisionNotification;
use App\Services\Change\ChangeApprovalRouter;
use App\Services\Change\ChangeConflictDetector;
use App\Services\Change\ChangeMetrics;
use App\Services\Change\ChangePolicySeeder;
use App\Services\Change\ChangeRiskScorer;
use App\Services\Change\ChangeWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Change Management (ATH-CHG-001 §11): tenant isolation of the register, boards, policy,
 * templates and freeze windows; risk scoring bands and uplifts; the risk-based routing matrix;
 * sequential approvals with segregation of duties; CAB quorum and chair override; freeze windows;
 * conflict detection; retrospective emergency starts; implementation outcomes and the PIR;
 * standard-template eligibility; the My Approvals inbox and the overdue sweep; the CSV export;
 * and the dashboard / calendar feeds.
 */
class ChangeManagementModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $ciso;

    private User $headIt;

    private User $changeManager;

    private User $requester;

    private User $owner;

    private User $implementer;

    private User $cab1;

    private User $cab2;

    private User $cab3;

    private ChangeWorkflow $workflow;

    private ChangeApprovalRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();

        $this->admin = $this->user('Organization Admin', ['job_title' => 'Platform Admin']);
        $this->ciso = $this->user('Organization Admin', ['job_title' => 'CISO']);
        $this->headIt = $this->user('Viewer', ['job_title' => 'Head of IT Operations']);
        $this->changeManager = $this->user('Change Manager', ['job_title' => 'IT Change Manager']);
        $this->requester = $this->user('Security Analyst', ['job_title' => 'SOC Analyst']);
        $this->owner = $this->user('Application Owner', ['job_title' => 'Finacle Application Owner']);
        $this->implementer = $this->user('Security Analyst', ['job_title' => 'Systems Engineer']);
        $this->cab1 = $this->user('Risk Manager', ['job_title' => 'Risk Analyst']);
        $this->cab2 = $this->user('Compliance Officer', ['job_title' => 'Compliance Manager']);
        $this->cab3 = $this->user('Viewer', ['job_title' => 'Network Engineer']);

        ChangePolicySeeder::ensureDefaults($this->org->id);
        [$cab, $ecab] = ChangePolicySeeder::ensureBoards($this->org->id);
        $cab->members()->create(['user_id' => $this->ciso->id, 'role' => 'chair', 'represents' => 'Information Security']);
        $cab->members()->create(['user_id' => $this->cab1->id, 'role' => 'member', 'represents' => 'Risk']);
        $cab->members()->create(['user_id' => $this->cab2->id, 'role' => 'member', 'represents' => 'Compliance']);
        $cab->members()->create(['user_id' => $this->cab3->id, 'role' => 'member', 'represents' => 'IT Operations']);
        $ecab->members()->create(['user_id' => $this->ciso->id, 'role' => 'chair', 'represents' => 'Information Security']);
        $ecab->members()->create(['user_id' => $this->headIt->id, 'role' => 'member', 'represents' => 'IT Operations']);
        $ecab->members()->create(['user_id' => $this->cab1->id, 'role' => 'member', 'represents' => 'Risk']);

        $this->workflow = app(ChangeWorkflow::class);
        $this->router = app(ChangeApprovalRouter::class);
    }

    /* ================================ helpers ================================ */

    private function user(string $role, array $attrs = [], ?Organization $org = null): User
    {
        $user = User::factory()->create($attrs + ['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    /** A draft normal change raised by the requester (medium risk unless overridden). */
    private function draft(array $attrs = [], ?User $requester = null): ChangeRequest
    {
        $requester ??= $this->requester;

        return $this->workflow->create($attrs + [
            'organization_id' => $requester->organization_id,
            'title' => 'Upgrade internet banking TLS configuration',
            'description' => 'Rotate cipher suites and disable TLS 1.1.',
            'change_type' => 'normal', 'category' => 'application', 'priority' => 'medium',
            'impact' => 3, 'likelihood' => 3,
            'impact_assessment' => 'Brief session resets during restart.',
            'implementation_plan' => 'Apply the new configuration and restart the web tier.',
            'backout_plan' => 'Restore the previous configuration from backup.',
            'change_owner_id' => $requester->organization_id === $this->org->id ? $this->owner->id : null,
            'implementer_id' => $requester->organization_id === $this->org->id ? $this->implementer->id : null,
        ], $requester);
    }

    /** A change submitted into approval. */
    private function submitted(array $attrs = []): ChangeRequest
    {
        $change = $this->draft($attrs);
        $this->workflow->transition($change, 'submit', $this->requester);

        return $change->fresh();
    }

    /** An approved low-risk change (only the Change Manager stage applies). */
    private function approvedLow(array $attrs = []): ChangeRequest
    {
        $change = $this->submitted($attrs + ['impact' => 1, 'likelihood' => 2]);
        $this->workflow->decide($change, $this->changeManager, 'approve', '');

        return $change->fresh();
    }

    /** A normal high change walked through to its CAB stage. */
    private function atCab(array $attrs = []): ChangeRequest
    {
        $change = $this->submitted($attrs + ['impact' => 4, 'likelihood' => 3, 'test_plan' => 'UAT signed off.']);
        $this->workflow->decide($change, $this->headIt, 'approve', '');
        $this->workflow->decide($change->fresh(), $this->ciso, 'approve', '');
        $this->workflow->decide($change->fresh(), $this->owner, 'approve', '');
        $change = $change->fresh();
        $this->assertSame('cab', $change->currentApproval()->stage_key);

        return $change;
    }

    private function template(array $attrs = []): ChangeStandardTemplate
    {
        return ChangeStandardTemplate::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $this->org->id, 'name' => 'Routine OS patching', 'category' => 'infrastructure',
            'implementation_plan' => 'Apply vendor patches.', 'backout_plan' => 'Roll back to snapshot.', 'risk_level' => 'low', 'is_active' => true,
        ]);
    }

    private function freeze(array $attrs = []): ChangeFreezeWindow
    {
        return ChangeFreezeWindow::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $this->org->id, 'name' => 'Year-end close',
            'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(14), 'scope' => 'all', 'allow_emergency' => true,
        ]);
    }

    private function stageKeys(ChangeRequest $change): array
    {
        return $change->fresh()->approvals()->orderBy('sequence')->orderBy('id')->pluck('stage_key')->all();
    }

    /* ============================= 1. tenancy ============================= */

    public function test_register_boards_policy_templates_and_freeze_windows_only_show_the_banks_own_records(): void
    {
        $ours = $this->submitted();
        $foreignAdmin = $this->user('Organization Admin', ['job_title' => 'CISO'], $this->other);
        ChangePolicySeeder::ensureDefaults($this->other->id);
        $theirs = $this->draft(['title' => 'Their change'], $foreignAdmin);
        $this->template();
        $this->template(['organization_id' => $this->other->id, 'name' => 'Their template']);
        $this->freeze();
        $this->freeze(['organization_id' => $this->other->id, 'name' => 'Their freeze']);

        $this->actingAs($this->admin)->get(route('changes.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/Index')->has('changes', 1)->where('changes.0.change_code', $ours->change_code));
        $this->actingAs($this->admin)->get(route('changes.show', $theirs))->assertNotFound();
        $this->actingAs($this->admin)->post(route('changes.transition', $theirs), ['action' => 'submit'])->assertNotFound();
        $this->actingAs($this->admin)->post(route('changes.decide', $theirs), ['decision' => 'approve'])->assertNotFound();
        $this->actingAs($foreignAdmin)->get(route('changes.show', $ours))->assertNotFound();

        $this->actingAs($this->admin)->get(route('changes.cab.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/Cab/Index')->has('boards', 2)->has('boards.0.members', 4));
        $this->actingAs($this->admin)->get(route('changes.policy'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/Policy')->has('stages', 6)->has('boards', 2));
        $this->actingAs($this->admin)->get(route('changes.templates'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/Templates')->has('templates', 1)->where('templates.0.name', 'Routine OS patching'));
        $this->actingAs($this->admin)->get(route('changes.freeze.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/FreezeWindows')->has('windows', 1)->where('windows.0.name', 'Year-end close'));
    }

    /* ========================== 2. risk scoring ========================== */

    public function test_risk_scoring_bands_and_automatic_uplifts(): void
    {
        $scorer = app(ChangeRiskScorer::class);
        $score = fn (array $attrs) => $scorer->scoreFor(new ChangeRequest($attrs + [
            'organization_id' => $this->org->id, 'change_type' => 'normal', 'category' => 'application',
        ]));

        $this->assertSame(['score' => 1, 'level' => 'low'], array_intersect_key($score(['impact' => 1, 'likelihood' => 1]), ['score' => 1, 'level' => 1]));
        $this->assertSame('low', $score(['impact' => 2, 'likelihood' => 2])['level']);
        $this->assertSame('medium', $score(['impact' => 5, 'likelihood' => 1])['level']);
        $this->assertSame('medium', $score(['impact' => 3, 'likelihood' => 3])['level']);
        $this->assertSame('high', $score(['impact' => 5, 'likelihood' => 2])['level']);
        $this->assertSame('high', $score(['impact' => 3, 'likelihood' => 5])['level']);
        $this->assertSame('critical', $score(['impact' => 4, 'likelihood' => 4])['level']);
        $this->assertSame(25, $score(['impact' => 5, 'likelihood' => 5])['score']);
        $this->assertSame(25, $score(['impact' => 9, 'likelihood' => 9])['score'], 'ratings are clamped to 1–5');

        // Outage: one likelihood step (3×3 = 9 medium → 3×4 = 12 high), recorded as a factor.
        $outage = $score(['impact' => 3, 'likelihood' => 3, 'outage_required' => true]);
        $this->assertSame([12, 'high'], [$outage['score'], $outage['level']]);
        $this->assertStringContainsString('Outage required', $outage['factors'][0]);

        // Core banking: never below high, score untouched.
        $core = $score(['impact' => 1, 'likelihood' => 1, 'category' => 'core_banking']);
        $this->assertSame([1, 'high'], [$core['score'], $core['level']]);
        $this->assertStringContainsString('Core banking', $core['factors'][0]);

        // Critical affected asset in this bank raises to high; a foreign or non-critical asset does not.
        $critical = Asset::factory()->create(['organization_id' => $this->org->id, 'criticality' => 'critical', 'name' => 'Finacle core']);
        $medium = Asset::factory()->create(['organization_id' => $this->org->id, 'criticality' => 'medium']);
        $foreign = Asset::factory()->create(['organization_id' => $this->other->id, 'criticality' => 'critical']);
        $asset = $score(['impact' => 1, 'likelihood' => 1, 'affected_asset_ids' => [$medium->id, $critical->id]]);
        $this->assertSame('high', $asset['level']);
        $this->assertStringContainsString('Finacle core', $asset['factors'][0]);
        $this->assertSame('low', $score(['impact' => 1, 'likelihood' => 1, 'affected_asset_ids' => [$medium->id]])['level']);
        $this->assertSame('low', $score(['impact' => 1, 'likelihood' => 1, 'affected_asset_ids' => [$foreign->id]])['level']);

        // Emergency: never below medium; an already-high emergency is left alone.
        $this->assertSame('medium', $score(['impact' => 1, 'likelihood' => 1, 'change_type' => 'emergency'])['level']);
        $this->assertSame('critical', $score(['impact' => 5, 'likelihood' => 5, 'change_type' => 'emergency'])['level']);

        // apply() persists score and level on the change.
        $change = $this->draft(['impact' => 4, 'likelihood' => 4]);
        $this->assertSame([16, 'critical'], [$change->risk_score, $change->risk_level]);
    }

    /* ========================= 3. routing matrix ========================= */

    public function test_routing_matrix_by_type_and_risk_level(): void
    {
        $orgId = $this->org->id;
        $keys = fn (string $type, string $level, bool $inFreeze = false) => $this->router->stagesFor($orgId, $type, $level, $inFreeze)->pluck('key')->all();

        $this->assertSame([], $keys('standard', 'low'));
        $this->assertSame([], $keys('standard', 'critical'));
        $this->assertSame(['change_manager'], $keys('normal', 'low'));
        $this->assertSame(['technical_review', 'security_review', 'change_manager'], $keys('normal', 'medium'));
        $this->assertSame(['technical_review', 'security_review', 'business_approval', 'cab', 'change_manager'], $keys('normal', 'high'));
        $this->assertSame(['technical_review', 'security_review', 'business_approval', 'cab', 'change_manager'], $keys('normal', 'critical'));
        $this->assertSame(['ecab', 'change_manager'], $keys('emergency', 'medium'));
        $this->assertSame(['ecab', 'change_manager'], $keys('emergency', 'critical'));
        // Inside a freeze a standard change loses its pre-approval; an emergency does not gain a stage.
        $this->assertSame(['change_manager'], $keys('standard', 'low', true));
        $this->assertSame(['ecab', 'change_manager'], $keys('emergency', 'low', true));

        $matrix = $this->router->matrix($orgId);
        $this->assertSame(['Change Manager authorisation'], $matrix['normal']['low']);
        $this->assertCount(5, $matrix['normal']['high']);
    }

    public function test_submitting_snapshots_the_route_and_notifies_the_first_stage(): void
    {
        $low = $this->submitted(['impact' => 1, 'likelihood' => 2]);
        $this->assertSame('in_approval', $low->status);
        $this->assertSame(['change_manager'], $this->stageKeys($low));
        $this->assertFalse($low->cab_required);
        $this->assertFalse($low->pir_required);
        Notification::assertSentTo($this->changeManager, ChangeApprovalRequestedNotification::class, fn ($n) => $n->change->is($low));

        $medium = $this->submitted();
        $this->assertSame(['technical_review', 'security_review', 'change_manager'], $this->stageKeys($medium));
        Notification::assertSentTo($this->headIt, ChangeApprovalRequestedNotification::class, fn ($n) => $n->change->is($medium));
        Notification::assertNotSentTo($this->ciso, ChangeApprovalRequestedNotification::class, fn ($n) => $n->change->is($medium));

        $high = $this->submitted(['impact' => 4, 'likelihood' => 3]);
        $this->assertSame(['technical_review', 'security_review', 'business_approval', 'cab', 'change_manager'], $this->stageKeys($high));
        $this->assertTrue($high->cab_required);
        $this->assertTrue($high->pir_required);
        $this->assertSame($this->owner->id, (int) $high->approvals->firstWhere('stage_key', 'business_approval')->approver_ref);

        $emergency = $this->submitted(['change_type' => 'emergency', 'source_type' => 'incident', 'impact' => 1, 'likelihood' => 1]);
        $this->assertSame(['ecab', 'change_manager'], $this->stageKeys($emergency));
        $this->assertSame('medium', $emergency->risk_level);
        $this->assertTrue($emergency->pir_required);
        Notification::assertSentTo($this->headIt, ChangeApprovalRequestedNotification::class, fn ($n) => $n->change->is($emergency));

        // An emergency without an incident / vulnerability / regulatory source needs a justification.
        $unjustified = $this->draft(['change_type' => 'emergency', 'source_type' => 'other', 'justification' => 'Hotfix']);
        $this->actingAs($this->requester)->post(route('changes.transition', $unjustified), ['action' => 'submit'])->assertSessionHasErrors('action');
        $this->assertSame('draft', $unjustified->fresh()->status);
    }

    /* ======================= 4. sequential approvals ======================= */

    public function test_only_the_current_stage_is_actionable_approving_advances_and_rejection_skips_the_rest(): void
    {
        $change = $this->submitted();
        $this->assertSame('technical_review', $change->currentApproval()->stage_key);

        // The CISO's stage is second: she cannot act yet.
        $this->actingAs($this->ciso)->post(route('changes.decide', $change), ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $this->assertSame('technical_review', $change->fresh()->currentApproval()->stage_key);
        $this->actingAs($this->headIt)->post(route('changes.decide', $change), ['decision' => 'maybe'])->assertSessionHasErrors('decision');

        $this->actingAs($this->headIt)->post(route('changes.decide', $change), ['decision' => 'approve', 'note' => 'Peer reviewed.'])->assertSessionHasNoErrors();
        $change->refresh();
        $this->assertSame('security_review', $change->currentApproval()->stage_key);
        $this->assertSame('approved', $change->approvals->firstWhere('stage_key', 'technical_review')->status);
        $this->assertSame($this->headIt->id, $change->approvals->firstWhere('stage_key', 'technical_review')->decided_by_id);
        Notification::assertSentTo($this->ciso, ChangeApprovalRequestedNotification::class, fn ($n) => $n->change->is($change));
        Notification::assertSentTo($this->requester, ChangeDecisionNotification::class, fn ($n) => $n->change->is($change) && $n->decision === 'stage_approved');

        // Rejection needs a reason, then skips every later stage and tells the requester.
        $this->actingAs($this->ciso)->post(route('changes.decide', $change), ['decision' => 'reject'])->assertSessionHasErrors('note');
        $this->actingAs($this->ciso)->post(route('changes.decide', $change), ['decision' => 'reject', 'note' => 'Cipher list still includes RC4.'])->assertSessionHasNoErrors();
        $change->refresh();
        $this->assertSame('rejected', $change->status);
        $this->assertSame('Cipher list still includes RC4.', $change->rejection_reason);
        $this->assertSame(['approved', 'rejected', 'skipped'], $change->approvals->sortBy('sequence')->pluck('status')->values()->all());
        Notification::assertSentTo($this->requester, ChangeDecisionNotification::class, fn ($n) => $n->change->is($change) && $n->decision === 'rejected' && $n->stageName === 'Information security review');
        Notification::assertNotSentTo($this->changeManager, ChangeApprovalRequestedNotification::class, fn ($n) => $n->change->is($change));

        // Nothing is pending any more, and a revision returns the change to draft.
        $this->actingAs($this->changeManager)->post(route('changes.decide', $change), ['decision' => 'approve'])->assertSessionHasErrors('decision');
        $this->actingAs($this->requester)->post(route('changes.transition', $change), ['action' => 'revise'])->assertSessionHasErrors('note');
        $this->actingAs($this->requester)->post(route('changes.transition', $change), ['action' => 'revise', 'note' => 'Removed RC4.'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $change->fresh()->status);
    }

    /* ====================== 5. segregation of duties ====================== */

    public function test_segregation_of_duties_for_requester_implementer_and_final_authority(): void
    {
        // A requester who otherwise holds the role may not approve their own change.
        $change = $this->submitted(['impact' => 1, 'likelihood' => 2]);
        $selfRaised = $this->draft(['impact' => 1, 'likelihood' => 2], $this->changeManager);
        $this->workflow->transition($selfRaised, 'submit', $this->changeManager);
        $this->assertFalse($this->router->canDecide($selfRaised->fresh()->currentApproval(), $this->changeManager));
        $this->actingAs($this->changeManager)->post(route('changes.decide', $selfRaised), ['decision' => 'approve'])
            ->assertSessionHasErrors(['decision' => 'The requester cannot approve their own change.']);
        $this->assertSame('in_approval', $selfRaised->fresh()->status);
        $this->actingAs($this->requester)->post(route('changes.decide', $change), ['decision' => 'approve'])
            ->assertSessionHasErrors(['decision' => 'The requester cannot approve their own change.']);

        // The final Change Manager stage always needs `approve changes`: a Viewer who is Head of IT cannot clear it.
        $this->assertFalse($this->router->canDecide($change->currentApproval(), $this->headIt));
        $this->actingAs($this->headIt)->post(route('changes.decide', $change), ['decision' => 'approve'])
            ->assertSessionHasErrors(['decision' => 'Change Manager authorisation needs the approve changes permission.']);
        // …and the implementer cannot approve even with the role.
        $implementingManager = $this->user('Change Manager', ['job_title' => 'Deputy Change Manager']);
        $change->forceFill(['implementer_id' => $implementingManager->id])->save();
        $this->actingAs($implementingManager)->post(route('changes.decide', $change), ['decision' => 'approve'])
            ->assertSessionHasErrors(['decision' => 'The implementer cannot approve the change they will implement.']);
        $this->assertNotContains($implementingManager->id, $this->router->eligibleUsers($change->currentApproval())->pluck('id')->all());
        $this->actingAs($this->changeManager)->post(route('changes.decide', $change), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $change->fresh()->status);

        // The implementer cannot do the PIR on their own change, even holding approve changes.
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $change), ['scheduled_start_at' => now()->addHour()->toDateTimeString()]);
        $this->actingAs($implementingManager)->post(route('changes.transition', $change), ['action' => 'start'])->assertSessionHasNoErrors();
        $this->actingAs($implementingManager)->post(route('changes.transition', $change), ['action' => 'back_out', 'note' => 'Node unstable.'])->assertSessionHasNoErrors();
        $this->assertSame('review', $change->fresh()->status);
        $this->actingAs($implementingManager)->post(route('changes.transition', $change), ['action' => 'review', 'pir_outcome' => 'backed_out', 'pir_summary' => 'x'])
            ->assertSessionHasErrors('action');
        $this->actingAs($this->headIt)->post(route('changes.transition', $change), ['action' => 'review', 'pir_outcome' => 'backed_out', 'pir_summary' => 'x'])
            ->assertForbidden();
        $this->actingAs($this->changeManager)->get(route('changes.show', $change))
            ->assertInertia(fn (Assert $p) => $p->where('canReview', true));
        $this->actingAs($implementingManager)->get(route('changes.show', $change))
            ->assertInertia(fn (Assert $p) => $p->where('canReview', false));
    }

    /* =========================== 6. CAB quorum =========================== */

    public function test_cab_votes_reach_quorum_one_reject_blocks_and_the_chair_decides(): void
    {
        $change = $this->atCab();
        $approval = $change->currentApproval();
        $this->assertSame(3, $this->router->evaluateBoard($approval)['quorum']);
        $this->assertSame(4, $this->router->evaluateBoard($approval)['voting']);

        // Non-members cannot vote; the requester, even if a member, could not either.
        $this->actingAs($this->headIt)->post(route('changes.vote', $change), ['vote' => 'approve'])->assertSessionHasErrors('vote');
        $this->actingAs($this->cab1)->post(route('changes.vote', $change), ['vote' => 'reject'])->assertSessionHasErrors('comment');

        $this->actingAs($this->cab1)->post(route('changes.vote', $change), ['vote' => 'approve'])->assertSessionHasNoErrors();
        $this->actingAs($this->cab2)->post(route('changes.vote', $change), ['vote' => 'abstain'])->assertSessionHasNoErrors();
        $this->actingAs($this->cab3)->post(route('changes.vote', $change), ['vote' => 'approve', 'comment' => 'Fine.'])->assertSessionHasNoErrors();
        $this->assertSame('pending', $change->fresh()->currentApproval()->status);
        $this->assertSame('cab', $change->fresh()->currentApproval()->stage_key);
        // A member may change their mind: the vote is upserted, not duplicated.
        $this->actingAs($this->cab2)->post(route('changes.vote', $change), ['vote' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(3, $approval->votes()->count());
        $change->refresh();
        $this->assertSame('approved', $change->approvals->firstWhere('stage_key', 'cab')->status);
        $this->assertSame('change_manager', $change->currentApproval()->stage_key);
        $this->assertNull($change->approvals->firstWhere('stage_key', 'cab')->decided_by_id);
        $this->assertSame(4, $change->events()->where('event_type', 'cab_vote')->count());

        // One reject vote keeps the stage open even past quorum; the chair confirms the rejection (logged as an override).
        $blocked = $this->atCab(['title' => 'Replace core switch firmware']);
        $this->actingAs($this->cab1)->post(route('changes.vote', $blocked), ['vote' => 'reject', 'comment' => 'No rollback tested.'])->assertSessionHasNoErrors();
        foreach ([$this->cab2, $this->cab3, $this->ciso] as $member) {
            $this->workflow->vote($blocked, $member, 'approve', '');
        }
        $tally = $this->router->evaluateBoard($blocked->fresh()->currentApproval());
        $this->assertSame([3, 1, null], [$tally['approve'], $tally['reject'], $tally['outcome']]);
        $this->assertSame('in_approval', $blocked->fresh()->status);
        $this->actingAs($this->cab3)->post(route('changes.decide', $blocked), ['decision' => 'reject', 'note' => 'x'])->assertSessionHasErrors('decision');
        $this->actingAs($this->ciso)->post(route('changes.decide', $blocked), ['decision' => 'reject', 'note' => 'Chair: rollback must be tested first.'])->assertSessionHasNoErrors();
        $blocked->refresh();
        $this->assertSame('rejected', $blocked->status);
        $this->assertSame($this->ciso->id, $blocked->approvals->firstWhere('stage_key', 'cab')->decided_by_id);
        $this->assertSame('skipped', $blocked->approvals->firstWhere('stage_key', 'change_manager')->status);
        $this->assertSame(1, $blocked->events()->where('event_type', 'cab_override')->count());

        // The chair may also override to approve while votes are short of quorum.
        $overridden = $this->atCab(['title' => 'Chair-approved change']);
        $this->workflow->vote($overridden, $this->cab1, 'approve', '');
        $this->actingAs($this->ciso)->post(route('changes.decide', $overridden), ['decision' => 'approve', 'note' => 'Chair decision between meetings.'])->assertSessionHasNoErrors();
        $this->assertSame('change_manager', $overridden->fresh()->currentApproval()->stage_key);
        $this->assertSame(1, $overridden->events()->where('event_type', 'cab_override')->count());
    }

    /* ========================== 7. freeze window ========================== */

    public function test_freeze_window_refuses_a_normal_change_unless_overridden_and_lets_an_emergency_through(): void
    {
        $window = $this->freeze();
        $inside = ['scheduled_start_at' => now()->addDays(11), 'scheduled_end_at' => now()->addDays(11)->addHours(2)];

        $normal = $this->draft(['impact' => 1, 'likelihood' => 1] + $inside);
        $this->actingAs($this->requester)->post(route('changes.transition', $normal), ['action' => 'submit'])->assertSessionHasErrors('scheduled_start_at');
        $this->assertSame('draft', $normal->fresh()->status);
        $this->assertSame($window->id, $this->workflow->freezeFor($normal->fresh())?->id);

        $this->actingAs($this->requester)->post(route('changes.transition', $normal), ['action' => 'submit', 'freeze_override_reason' => 'CBN-mandated patch deadline.'])
            ->assertSessionHasNoErrors();
        $normal->refresh();
        $this->assertSame('in_approval', $normal->status);
        $this->assertSame('CBN-mandated patch deadline.', $normal->freeze_override_reason);
        $event = $normal->events()->where('event_type', 'freeze_override')->first();
        $this->assertNotNull($event);
        $this->assertSame($window->id, $event->payload['freeze_window_id']);
        $this->assertSame($this->requester->id, $event->actor_id);

        // Outside the window, or in a category the freeze does not cover, nothing is asked.
        $outside = $this->draft(['impact' => 1, 'likelihood' => 1, 'scheduled_start_at' => now()->addDays(20), 'scheduled_end_at' => now()->addDays(20)->addHour()]);
        $this->actingAs($this->requester)->post(route('changes.transition', $outside), ['action' => 'submit'])->assertSessionHasNoErrors();
        $window->update(['scope' => 'categories', 'categories' => ['core_banking']]);
        $otherCategory = $this->draft(['impact' => 1, 'likelihood' => 1, 'category' => 'network'] + $inside);
        $this->actingAs($this->requester)->post(route('changes.transition', $otherCategory), ['action' => 'submit'])->assertSessionHasNoErrors();
        $window->update(['scope' => 'all', 'categories' => null]);

        // An emergency passes when the window allows emergencies; not when it does not.
        $emergency = $this->draft(['change_type' => 'emergency', 'source_type' => 'incident'] + $inside);
        $this->actingAs($this->requester)->post(route('changes.transition', $emergency), ['action' => 'submit'])->assertSessionHasNoErrors();
        $this->assertSame('in_approval', $emergency->fresh()->status);
        $this->assertSame(0, $emergency->events()->where('event_type', 'freeze_override')->count());
        $window->update(['allow_emergency' => false]);
        $strict = $this->draft(['change_type' => 'emergency', 'source_type' => 'incident'] + $inside);
        $this->actingAs($this->requester)->post(route('changes.transition', $strict), ['action' => 'submit'])->assertSessionHasErrors('scheduled_start_at');

        // Scheduling an approved change into a freeze is refused the same way, and accepted with a reason.
        $window->update(['allow_emergency' => true]);
        $approved = $this->approvedLow();
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $approved), ['scheduled_start_at' => now()->addDays(12)->toDateTimeString()])
            ->assertSessionHasErrors('scheduled_start_at');
        $this->assertSame('approved', $approved->fresh()->status);
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $approved), ['scheduled_start_at' => now()->addDays(12)->toDateTimeString(), 'freeze_override_reason' => 'Regulator deadline.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $approved->fresh()->status);
        $this->assertSame(1, $approved->events()->where('event_type', 'freeze_override')->count());
    }

    /* ======================== 8. conflict detection ======================== */

    public function test_conflict_detection_returns_overlapping_changes_sharing_an_asset(): void
    {
        $asset = Asset::factory()->create(['organization_id' => $this->org->id, 'criticality' => 'medium', 'name' => 'Core switch A']);
        $otherAsset = Asset::factory()->create(['organization_id' => $this->org->id, 'criticality' => 'medium']);
        $start = now()->addDays(3)->setTime(22, 0);

        $booked = $this->approvedLow(['title' => 'Switch firmware', 'affected_asset_ids' => [$asset->id]]);
        $this->workflow->schedule($booked, $this->changeManager, ['scheduled_start_at' => $start->toDateTimeString(), 'scheduled_end_at' => $start->copy()->addHours(3)->toDateTimeString()]);
        $this->assertSame('scheduled', $booked->fresh()->status);

        $detector = app(ChangeConflictDetector::class);
        $overlap = ['scheduled_start_at' => $start->copy()->addHour(), 'scheduled_end_at' => $start->copy()->addHours(4)];
        $sameAsset = $this->draft(['affected_asset_ids' => [$asset->id]] + $overlap);
        $conflicts = $detector->conflicts($sameAsset);
        $this->assertCount(1, $conflicts);
        $this->assertSame($booked->change_code, $conflicts[0]['change_code']);
        $this->assertStringContainsString("#{$asset->id}", $conflicts[0]['reason']);

        $this->assertSame([], $detector->conflicts($this->draft(['affected_asset_ids' => [$otherAsset->id]] + $overlap)), 'a different asset is no conflict');
        $this->assertSame([], $detector->conflicts($this->draft(['affected_asset_ids' => [$asset->id], 'scheduled_start_at' => $start->copy()->addDays(2), 'scheduled_end_at' => $start->copy()->addDays(2)->addHour()])), 'a later window is no conflict');
        $this->assertSame([], $detector->conflicts($this->draft(['affected_asset_ids' => [$asset->id]])), 'an unscheduled change has no window');
        // Draft changes do not occupy the forward schedule.
        $this->assertSame([], $detector->conflicts($booked->fresh()));

        // Same heavy category in an overlapping window counts too.
        $network = $this->approvedLow(['title' => 'WAN re-route', 'category' => 'network']);
        $this->workflow->schedule($network, $this->changeManager, ['scheduled_start_at' => $start->toDateTimeString(), 'scheduled_end_at' => $start->copy()->addHours(2)->toDateTimeString()]);
        $this->assertSame([$network->change_code], array_column($detector->conflicts($this->draft(['category' => 'network'] + $overlap)), 'change_code'));
        $this->assertSame([], $detector->conflicts($this->draft(['category' => 'application'] + $overlap)));

        // Scheduling through the controller flags and logs the conflict.
        $late = $this->approvedLow(['title' => 'Switch ACL update', 'affected_asset_ids' => [$asset->id]]);
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $late), ['scheduled_start_at' => $overlap['scheduled_start_at']->toDateTimeString(), 'scheduled_end_at' => $overlap['scheduled_end_at']->toDateTimeString()])
            ->assertSessionHas('warning', fn ($w) => str_contains($w, $booked->change_code));
        $this->assertSame(1, $late->events()->where('event_type', 'conflict')->count());
        $this->actingAs($this->admin)->get(route('changes.show', $late))
            ->assertInertia(fn (Assert $p) => $p->has('conflicts', 1)->where('conflicts.0.change_code', $booked->change_code));
    }

    /* ======================= 9. emergency retrospective ======================= */

    public function test_emergency_change_may_start_before_approval_with_authority_and_reason_and_is_flagged_when_overdue(): void
    {
        $emergency = $this->submitted(['change_type' => 'emergency', 'source_type' => 'incident', 'title' => 'Block C2 domain at the perimeter']);
        $this->assertSame('in_approval', $emergency->status);
        $normal = $this->submitted(['impact' => 1, 'likelihood' => 2]);

        // Only an emergency; only with approve changes; only with a reason.
        $this->actingAs($this->changeManager)->post(route('changes.transition', $normal), ['action' => 'start', 'emergency_start_reason' => 'Outage'])->assertSessionHasErrors('action');
        $this->actingAs($this->headIt)->post(route('changes.transition', $emergency), ['action' => 'start', 'emergency_start_reason' => 'Outage'])->assertForbidden();
        $this->actingAs($this->requester)->post(route('changes.transition', $emergency), ['action' => 'start', 'emergency_start_reason' => 'Outage'])->assertSessionHasErrors('action');
        $this->actingAs($this->ciso)->post(route('changes.transition', $emergency), ['action' => 'start'])->assertSessionHasErrors('note');
        $this->assertSame('in_approval', $emergency->fresh()->status);

        $this->actingAs($this->ciso)->post(route('changes.transition', $emergency), ['action' => 'start', 'emergency_start_reason' => 'Active exfiltration in progress.'])->assertSessionHasNoErrors();
        $emergency->refresh();
        $this->assertSame('implementing', $emergency->status);
        $this->assertTrue($emergency->started_before_approval);
        $this->assertSame('Active exfiltration in progress.', $emergency->emergency_start_reason);
        $this->assertNotNull($emergency->actual_start_at);
        $this->assertNull($emergency->approved_at);

        // Retrospective authorisation stays open: the ECAB can still vote and it still shows in the inbox.
        $this->assertTrue($this->router->isDecidable($emergency));
        $this->assertCount(1, $this->router->pendingFor($this->headIt));
        $this->actingAs($this->admin)->get(route('changes.index', ['type' => 'emergency']))
            ->assertInertia(fn (Assert $p) => $p->has('changes', 1)->where('changes.0.current_stage', 'Emergency CAB')->where('changes.0.status', 'implementing'));

        // Not yet unauthorised: the ECAB has four hours and the 24 h retrospective window is open.
        $this->actingAs($this->admin);
        $this->assertSame(0, app(ChangeMetrics::class)->summary()['unauthorised_emergency']);
        $this->travel(25)->hours();
        $this->assertSame(1, app(ChangeMetrics::class)->summary()['unauthorised_emergency']);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('changes.stats.unauthorised_emergency', 1));

        // Once the ECAB and the Change Manager sign it retrospectively, the flag clears and the change stays implementing.
        $this->workflow->vote($emergency, $this->headIt, 'approve', '');
        $this->workflow->vote($emergency, $this->cab1, 'approve', '');
        $this->assertSame('change_manager', $emergency->fresh()->currentApproval()->stage_key);
        $this->actingAs($this->changeManager)->post(route('changes.decide', $emergency), ['decision' => 'approve', 'note' => 'Retrospective.'])->assertSessionHasNoErrors();
        $emergency->refresh();
        $this->assertSame('implementing', $emergency->status);
        $this->assertNotNull($emergency->approved_at);
        $this->assertSame(0, app(ChangeMetrics::class)->summary()['unauthorised_emergency']);
        $this->assertTrue($emergency->events()->where('description', 'Retrospective authorisation complete.')->exists());
    }

    /* ===================== 10. implementation outcomes ===================== */

    public function test_a_low_change_closes_on_completion_and_a_high_change_needs_an_independent_pir(): void
    {
        // Low: approve → schedule → start → complete → closed, no PIR.
        $low = $this->approvedLow();
        $this->assertSame('approved', $low->status);
        $this->actingAs($this->implementer)->post(route('changes.transition', $low), ['action' => 'start'])->assertSessionHasErrors('action');
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $low), ['scheduled_start_at' => now()->addDays(3)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->actingAs($this->implementer)->post(route('changes.transition', $low), ['action' => 'start'])->assertSessionHasErrors('action');
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $low), ['scheduled_start_at' => now()->addHours(2)->toDateTimeString(), 'scheduled_end_at' => now()->addHours(4)->toDateTimeString()])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $low->fresh()->status);
        $this->actingAs($this->headIt)->post(route('changes.transition', $low), ['action' => 'start'])->assertForbidden();
        $this->actingAs($this->implementer)->post(route('changes.transition', $low), ['action' => 'start'])->assertSessionHasNoErrors();
        $this->assertSame('implementing', $low->fresh()->status);
        $this->actingAs($this->implementer)->post(route('changes.transition', $low), ['action' => 'complete'])->assertSessionHasNoErrors();
        $low->refresh();
        $this->assertSame(['closed', 'implemented'], [$low->status, $low->closure_code]);
        $this->assertNotNull($low->closed_at);
        $this->assertNull($low->pir_by_id);
        $this->actingAs($this->admin)->put(route('changes.update', $low), ['title' => 'x', 'category' => 'network', 'priority' => 'low', 'impact' => 1, 'likelihood' => 1])->assertSessionHas('error');

        // High: scheduling needs a test plan; completion parks the change in review until an independent PIR.
        $high = $this->atCab();
        foreach ([$this->cab1, $this->cab2, $this->cab3] as $m) {
            $this->workflow->vote($high, $m, 'approve', '');
        }
        $this->workflow->decide($high->fresh(), $this->changeManager, 'approve', '');
        $high->refresh();
        $this->assertSame(['approved', true], [$high->status, $high->pir_required]);
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $high), ['scheduled_start_at' => now()->addHour()->toDateTimeString()])->assertSessionHasNoErrors();
        $this->actingAs($this->implementer)->post(route('changes.transition', $high), ['action' => 'start'])->assertSessionHasNoErrors();
        $this->actingAs($this->implementer)->post(route('changes.transition', $high), ['action' => 'complete', 'note' => 'Rolled out to both nodes.'])->assertSessionHasNoErrors();
        $high->refresh();
        $this->assertSame(['review', 'implemented'], [$high->status, $high->closure_code]);
        $this->assertNull($high->closed_at);

        $this->actingAs($this->changeManager)->post(route('changes.transition', $high), ['action' => 'review', 'pir_summary' => 'Fine.'])->assertSessionHasErrors('pir_outcome');
        $this->actingAs($this->changeManager)->post(route('changes.transition', $high), ['action' => 'review', 'pir_outcome' => 'successful'])->assertSessionHasErrors('pir_summary');
        $this->actingAs($this->changeManager)->post(route('changes.transition', $high), ['action' => 'review', 'pir_outcome' => 'successful_with_issues', 'pir_summary' => 'Monitoring gap found.'])->assertSessionHasNoErrors();
        $high->refresh();
        $this->assertSame(['closed', 'implemented', 'successful_with_issues'], [$high->status, $high->closure_code, $high->pir_outcome]);
        $this->assertSame($this->changeManager->id, $high->pir_by_id);
        $this->assertTrue($high->events()->where('event_type', 'pir')->exists());
        $this->actingAs($this->changeManager)->post(route('changes.transition', $high), ['action' => 'complete'])->assertSessionHasErrors('action');
    }

    public function test_a_test_plan_is_required_before_scheduling_a_high_change(): void
    {
        $high = $this->atCab(['test_plan' => null]);
        foreach ([$this->cab1, $this->cab2, $this->cab3] as $m) {
            $this->workflow->vote($high, $m, 'approve', '');
        }
        $this->workflow->decide($high->fresh(), $this->changeManager, 'approve', '');
        $this->actingAs($this->changeManager)->post(route('changes.schedule', $high), ['scheduled_start_at' => now()->addHour()->toDateTimeString()])->assertSessionHasErrors('action');
        $this->assertSame('approved', $high->fresh()->status);
    }

    public function test_backing_out_or_failing_forces_a_pir_even_on_a_low_change(): void
    {
        foreach (['back_out' => 'backed_out', 'fail' => 'failed'] as $action => $closure) {
            $change = $this->approvedLow(['title' => "Outcome {$action}"]);
            $this->workflow->schedule($change, $this->changeManager, ['scheduled_start_at' => now()->addHour()->toDateTimeString()]);
            $this->workflow->transition($change->fresh(), 'start', $this->implementer);
            $this->assertFalse($change->fresh()->pir_required);

            $this->actingAs($this->implementer)->post(route('changes.transition', $change), ['action' => $action])->assertSessionHasErrors('note');
            $this->actingAs($this->implementer)->post(route('changes.transition', $change), ['action' => $action, 'note' => 'Service did not come back.'])->assertSessionHasNoErrors();
            $change->refresh();
            $this->assertSame(['review', $closure, true], [$change->status, $change->closure_code, $change->pir_required]);
            $this->assertNotNull($change->actual_end_at);
            $this->assertNull($change->closed_at);

            $this->actingAs($this->implementer)->post(route('changes.transition', $change), ['action' => 'review', 'pir_outcome' => $closure, 'pir_summary' => 'x'])->assertSessionHasErrors('action');
            $this->actingAs($this->ciso)->post(route('changes.transition', $change), ['action' => 'review', 'pir_outcome' => $closure, 'pir_summary' => 'Restore from snapshot worked; root cause in vendor patch.'])->assertSessionHasNoErrors();
            $change->refresh();
            $this->assertSame(['closed', $closure, $this->ciso->id], [$change->status, $change->closure_code, $change->pir_by_id]);
        }
        $this->assertSame(2, app(ChangeMetrics::class)->summary()['failed_or_backed_out_90d']);
    }

    /* ==================== 11. standard template eligibility ==================== */

    public function test_standard_changes_need_an_approved_in_date_template_and_count_usage(): void
    {
        $template = $this->template();
        $payload = [
            'title' => 'Monthly OS patching — web tier', 'change_type' => 'standard', 'category' => 'infrastructure', 'priority' => 'low',
            'impact' => 1, 'likelihood' => 1, 'standard_template_id' => $template->id, 'submit' => true,
        ];

        // No template at all is refused at validation; an unapproved template saves a draft with a warning.
        $this->actingAs($this->requester)->post(route('changes.store'), array_diff_key($payload, ['standard_template_id' => 1]))->assertSessionHasErrors('standard_template_id');
        $this->actingAs($this->requester)->post(route('changes.store'), $payload)->assertSessionHas('warning', fn ($w) => str_contains($w, 'has not been approved yet'));
        $change = ChangeRequest::withoutGlobalScopes()->where('title', $payload['title'])->firstOrFail();
        $this->assertSame('draft', $change->status);
        $this->assertSame(0, $template->fresh()->usage_count);

        // Approval is gated on approve changes; after it the template is eligible.
        $this->actingAs($this->requester)->post(route('changes.templates.approve', $template))->assertForbidden();
        $this->actingAs($this->changeManager)->post(route('changes.templates.approve', $template))->assertSessionHasNoErrors();
        $this->assertTrue($template->fresh()->isEligible());
        $this->actingAs($this->requester)->post(route('changes.transition', $change), ['action' => 'submit'])->assertSessionHasNoErrors();
        $change->refresh();
        $this->assertSame('approved', $change->status);
        $this->assertSame([], $this->stageKeys($change));
        $this->assertSame('Apply vendor patches.', $change->implementation_plan, 'plans are copied from the template');
        $this->assertSame(1, $template->fresh()->usage_count);
        $this->assertNotNull($change->approved_at);

        // Second use increments again; a template past its review date is refused.
        $this->actingAs($this->requester)->post(route('changes.store'), ['title' => 'Patching again'] + $payload)->assertSessionHas('success');
        $this->assertSame(2, $template->fresh()->usage_count);
        $expired = $this->template(['name' => 'Expired procedure', 'approved_at' => now()->subYear(), 'approved_by_id' => $this->admin->id, 'review_due_at' => now()->subDay()]);
        $this->assertSame('review_overdue', $expired->state());
        $this->actingAs($this->requester)->post(route('changes.store'), ['title' => 'From expired', 'standard_template_id' => $expired->id] + $payload)
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'past its review date'));
        $this->assertSame('draft', ChangeRequest::withoutGlobalScopes()->where('title', 'From expired')->value('status'));
        $this->assertSame(0, $expired->fresh()->usage_count);

        // A template from another bank cannot be used; editing the procedure withdraws the approval.
        $foreign = $this->template(['organization_id' => $this->other->id, 'name' => 'Theirs', 'approved_at' => now()]);
        $this->actingAs($this->requester)->post(route('changes.store'), ['title' => 'Foreign', 'standard_template_id' => $foreign->id] + $payload)->assertSessionHasErrors('standard_template_id');
        $this->actingAs($this->changeManager)->put(route('changes.templates.update', $template), [
            'name' => $template->name, 'category' => 'infrastructure', 'risk_level' => 'low', 'implementation_plan' => 'Apply vendor patches, then reboot.', 'backout_plan' => 'Roll back to snapshot.',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($template->fresh()->isEligible());
        $this->actingAs($this->requester)->get(route('changes.create'))->assertInertia(fn (Assert $p) => $p->has('templates', 0));
    }

    /* ======================= 12. inbox and overdue sweep ======================= */

    public function test_my_approvals_lists_only_actionable_approvals(): void
    {
        $medium = $this->submitted();
        $low = $this->submitted(['impact' => 1, 'likelihood' => 2, 'title' => 'Low one']);
        $cab = $this->atCab(['title' => 'At CAB']);
        $this->workflow->vote($cab, $this->cab1, 'approve', '');

        $inbox = fn (User $u) => collect($this->actingAs($u)->get(route('changes.approvals'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/Approvals'))->viewData('page')['props']['approvals']);
        $codes = fn (User $u) => $inbox($u)->pluck('change.change_code')->sort()->values()->all();

        $headIt = $inbox($this->headIt);
        $this->assertSame([$medium->change_code], $headIt->pluck('change.change_code')->all());
        $this->assertTrue($headIt->first()['can_decide']);
        $this->assertSame([], $codes($this->requester), 'the requester never sees their own changes');
        // The CISO's security stage is not current yet; as an approve-changes holder she may decide the CAB stage.
        $this->assertSame([$cab->change_code], $codes($this->ciso));
        // The Change Manager sees the low change at its final stage and the CAB stage (board decisions need approve changes).
        $this->assertSame(collect([$low->change_code, $cab->change_code])->sort()->values()->all(), $codes($this->changeManager));

        $cabInbox = $inbox($this->cab1);
        $this->assertSame([$cab->change_code], $cabInbox->pluck('change.change_code')->all());
        $this->assertSame(['is_board' => true, 'can_decide' => true, 'my_vote' => 'approve'], array_intersect_key($cabInbox->first(), ['is_board' => 1, 'can_decide' => 1, 'my_vote' => 1]));
        $member = $inbox($this->cab3)->first();
        $this->assertSame([$cab->change_code, false, null], [$member['change']['change_code'], $member['can_decide'], $member['my_vote']], 'a plain member votes but does not decide');

        // Decided approvals move to the history and the next stage's approver inherits the item.
        $this->actingAs($this->headIt)->post(route('changes.decide', $medium), ['decision' => 'approve']);
        $this->assertSame([], $codes($this->headIt));
        $this->actingAs($this->headIt)->get(route('changes.approvals'))
            ->assertInertia(fn (Assert $p) => $p->has('history', 2)->where('history.0.status', 'approved')->where('history.0.change.change_code', $medium->change_code)
                ->where('history.1.change.change_code', $cab->change_code));
        $this->assertSame(collect([$medium->change_code, $cab->change_code])->sort()->values()->all(), $codes($this->ciso));
        $this->assertSame(2, $this->router->pendingFor($this->ciso)->count());
    }

    public function test_sweep_reminds_overdue_approvers_once_per_24_hours(): void
    {
        $medium = $this->submitted();          // technical review SLA 24 h
        $low = $this->submitted(['impact' => 1, 'likelihood' => 2]);   // change manager SLA 24 h
        $foreignAdmin = $this->user('Organization Admin', ['job_title' => 'CISO'], $this->other);
        $foreignHeadIt = $this->user('Viewer', ['job_title' => 'Head of IT'], $this->other);
        ChangePolicySeeder::ensureDefaults($this->other->id);
        $theirs = $this->draft(['impact' => 3, 'likelihood' => 3], $foreignAdmin);
        $this->workflow->transition($theirs, 'submit', $foreignAdmin);

        $this->artisan('changes:sweep')->expectsOutputToContain('0 overdue')->assertSuccessful();
        Notification::assertNotSentTo($this->headIt, ChangeApprovalOverdueNotification::class);

        $this->travel(25)->hours();
        $this->artisan('changes:sweep')->expectsOutputToContain('3 overdue')->assertSuccessful();
        Notification::assertSentToTimes($this->headIt, ChangeApprovalOverdueNotification::class, 1);
        Notification::assertSentTo($this->headIt, ChangeApprovalOverdueNotification::class, fn ($n) => $n->change->is($medium));
        Notification::assertSentToTimes($this->changeManager, ChangeApprovalOverdueNotification::class, 1);
        Notification::assertSentTo($this->changeManager, ChangeApprovalOverdueNotification::class, fn ($n) => $n->change->is($low));
        Notification::assertNotSentTo($this->ciso, ChangeApprovalOverdueNotification::class, fn ($n) => $n->change->is($medium));
        Notification::assertNotSentTo($this->requester, ChangeApprovalOverdueNotification::class);
        // Reminders never cross tenants.
        Notification::assertSentToTimes($foreignHeadIt, ChangeApprovalOverdueNotification::class, 1);
        Notification::assertSentTo($foreignHeadIt, ChangeApprovalOverdueNotification::class, fn ($n) => $n->change->is($theirs));
        Notification::assertNotSentTo($this->headIt, ChangeApprovalOverdueNotification::class, fn ($n) => $n->change->is($theirs));
        $this->assertNotNull($medium->fresh()->currentApproval()->reminded_at);
        $this->assertSame(1, $medium->events()->where('event_type', 'notified')->where('description', 'like', 'Overdue reminder%')->count());

        // Within 24 h nothing is repeated.
        $this->travel(6)->hours();
        $this->assertSame(0, $this->workflow->sweepOverdueApprovals());
        Notification::assertSentToTimes($this->headIt, ChangeApprovalOverdueNotification::class, 1);

        // After 24 h the reminder goes again — unless the stage has been cleared meanwhile.
        $this->workflow->decide($low->fresh(), $this->changeManager, 'approve', '');
        $this->travel(19)->hours();
        $this->assertSame(2, $this->workflow->sweepOverdueApprovals(), 'our medium change and the other bank\'s change');
        Notification::assertSentToTimes($this->headIt, ChangeApprovalOverdueNotification::class, 2);
        Notification::assertSentToTimes($this->changeManager, ChangeApprovalOverdueNotification::class, 1);

        $this->actingAs($this->admin);
        $this->assertSame(1, app(ChangeMetrics::class)->summary()['overdue_approvals']);
    }

    /* ============================ 13. CSV export ============================ */

    public function test_csv_export_needs_export_changes_and_is_tenant_scoped(): void
    {
        $ours = $this->submitted(['title' => 'Our "quoted" change']);
        $foreignAdmin = $this->user('Organization Admin', ['job_title' => 'CISO'], $this->other);
        // Codes are sequential per bank, so give the other bank a second change whose code we never issued.
        $this->draft(['title' => 'Their first change'], $foreignAdmin);
        $theirs = $this->draft(['title' => 'Their change'], $foreignAdmin);
        $this->assertSame('CHG-0002', $theirs->change_code);
        $viewer = $this->user('Viewer');
        $this->assertTrue($viewer->can('view changes'));
        $this->assertFalse($viewer->can('export changes'));

        $this->actingAs($viewer)->get(route('changes.export'))->assertForbidden();
        $this->actingAs($this->requester)->get(route('changes.export'))->assertForbidden();

        $response = $this->actingAs($this->admin)->get(route('changes.export'))->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('change-register-'.Str::slug($this->org->name).'-'.now()->format('Ymd').'.csv');
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('Code,Title,Type,Category', $lines[0]);
        $this->assertStringContainsString($ours->change_code, $csv);
        $this->assertStringContainsString('"Our ""quoted"" change"', $csv);
        $this->assertStringNotContainsString($theirs->change_code, $csv);
        $this->assertStringNotContainsString('Their change', $csv);

        $auditor = $this->user('Auditor');
        $this->actingAs($auditor)->get(route('changes.export'))->assertOk();
        $theirCsv = $this->actingAs($foreignAdmin)->get(route('changes.export'))->assertOk()->streamedContent();
        $this->assertCount(3, array_filter(explode("\n", trim($theirCsv))));
        $this->assertStringContainsString($theirs->change_code, $theirCsv);
        $this->assertStringContainsString('Their change', $theirCsv);
        $this->assertStringNotContainsString('quoted', $theirCsv);
    }

    /* ======================= 14. dashboard and calendar ======================= */

    public function test_dashboard_pulse_and_calendar_range(): void
    {
        $this->submitted();
        $scheduled = $this->approvedLow(['title' => 'Scheduled soon']);
        $this->workflow->schedule($scheduled, $this->changeManager, ['scheduled_start_at' => now()->addDays(2)->toDateTimeString(), 'scheduled_end_at' => now()->addDays(2)->addHours(2)->toDateTimeString()]);
        $far = $this->approvedLow(['title' => 'Far away']);
        $this->workflow->schedule($far, $this->changeManager, ['scheduled_start_at' => now()->addDays(40)->toDateTimeString()]);
        $this->draft(['title' => 'Unsubmitted draft', 'scheduled_start_at' => now()->addDay(), 'scheduled_end_at' => now()->addDay()->addHour()]);
        $freeze = $this->freeze(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(5)]);
        $this->freeze(['name' => 'Next quarter', 'starts_at' => now()->addDays(60), 'ends_at' => now()->addDays(62)]);
        $foreignAdmin = $this->user('Organization Admin', ['job_title' => 'CISO'], $this->other);
        ChangePolicySeeder::ensureDefaults($this->other->id);
        $this->workflow->transition($this->draft([], $foreignAdmin), 'submit', $foreignAdmin);

        $this->actingAs($this->headIt)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('changes', fn (Assert $c) => $c
                ->where('stats.awaiting_approval', 1)->where('stats.open', 4)->where('stats.scheduled_7d', 1)->where('stats.implementing', 0)
                ->where('stats.emergency_30d', 0)->where('stats.overdue_approvals', 0)
                ->has('upcoming', 2)->where('upcoming.0.change_code', $scheduled->change_code)
                ->where('myApprovals', 1)));
        $this->actingAs($this->requester)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('changes.myApprovals', 0));
        $this->actingAs($foreignAdmin)->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p->where('changes.stats.awaiting_approval', 1)->where('changes.stats.open', 1));

        $from = now()->toDateString();
        $to = now()->addDays(10)->toDateString();
        $this->actingAs($this->admin)->get(route('changes.calendar', ['from' => $from, 'to' => $to]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Changes/Calendar')
                ->where('from', $from)->where('to', $to)
                ->has('changes', 1)->where('changes.0.change_code', $scheduled->change_code)
                ->has('freezeWindows', 1)->where('freezeWindows.0.id', $freeze->id)
                ->has('conflictIds', 0));
        $this->actingAs($this->admin)->get(route('changes.calendar', ['from' => now()->addDays(35)->toDateString(), 'to' => now()->addDays(65)->toDateString()]))
            ->assertInertia(fn (Assert $p) => $p->has('changes', 1)->where('changes.0.change_code', $far->change_code)->has('freezeWindows', 1)->where('freezeWindows.0.name', 'Next quarter'));
        // Reversed bounds are swapped rather than rejected.
        $this->actingAs($this->admin)->get(route('changes.calendar', ['from' => $to, 'to' => $from]))
            ->assertInertia(fn (Assert $p) => $p->where('from', $from)->where('to', $to)->has('changes', 1));
    }
}
