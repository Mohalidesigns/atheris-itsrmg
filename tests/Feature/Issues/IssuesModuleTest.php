<?php

namespace Tests\Feature\Issues;

use App\Models\Issue;
use App\Models\ItsmConnection;
use App\Models\Organization;
use App\Models\Risk;
use App\Models\User;
use App\Notifications\IssueEscalatedNotification;
use App\Services\Issues\IssueWorkflow;
use App\Services\Issues\ItsmConnector;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Issues & Remediation (ATH-VAL-006): tenant isolation of the register, SLA policy and ITSM
 * connectors; raising an issue against an in-bank source; the lifecycle with blocked-time SLA
 * pause and segregated verification; escalation; ITSM push and the signed status webhook; and the
 * dashboard feed.
 */
class IssuesModuleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;

    private User $riskManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin', ['job_title' => 'CISO']);
        $this->riskManager = $this->user('Risk Manager', ['job_title' => 'Chief Risk Officer']);

        foreach (['critical' => 72, 'high' => 336, 'medium' => 720, 'low' => 2160] as $severity => $hours) {
            DB::table('vulnerability_sla_policies')->insert([
                'organization_id' => $this->org->id, 'severity' => $severity, 'hours_to_remediate' => $hours,
                'escalation_rules' => json_encode(['chain' => ['owner', 'CISO', 'CRO']]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function user(string $role, array $attrs = [], ?Organization $org = null): User
    {
        $user = User::factory()->create($attrs + ['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function issue(array $attrs = [], ?Organization $org = null): Issue
    {
        $org ??= $this->org;

        return Issue::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $org->id,
            'issue_code' => 'ISS-'.fake()->unique()->numerify('####'),
            'source_type' => 'audit',
            'source_reference' => 'IA-2026-07 finding 3',
            'title' => 'Privileged accounts missing MFA',
            'severity' => 'high',
            'status' => 'open',
            'sla_minutes' => 336 * 60,
            'sla_due_at' => now()->addDays(14),
        ]);
    }

    private function connection(array $attrs = [], ?Organization $org = null): ItsmConnection
    {
        return ItsmConnection::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id,
            'name' => 'Service Desk', 'system' => 'jira', 'base_url' => 'https://bank.atlassian.net',
            'project_key' => 'GRC', 'mode' => 'sandbox', 'status' => 'active', 'webhook_secret' => 'whsec-test-123',
        ]);
    }

    public function test_console_sla_policies_and_itsm_only_show_the_banks_own_records(): void
    {
        $this->issue(['title' => 'Ours']);
        $theirs = $this->issue(['title' => 'Theirs'], $this->other);
        DB::table('vulnerability_sla_policies')->insert(['organization_id' => $this->other->id, 'severity' => 'critical', 'hours_to_remediate' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->connection(['name' => 'Their desk'], $this->other);
        $this->connection(['name' => 'Our desk']);

        $this->actingAs($this->admin)->get(route('issues.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Issues/Index')->has('issues', 1)->where('issues.0.title', 'Ours')->where('stats.open', 1));
        $this->actingAs($this->admin)->get(route('issues.show', $theirs))->assertNotFound();
        $this->actingAs($this->admin)->post(route('issues.transition', $theirs), ['action' => 'start'])->assertNotFound();

        $this->actingAs($this->admin)->get(route('issues.sla-policies'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('policies', 4)->where('policies.0.hours_to_remediate', 72));
        $this->actingAs($this->admin)->get(route('itsm.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('connections', 1)->where('connections.0.name', 'Our desk'));
    }

    public function test_raising_an_issue_sets_code_and_sla_and_rejects_another_banks_source(): void
    {
        $risk = Risk::factory()->create(['organization_id' => $this->org->id]);
        $foreignRisk = Risk::factory()->create(['organization_id' => $this->other->id]);
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $foreignUser = User::factory()->create(['organization_id' => $this->other->id]);

        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'Treat AD compromise risk', 'severity' => 'critical', 'source_type' => 'risk', 'source_id' => $foreignRisk->id,
        ])->assertSessionHasErrors('source_id');
        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'x', 'severity' => 'high', 'source_type' => 'risk', 'source_id' => $risk->id, 'owner_id' => $foreignUser->id,
        ])->assertSessionHasErrors('owner_id');
        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'Audit point', 'severity' => 'low', 'source_type' => 'audit',
        ])->assertSessionHasErrors('source_reference');

        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'Treat AD compromise risk', 'severity' => 'critical', 'source_type' => 'risk', 'source_id' => $risk->id, 'owner_id' => $owner->id,
        ])->assertRedirect();

        $issue = Issue::withoutGlobalScopes()->where('title', 'Treat AD compromise risk')->firstOrFail();
        $this->assertSame($this->org->id, $issue->organization_id);
        $this->assertSame('ISS-0001', $issue->issue_code);
        $this->assertSame(72 * 60, $issue->sla_minutes);
        $this->assertEqualsWithDelta(now()->addHours(72)->timestamp, $issue->sla_due_at->timestamp, 5);
        $this->assertSame($risk->id, $issue->source_id);
        $this->assertSame($risk->risk_id_code, $issue->sourceSummary()['code']);
        $this->assertEqualsCanonicalizing(['created', 'assigned'], $issue->events()->pluck('event_type')->all());
        $this->assertSame(1, $risk->issues()->count());
    }

    public function test_blocking_pauses_the_sla_and_unblocking_extends_it(): void
    {
        $issue = $this->issue(['status' => 'in_progress']);
        $due = $issue->sla_due_at->copy();

        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'block'])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'block', 'note' => 'Waiting on vendor patch.']);
        $this->assertSame('paused', $issue->fresh()->slaState());

        $this->travel(3)->days();
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'unblock']);
        $issue->refresh();
        $this->assertSame('in_progress', $issue->status);
        $this->assertEqualsWithDelta(3 * 24 * 60, $issue->sla_paused_minutes, 2);
        $this->assertEqualsWithDelta($due->copy()->addDays(3)->timestamp, $issue->sla_due_at->timestamp, 120);
    }

    public function test_remediation_needs_root_cause_and_verification_is_segregated(): void
    {
        $owner = $this->user('Viewer');
        $issue = $this->issue(['status' => 'in_progress', 'owner_id' => $owner->id]);

        // The owner may work their own issue without edit issues, but not someone else's.
        $this->actingAs($owner)->post(route('issues.transition', $this->issue()), ['action' => 'start'])->assertForbidden();
        $this->actingAs($owner)->post(route('issues.transition', $issue), ['action' => 'remediate'])->assertSessionHasErrors('action');

        $issue->update(['root_cause' => 'No MFA policy for service desk admins.', 'remediation_plan' => 'Enforce conditional access.']);
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'remediate'])->assertSessionHasNoErrors();
        $this->assertSame('remediated', $issue->fresh()->status);

        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'verify'])->assertSessionHasErrors('action');
        $this->actingAs($owner)->post(route('issues.transition', $issue), ['action' => 'verify'])->assertSessionHasErrors('action');

        $this->actingAs($this->riskManager)->post(route('issues.transition', $issue), ['action' => 'reject'])->assertSessionHasErrors('note');
        $this->actingAs($this->riskManager)->post(route('issues.transition', $issue), ['action' => 'verify', 'note' => 'Re-test passed.'])->assertSessionHasNoErrors();
        $issue->refresh();
        $this->assertSame('verified', $issue->status);
        $this->assertSame($this->riskManager->id, $issue->verified_by_id);
        $this->assertSame('met', $issue->slaState());

        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'close']);
        $this->actingAs($this->admin)->put(route('issues.update', $issue), ['title' => 'x', 'severity' => 'low'])->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'reopen', 'note' => 'Control failed again.']);
        $this->assertSame('in_progress', $issue->fresh()->status);
        $this->assertNull($issue->fresh()->verified_by_id);
    }

    public function test_out_of_order_steps_are_refused(): void
    {
        $issue = $this->issue();
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'close'])->assertSessionHasErrors('action');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'verify'])->assertSessionHasErrors('action');
        $this->assertSame('open', $issue->fresh()->status);
    }

    public function test_severity_change_rebases_the_sla_on_policy(): void
    {
        $issue = $this->issue(['created_at' => now()->subDay()]);
        $this->actingAs($this->admin)->put(route('issues.update', $issue), [
            'title' => $issue->title, 'severity' => 'critical', 'root_cause' => 'Found it',
        ])->assertSessionHasNoErrors();
        $issue->refresh();
        $this->assertSame('critical', $issue->severity);
        $this->assertEqualsWithDelta(now()->subDay()->addHours(72)->timestamp, $issue->sla_due_at->timestamp, 5);
        $this->assertTrue($issue->events()->where('event_type', 'severity_changed')->exists());
    }

    public function test_overdue_issues_escalate_through_the_chain_and_notify(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['organization_id' => $this->org->id]);
        $foreignCiso = User::factory()->create(['organization_id' => $this->other->id, 'job_title' => 'CISO']);
        // 336 h SLA; due 2 days ago → level 1 only.
        $breached = $this->issue(['owner_id' => $owner->id, 'sla_due_at' => now()->subDays(2)]);
        // Overdue by more than half the window → level 2.
        $long = $this->issue(['owner_id' => $owner->id, 'sla_due_at' => now()->subDays(8)]);
        $blocked = $this->issue(['status' => 'blocked', 'sla_due_at' => now()->subDays(8)]);

        $this->artisan('issues:escalate')->assertSuccessful();

        $this->assertSame(1, $breached->fresh()->escalation_level);
        $this->assertSame(2, $long->fresh()->escalation_level);
        $this->assertSame(0, $blocked->fresh()->escalation_level);
        Notification::assertSentTo($this->admin, IssueEscalatedNotification::class);
        Notification::assertSentTo($this->riskManager, IssueEscalatedNotification::class, fn ($n) => $n->issue->is($long) && $n->level === 2);
        Notification::assertNotSentTo($foreignCiso, IssueEscalatedNotification::class);

        // Idempotent: a second sweep raises nothing new.
        $this->assertSame(0, app(IssueWorkflow::class)->escalateOverdue());
        $this->actingAs($this->admin)->get(route('issues.index', ['status' => 'escalated']))
            ->assertInertia(fn (Assert $p) => $p->has('issues', 2));
    }

    public function test_sla_policy_edit_is_gated_and_scoped(): void
    {
        $viewer = $this->user('Viewer');
        $this->actingAs($viewer)->put(route('issues.sla-policies.update', 'high'), ['hours_to_remediate' => 100, 'chain' => ['owner']])->assertForbidden();

        $this->actingAs($this->admin)->put(route('issues.sla-policies.update', 'high'), ['hours_to_remediate' => 240, 'chain' => ['CISO', 'CRO']])
            ->assertSessionHasNoErrors();
        $row = DB::table('vulnerability_sla_policies')->where('organization_id', $this->org->id)->where('severity', 'high')->first();
        $this->assertSame(240, (int) $row->hours_to_remediate);
        $this->assertSame(['owner', 'CISO', 'CRO'], json_decode($row->escalation_rules, true)['chain']);
        $this->assertSame(240, app(IssueWorkflow::class)->slaHours($this->org->id, 'high'));
        $this->assertSame(720, app(IssueWorkflow::class)->slaHours($this->org->id, 'moderate'));
    }

    public function test_itsm_push_sandbox_and_live_and_auto_push(): void
    {
        $sandbox = $this->connection();
        $issue = $this->issue();
        $this->actingAs($this->admin)->post(route('issues.push', $issue), ['connection_id' => $this->connection([], $this->other)->id])
            ->assertSessionHasErrors('connection_id');
        $this->actingAs($this->admin)->post(route('issues.push', $issue), ['connection_id' => $sandbox->id])->assertSessionHas('success');
        $this->assertSame('GRC-101', $issue->fresh()->external_ticket_id);
        $this->actingAs($this->admin)->post(route('issues.push', $issue), ['connection_id' => $sandbox->id])->assertSessionHas('error');

        Http::fake(['https://bank.service-now.com/api/now/table/incident' => Http::response(['result' => ['number' => 'INC0012345', 'sys_id' => 'abc']], 201)]);
        $live = $this->connection(['name' => 'SNOW', 'system' => 'servicenow', 'base_url' => 'https://bank.service-now.com', 'mode' => 'live', 'username' => 'svc', 'api_token' => 'tok', 'auto_push_severity' => 'critical']);
        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'Public S3 bucket', 'severity' => 'critical', 'source_type' => 'audit', 'source_reference' => 'Cloud review',
        ]);
        $auto = Issue::withoutGlobalScopes()->where('title', 'Public S3 bucket')->firstOrFail();
        $this->assertSame('INC0012345', $auto->external_ticket_id);
        $this->assertSame($live->id, $auto->itsm_connection_id);
        Http::assertSent(fn ($req) => $req['correlation_id'] === $auto->issue_code && $req['urgency'] === 1);
    }

    public function test_signed_webhook_mirrors_status_and_marks_remediated(): void
    {
        $connection = $this->connection();
        $issue = $this->issue(['status' => 'in_progress', 'itsm_connection_id' => $connection->id, 'external_ticket_id' => 'GRC-7',
            'root_cause' => 'Drift', 'remediation_plan' => 'Fix and re-test']);
        $body = json_encode(['issue' => ['key' => 'GRC-7', 'fields' => ['status' => ['name' => 'Done']]]]);
        $ts = (string) time();

        $this->call('POST', route('itsm.webhook', $connection->id), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ATHERIS_TIMESTAMP' => $ts, 'HTTP_X_ATHERIS_SIGNATURE' => 'sha256=bad',
        ], $body)->assertStatus(401);

        $this->call('POST', route('itsm.webhook', $connection->id), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_ATHERIS_TIMESTAMP' => $ts,
            'HTTP_X_ATHERIS_SIGNATURE' => ItsmConnector::sign('whsec-test-123', $ts, $body),
        ], $body)->assertOk()->assertJson(['status' => 'remediated', 'changed' => true]);

        $issue->refresh();
        $this->assertSame('Done', $issue->external_ticket_status);
        $this->assertSame('remediated', $issue->status);
        $this->assertNull($issue->remediated_by_id);
        // Verification stays a human step: anyone with approve issues may verify an ITSM-remediated issue.
        $this->actingAs($this->riskManager)->post(route('issues.transition', $issue), ['action' => 'verify'])->assertSessionHasNoErrors();
    }

    public function test_itsm_connector_management_is_gated_and_keeps_secrets_server_side(): void
    {
        $viewer = $this->user('Viewer');
        $payload = ['name' => 'Desk', 'system' => 'jira', 'base_url' => 'https://bank.atlassian.net', 'project_key' => 'OPS', 'mode' => 'live', 'api_token' => 'secret-token'];
        $this->actingAs($viewer)->post(route('itsm.store'), $payload)->assertForbidden();
        $this->actingAs($this->admin)->post(route('itsm.store'), ['mode' => 'live'] + array_diff_key($payload, ['api_token' => 1]))->assertSessionHasErrors('api_token');
        $this->actingAs($this->admin)->post(route('itsm.store'), $payload)->assertSessionHasNoErrors();

        $connection = ItsmConnection::withoutGlobalScopes()->where('name', 'Desk')->firstOrFail();
        $this->assertSame($this->org->id, $connection->organization_id);
        $this->assertNotSame('secret-token', DB::table('itsm_connections')->where('id', $connection->id)->value('api_token'));

        $this->actingAs($this->admin)->put(route('itsm.update', $connection), ['api_token' => ''] + $payload)->assertSessionHasNoErrors();
        $this->assertSame('secret-token', $connection->fresh()->api_token);

        Http::fake(['https://bank.atlassian.net/rest/api/2/myself' => Http::response([], 401)]);
        $this->actingAs($this->admin)->post(route('itsm.test', $connection))->assertSessionHas('error');
        $this->assertSame('error', $connection->fresh()->status);

        $this->actingAs($this->admin)->get(route('itsm.index'))
            ->assertInertia(fn (Assert $p) => $p->missing('connections.0.api_token')->where('connections.0.has_token', true));
        $this->actingAs($this->admin)->post(route('itsm.test', $this->connection([], $this->other)))->assertNotFound();
    }

    public function test_dashboard_feeds_issue_metrics(): void
    {
        $this->issue(['severity' => 'critical', 'sla_due_at' => now()->subDay()]);
        $this->issue(['status' => 'verified', 'created_at' => now()->subDays(10), 'remediated_at' => now()->subDays(5), 'sla_due_at' => now()->subDays(4)]);
        $this->issue(['status' => 'verified', 'created_at' => now()->subDays(10), 'remediated_at' => now()->subDays(2), 'sla_due_at' => now()->subDays(4)]);
        $this->issue([], $this->other);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('issues.stats.open', 1)->where('issues.stats.overdue', 1)
                ->where('issues.stats.open_critical', 1)->where('issues.stats.sla_compliance_pct', 50)
                ->has('issues.overdue', 1));
    }
}
