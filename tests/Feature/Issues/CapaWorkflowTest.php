<?php

namespace Tests\Feature\Issues;

use App\Models\Capa\CapaAction;
use App\Models\Incident;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CapaCriticalNotification;
use App\Services\Issues\CapaMetrics;
use App\Services\Issues\CapaWorkflow;
use App\Services\Issues\IssueWorkflow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Integrated CAPA (IT-GOV-CAPA-001) on the Issues register: each stage gate, the RACI sign-off,
 * the independent verifier and settling period, the failed-verification loop, ITIL recurrence,
 * the CBN CPR §6.0 incident auto-trigger, tenant isolation, KPIs, the CSV register and the 48 h
 * critical SLA with its 2 h escalation.
 */
class CapaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $other;

    private User $admin;      // Organization Admin, CISO: IT governance + infosec

    private User $grc;        // Compliance Officer: IT governance (Accountable for planning)

    private User $analyst;    // Security Analyst: infosec (Responsible)

    private User $auditor;    // Auditor: internal audit (Accountable for verification)

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->org = Organization::factory()->create();
        $this->other = Organization::factory()->create();
        $this->admin = $this->user('Organization Admin', ['job_title' => 'CISO']);
        $this->grc = $this->user('Compliance Officer', ['job_title' => 'Head of Compliance']);
        $this->analyst = $this->user('Security Analyst', ['job_title' => 'Security Operations Analyst']);
        $this->auditor = $this->user('Auditor', ['job_title' => 'Head of Internal Audit']);
    }

    private function user(string $role, array $attrs = [], ?Organization $org = null): User
    {
        $user = User::factory()->create($attrs + ['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);

        return $user;
    }

    private function issue(array $attrs = [], ?Organization $org = null): Issue
    {
        return Issue::withoutGlobalScopes()->create($attrs + [
            'organization_id' => ($org ?? $this->org)->id,
            'issue_code' => 'ISS-'.fake()->unique()->numerify('####'),
            'source_type' => 'audit',
            'source_reference' => 'CBN examination 2026, observation 4',
            'title' => 'Core banking patch latency',
            'severity' => 'high',
            'status' => 'open',
            'sla_minutes' => 336 * 60,
            'sla_due_at' => now()->addDays(14),
        ]);
    }

    /** Raise a CAPA through the console. */
    private function raiseCapa(array $attrs = []): Issue
    {
        $this->actingAs($this->admin)->post(route('issues.store'), $attrs + [
            'title' => 'Finacle EOD batch failures',
            'severity' => 'high',
            'source_type' => 'audit',
            'source_reference' => 'IA-2026-09 finding 2',
            'owner_id' => $this->analyst->id,
            'capa_framework' => 'iso27001',
        ])->assertSessionHasNoErrors();

        return Issue::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function advance(Issue $issue, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post(route('issues.capa.stage', $issue));
    }

    /** Walk a CAPA to the given stage through the real endpoints. */
    private function walkTo(Issue $issue, string $stage): Issue
    {
        $steps = ['identification', 'containment', 'rca', 'action_planning', 'implementation', 'verification'];
        foreach ($steps as $from) {
            if ($issue->fresh()->capa_stage === $stage) {
                break;
            }
            match ($from) {
                'identification' => $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), [
                    'capa_classification' => array_key_first(config('capa.frameworks.'.$issue->fresh()->capa_framework.'.classifications')),
                    'cbn_provisions' => ['cbn_itsb_3_5'],
                ]),
                'containment' => $this->actingAs($this->analyst)->post(route('issues.capa.containment.store', $issue), ['action' => 'Failed batch node isolated.']),
                'rca' => $this->actingAs($this->analyst)->put(route('issues.capa.root-cause', $issue), [
                    'method' => 'five_whys', 'five_whys' => ['Batch failed', 'Disk full', 'No log rotation'],
                    'root_cause_summary' => 'Log rotation missing from the EOD server baseline.',
                ]),
                'action_planning' => (function () use ($issue) {
                    $this->actingAs($this->admin)->post(route('issues.capa.actions.store', $issue), [
                        'title' => 'Add log rotation to baseline', 'owner_id' => $this->analyst->id, 'due_date' => now()->addDays(10)->toDateString(),
                    ]);
                    $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['cbn_correlation' => 'Fixes CBN ITSB §3.5 on persistent downtime.']);
                    $this->actingAs($this->grc)->post(route('issues.capa.signoff', $issue));
                })(),
                'implementation' => CapaAction::withoutGlobalScopes()->where('issue_id', $issue->id)->get()
                    ->each(fn ($a) => $this->actingAs($this->admin)->put(route('issues.capa.actions.update', [$issue, $a]), ['status' => 'done'])),
                default => null,
            };
            $this->advance($issue)->assertSessionHasNoErrors();
        }

        return $issue->fresh();
    }

    private function allCriteria(Issue $issue): array
    {
        return collect(config('capa.frameworks.'.$issue->capa_framework.'.verification_criteria'))->map(fn () => true)->all();
    }

    public function test_identification_gate_needs_framework_classification_and_a_cbn_provision(): void
    {
        $issue = $this->raiseCapa();
        $this->assertSame('identification', $issue->capa_stage);
        $this->assertSame('open', $issue->status);
        $this->assertSame(30, $issue->settling_days);

        $this->advance($issue)->assertSessionHasErrors('capa');
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['capa_classification' => 'risk_event'])
            ->assertSessionHasErrors('capa_classification');
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['capa_classification' => 'nonconformity', 'framework_reference' => 'iso27001_10_2'])
            ->assertSessionHasNoErrors();
        $this->advance($issue)->assertSessionHasErrors('capa');

        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['cbn_provisions' => ['bogus']])->assertSessionHasErrors('cbn_provisions.0');
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['cbn_provisions' => ['cbn_rbcsf_3_1_5']])->assertSessionHasNoErrors();
        $this->advance($issue)->assertSessionHasNoErrors();
        $this->assertSame('containment', $issue->fresh()->capa_stage);
        $this->assertSame('open', $issue->fresh()->status);

        // The path is fixed once identification is done.
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['capa_framework' => 'cobit2019'])->assertSessionHasErrors('capa_framework');
    }

    public function test_containment_and_rca_gates(): void
    {
        $issue = $this->walkTo($this->raiseCapa(), 'containment');
        $this->advance($issue)->assertSessionHasErrors('capa');
        $this->actingAs($this->analyst)->post(route('issues.capa.containment.store', $issue), ['action' => 'Temporary firewall rule applied.'])->assertSessionHasNoErrors();
        $this->advance($issue)->assertSessionHasNoErrors();
        $issue->refresh();
        $this->assertSame('rca', $issue->capa_stage);
        $this->assertSame('in_progress', $issue->status);
        $this->assertNotNull($issue->containment_completed_at);

        // Two whys is not an analysis; a fishbone cause without a summary is not either.
        $this->actingAs($this->analyst)->put(route('issues.capa.root-cause', $issue), ['method' => 'five_whys', 'five_whys' => ['a', 'b'], 'root_cause_summary' => 'x']);
        $this->advance($issue)->assertSessionHasErrors('capa');
        $this->actingAs($this->analyst)->put(route('issues.capa.root-cause', $issue), ['method' => 'fishbone', 'fishbone' => ['technology' => ['No log rotation']]]);
        $this->advance($issue)->assertSessionHasErrors('capa');
        $this->actingAs($this->analyst)->put(route('issues.capa.root-cause', $issue), [
            'method' => 'fishbone', 'fishbone' => ['technology' => ['No log rotation']], 'root_cause_summary' => 'Baseline gap.',
        ]);
        $this->advance($issue)->assertSessionHasNoErrors();
        $issue->refresh();
        $this->assertSame('action_planning', $issue->capa_stage);
        $this->assertSame('Baseline gap.', $issue->root_cause);
        $this->assertNotNull($issue->rca_completed_at);
    }

    public function test_action_planning_needs_owned_dated_actions_cbn_correlation_and_accountable_signoff(): void
    {
        $issue = $this->walkTo($this->raiseCapa(), 'action_planning');
        $this->advance($issue)->assertSessionHasErrors('capa');

        $this->actingAs($this->admin)->post(route('issues.capa.actions.store', $issue), ['title' => 'Patch EOD servers'])->assertSessionHasNoErrors();
        $action = CapaAction::withoutGlobalScopes()->where('issue_id', $issue->id)->firstOrFail();
        $this->assertSame($issue->issue_code.'-A1', $action->action_code);
        $this->assertStringContainsString('owner and a due date', implode(' ', app(CapaWorkflow::class)->blockers($issue->fresh())));

        $this->actingAs($this->admin)->put(route('issues.capa.actions.update', [$issue, $action]), ['owner_id' => $this->analyst->id, 'due_date' => now()->addWeek()->toDateString()]);
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['cbn_correlation' => 'Fixes CBN ITSB §3.5.']);

        // RACI: IT Governance is Accountable for planning; infosec is only Responsible.
        $this->actingAs($this->analyst)->post(route('issues.capa.signoff', $issue))->assertSessionHasErrors('capa');
        $this->advance($issue)->assertSessionHasErrors('capa');
        $this->actingAs($this->grc)->post(route('issues.capa.signoff', $issue), ['note' => 'Plan approved.'])->assertSessionHasNoErrors();
        $this->assertSame($this->grc->id, $issue->fresh()->stage_signoffs['action_planning']['user_id']);

        // Changing the plan withdraws the sign-off.
        $this->actingAs($this->admin)->put(route('issues.capa.actions.update', [$issue, $action]), ['due_date' => now()->addDays(9)->toDateString()]);
        $this->assertArrayNotHasKey('action_planning', $issue->fresh()->stage_signoffs ?? []);
        $this->advance($issue)->assertSessionHasErrors('capa');

        $this->actingAs($this->grc)->post(route('issues.capa.signoff', $issue));
        $this->advance($issue)->assertSessionHasNoErrors();
        $issue->refresh();
        $this->assertSame('implementation', $issue->capa_stage);
        $this->assertNotNull($issue->plan_approved_at);
        $this->assertStringContainsString('Patch EOD servers', $issue->remediation_plan);
    }

    public function test_implementation_needs_every_action_done_and_starts_the_settling_period(): void
    {
        $issue = $this->walkTo($this->raiseCapa(['settling_days' => 45]), 'implementation');
        $this->advance($issue)->assertSessionHasErrors('capa');

        // The action's owner may work it without edit issues rights over the CAPA.
        $action = CapaAction::withoutGlobalScopes()->where('issue_id', $issue->id)->firstOrFail();
        $this->actingAs($this->analyst)->put(route('issues.capa.actions.update', [$issue, $action]), ['status' => 'done'])->assertSessionHasNoErrors();
        $this->assertNotNull($action->fresh()->completed_at);

        $this->advance($issue)->assertSessionHasNoErrors();
        $issue->refresh();
        $this->assertSame('verification', $issue->capa_stage);
        $this->assertSame('remediated', $issue->status);
        $this->assertSame($this->admin->id, $issue->remediated_by_id);
        $this->assertEqualsWithDelta(now()->addDays(45)->timestamp, $issue->verification_due_at->timestamp, 5);
    }

    public function test_settling_period_bounds(): void
    {
        $issue = $this->raiseCapa();
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['settling_days' => 20])->assertSessionHasErrors('settling_days');
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['settling_days' => 91])->assertSessionHasErrors('settling_days');
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['settling_days' => 90])->assertSessionHasNoErrors();
        $this->assertSame(90, $issue->fresh()->settling_days);
    }

    public function test_verification_is_independent_waits_for_settling_and_needs_every_criterion(): void
    {
        $issue = $this->walkTo($this->raiseCapa(), 'verification');
        $criteria = $this->allCriteria($issue);

        // Not internal audit.
        $this->actingAs($this->grc)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $criteria])->assertSessionHasErrors('capa');
        // An auditor who owns a corrective action is not independent.
        $conflicted = $this->user('Auditor');
        CapaAction::withoutGlobalScopes()->where('issue_id', $issue->id)->update(['owner_id' => $conflicted->id]);
        $this->actingAs($conflicted)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $criteria])->assertSessionHasErrors('capa');
        // Nor is the issue owner, even with the auditor role.
        $this->analyst->assignRole('Auditor');
        $this->actingAs($this->analyst)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $criteria])->assertSessionHasErrors('capa');

        // Settling period not over yet.
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $criteria])->assertSessionHasErrors('capa');
        $this->actingAs($this->auditor)->get(route('issues.show', $issue))
            ->assertInertia(fn (Assert $p) => $p->where('capa.can_verify', true)->where('capa.verification_open', false));

        $this->travel(31)->days();
        $partial = $criteria;
        $partial[array_key_first($partial)] = false;
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $partial])->assertSessionHasErrors('criteria');
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $criteria, 'note' => 'Risk within appetite.'])
            ->assertSessionHasNoErrors();

        $issue->refresh();
        $this->assertSame('closed', $issue->capa_stage);
        $this->assertSame('closed', $issue->status);
        $this->assertSame($this->auditor->id, $issue->verified_by_id);
        $this->assertNotNull($issue->closed_at);
        $this->assertTrue($issue->verification_criteria['risk_within_threshold']);
        // A closed CAPA is locked; the legacy reopen is not available to it.
        $this->actingAs($this->admin)->put(route('issues.capa.update', $issue), ['cbn_provisions' => ['cbn_cpr_6_0']])->assertSessionHasErrors('capa');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'reopen', 'note' => 'x'])->assertSessionHasErrors('action');
    }

    public function test_failed_verification_returns_to_implementation(): void
    {
        $issue = $this->walkTo($this->raiseCapa(), 'verification');
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $issue), ['outcome' => 'fail'])->assertSessionHasErrors('note');
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $issue), ['outcome' => 'fail', 'note' => 'EOD failed again in week 2.'])
            ->assertSessionHasNoErrors();

        $issue->refresh();
        $this->assertSame('implementation', $issue->capa_stage);
        $this->assertSame('in_progress', $issue->status);
        $this->assertNull($issue->implemented_at);
        $this->assertNull($issue->verification_due_at);
        $this->assertNull($issue->remediated_by_id);
        $this->assertTrue($issue->events()->where('event_type', 'capa_verification')->exists());
    }

    public function test_itil_closure_is_refused_when_the_problem_has_recurred(): void
    {
        $issue = $this->walkTo($this->raiseCapa(['capa_framework' => 'itilv4']), 'verification');
        $this->travel(31)->days();

        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'EOD failure again', 'severity' => 'high', 'source_type' => 'audit', 'source_reference' => 'Ops log',
            'recurrence_of_id' => $issue->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $issue), ['outcome' => 'pass', 'criteria' => $this->allCriteria($issue)])
            ->assertSessionHasErrors('capa');
        $this->assertSame('verification', $issue->fresh()->capa_stage);

        // An incident tagged against the problem also counts.
        $tagged = $this->walkTo($this->raiseCapa(['capa_framework' => 'itilv4']), 'verification');
        $this->travel(31)->days();
        Incident::factory()->create(['organization_id' => $this->org->id, 'assigned_to' => null, 'lead_investigator_id' => null,
            'detected_at' => now(), 'tags' => ['capa:'.$tagged->issue_code]]);
        $this->actingAs($this->auditor)->post(route('issues.capa.verify', $tagged), ['outcome' => 'pass', 'criteria' => $this->allCriteria($tagged)])
            ->assertSessionHasErrors('capa');

        $show = $this->actingAs($this->admin)->get(route('issues.show', $issue));
        $show->assertInertia(fn (Assert $p) => $p->has('recurrence.later', 1)->where('recurrence.of', null));
    }

    public function test_legacy_transitions_are_closed_to_a_capa_but_blocking_still_pauses_it(): void
    {
        $issue = $this->walkTo($this->raiseCapa(), 'rca');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'remediate'])->assertSessionHasErrors('action');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'block', 'note' => 'Vendor RCA pending.'])->assertSessionHasNoErrors();
        $this->advance($issue)->assertSessionHasErrors('capa');
        $this->actingAs($this->admin)->post(route('issues.transition', $issue), ['action' => 'unblock'])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $issue->fresh()->status);

        // Unblocking during containment goes back to open, not in progress.
        $early = $this->walkTo($this->raiseCapa(), 'containment');
        $this->actingAs($this->admin)->post(route('issues.transition', $early), ['action' => 'block', 'note' => 'Waiting on DC access.']);
        $this->actingAs($this->admin)->post(route('issues.transition', $early), ['action' => 'unblock']);
        $this->assertSame('open', $early->fresh()->status);
    }

    public function test_customer_impacting_incident_opens_one_capa(): void
    {
        Notification::fake();
        $cio = $this->user('Viewer', ['job_title' => 'Chief Information Officer']);
        $committee = $this->user('Viewer', ['job_title' => 'Board Risk Committee Chair']);
        $foreignCiso = $this->user('Organization Admin', ['job_title' => 'CISO'], $this->other);

        $plain = Incident::factory()->create(['organization_id' => $this->org->id, 'assigned_to' => null, 'lead_investigator_id' => null, 'severity' => 'high']);
        $this->assertSame(0, Issue::withoutGlobalScopes()->where('source_type', 'incident')->count());

        $incident = Incident::factory()->create(['organization_id' => $this->org->id, 'assigned_to' => $this->analyst->id,
            'lead_investigator_id' => null, 'severity' => 'critical', 'customer_impact' => true, 'title' => 'Mobile app debits customers twice']);
        $incident->update(['status' => 'containing']);
        $incident->update(['description' => 'More detail']);

        $capas = Issue::withoutGlobalScopes()->where('source_type', 'incident')->where('source_id', $incident->id)->get();
        $this->assertCount(1, $capas);
        $capa = $capas->first();
        $this->assertSame($this->org->id, $capa->organization_id);
        $this->assertSame(['cbn_cpr_6_0'], $capa->cbn_provisions);
        $this->assertSame('itilv4', $capa->capa_framework);
        $this->assertSame('problem_statement', $capa->capa_classification);
        $this->assertSame('critical', $capa->severity);
        $this->assertSame($this->analyst->id, $capa->owner_id);
        $this->assertSame(48 * 60, $capa->sla_minutes);
        $this->assertNotNull($capa->critical_notified_at);
        Notification::assertSentTo([$cio, $committee, $this->admin], CapaCriticalNotification::class);
        Notification::assertNotSentTo($foreignCiso, CapaCriticalNotification::class);

        // Flagging an existing incident later opens it too (high, since not critical); toggle off stops it.
        $plain->update(['customer_impact' => true]);
        $this->assertSame('high', Issue::withoutGlobalScopes()->where('source_type', 'incident')->where('source_id', $plain->id)->value('severity'));
        config(['capa.auto_trigger.incident_customer_impact' => false]);
        $off = Incident::factory()->create(['organization_id' => $this->org->id, 'assigned_to' => null, 'lead_investigator_id' => null, 'customer_impact' => true]);
        $this->assertFalse(Issue::withoutGlobalScopes()->where('source_type', 'incident')->where('source_id', $off->id)->exists());
    }

    public function test_capas_run_on_their_own_sla_windows_not_the_bank_vulnerability_policy(): void
    {
        // The bank's (shared vulnerability) policy says 72 h critical / 240 h high.
        foreach (['critical' => 72, 'high' => 240] as $severity => $hours) {
            DB::table('vulnerability_sla_policies')->insert(['organization_id' => $this->org->id, 'severity' => $severity, 'hours_to_remediate' => $hours, 'created_at' => now(), 'updated_at' => now()]);
        }
        $raise = fn (array $attrs) => $this->actingAs($this->admin)->post(route('issues.store'), $attrs + [
            'title' => 'ATM firmware patch latency', 'severity' => 'critical', 'source_type' => 'audit', 'source_reference' => 'IA-2026-11',
        ])->assertSessionHasNoErrors();

        $raise([]);
        $this->assertSame(72 * 60, Issue::withoutGlobalScopes()->latest('id')->value('sla_minutes'), 'legacy issues keep the bank policy');

        $raise(['capa_framework' => 'iso27001']);
        $capa = Issue::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(48 * 60, $capa->sla_minutes);
        $this->assertEqualsWithDelta($capa->created_at->copy()->addHours(48)->timestamp, $capa->sla_due_at->timestamp, 5);

        // Severity changes on a CAPA stay on the CAPA windows (high = 14 days, not the policy's 240 h).
        app(IssueWorkflow::class)->changeSeverity($capa, 'high', $this->admin);
        $this->assertSame(336 * 60, $capa->fresh()->sla_minutes);

        // Converting a legacy issue re-bases its clock from when it was raised.
        $legacy = $this->issue(['severity' => 'moderate', 'sla_minutes' => 720 * 60, 'created_at' => now()->subDays(2)]);
        $this->actingAs($this->admin)->put(route('issues.capa.update', $legacy), ['capa_framework' => 'cobit2019'])->assertSessionHasNoErrors();
        $legacy->refresh();
        $this->assertSame(720 * 60, $legacy->sla_minutes);
        $legacy->update(['severity' => 'low']);
        app(IssueWorkflow::class)->applyCapaSla($legacy, null);
        $this->assertSame(2160 * 60, $legacy->fresh()->sla_minutes);
        $this->assertEqualsWithDelta(now()->subDays(2)->addHours(2160)->timestamp, $legacy->fresh()->sla_due_at->timestamp, 5);
    }

    public function test_critical_sla_is_48h_and_critical_capas_escalate_once(): void
    {
        Notification::fake();
        $this->user('Viewer', ['job_title' => 'Chief Information Officer']);

        $this->actingAs($this->admin)->post(route('issues.store'), [
            'title' => 'Core banking outage', 'severity' => 'critical', 'source_type' => 'audit', 'source_reference' => 'Major outage MO-12',
        ])->assertSessionHasNoErrors();
        $legacy = Issue::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(48 * 60, $legacy->sla_minutes);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $legacy->sla_due_at->timestamp, 5);
        $this->assertNull($legacy->critical_notified_at);

        // A critical issue raised before CAPA, later classified, is caught by the sweep within the hour.
        $capa = $this->issue(['severity' => 'critical', 'capa_framework' => 'iso27001', 'capa_stage' => 'identification']);
        $this->assertSame(1, app(IssueWorkflow::class)->escalateOverdue());
        $this->assertNotNull($capa->fresh()->critical_notified_at);
        $this->assertSame(0, app(IssueWorkflow::class)->escalateOverdue());
        Notification::assertSentTo($this->admin, CapaCriticalNotification::class);

        // Raising a severity to critical on a CAPA notifies straight away.
        $raised = $this->raiseCapa();
        $this->actingAs($this->admin)->put(route('issues.update', $raised), ['title' => $raised->title, 'severity' => 'critical'])->assertSessionHasNoErrors();
        $this->assertNotNull($raised->fresh()->critical_notified_at);
    }

    public function test_capa_records_and_register_are_tenant_isolated(): void
    {
        $theirs = $this->issue(['capa_framework' => 'iso27001', 'capa_stage' => 'containment', 'title' => 'Their CAPA'], $this->other);
        $theirAdmin = $this->user('Organization Admin', [], $this->other);
        $this->actingAs($theirAdmin)->post(route('issues.capa.containment.store', $theirs), ['action' => 'Isolated host']);
        $theirAction = $theirs->containmentActions()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->other->id, $theirAction->organization_id);

        $ours = $this->walkTo($this->raiseCapa(), 'containment');
        $this->actingAs($this->admin)->post(route('issues.capa.stage', $theirs))->assertNotFound();
        $this->actingAs($this->admin)->post(route('issues.capa.containment.store', $theirs), ['action' => 'x'])->assertNotFound();
        $this->actingAs($this->admin)->delete(route('issues.capa.containment.destroy', [$ours, $theirAction->id]))->assertNotFound();
        $this->actingAs($this->admin)->post(route('issues.capa.actions.store', $ours), ['title' => 'x', 'owner_id' => $theirAdmin->id])->assertSessionHasErrors('owner_id');

        $csv = $this->actingAs($this->admin)->get(route('issues.capa.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($ours->issue_code, $csv);
        $this->assertStringNotContainsString('Their CAPA', $csv);
        $this->actingAs($this->admin)->get(route('issues.index'))
            ->assertInertia(fn (Assert $p) => $p->where('capaKpis.total', 1));
    }

    public function test_kpis_overdue_rate_recurrence_rate_and_mttr(): void
    {
        $base = ['capa_framework' => 'iso27001', 'capa_classification' => 'nonconformity'];
        // Closed 3 months ago after 10 days to implement; recurred 1 month later.
        $a = $this->issue($base + ['capa_stage' => 'closed', 'status' => 'closed', 'created_at' => now()->subMonths(5),
            'implemented_at' => now()->subMonths(5)->addDays(10), 'closed_at' => now()->subMonths(3)]);
        $this->issue($base + ['recurrence_of_id' => $a->id, 'created_at' => now()->subMonths(2)]);
        // Closed, no recurrence; 20 days to implement.
        $this->issue($base + ['capa_stage' => 'closed', 'status' => 'closed', 'created_at' => now()->subMonths(4),
            'implemented_at' => now()->subMonths(4)->addDays(20), 'closed_at' => now()->subMonth()]);
        // In verification, settling over.
        $v = $this->issue(['capa_framework' => 'cobit2019', 'capa_stage' => 'verification', 'status' => 'remediated',
            'created_at' => now()->subDays(60), 'implemented_at' => now()->subDays(45), 'verification_due_at' => now()->subDays(15)]);
        // Not a CAPA: ignored.
        $this->issue(['status' => 'closed', 'closed_at' => now()->subMonth()]);

        $mk = fn (Issue $i, array $attrs) => CapaAction::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $this->org->id, 'issue_id' => $i->id, 'action_code' => 'A'.fake()->unique()->numberBetween(1, 9999), 'title' => 'Task',
        ]);
        $mk($v, ['status' => 'done', 'due_date' => now()->subDays(50), 'completed_at' => now()->subDays(48)]);   // done late → overdue
        $mk($v, ['status' => 'done', 'due_date' => now()->subDays(50), 'completed_at' => now()->subDays(55)]);   // on time
        $mk($v, ['status' => 'in_progress', 'due_date' => now()->subDay()]);                                      // open, past due → overdue
        $mk($v, ['status' => 'planned', 'due_date' => now()->addDays(5)]);                                        // on time
        $mk($v, ['status' => 'cancelled', 'due_date' => now()->subDays(80)]);                                     // excluded
        $mk($this->issue(['capa_framework' => 'iso27001'], $this->other), ['organization_id' => $this->other->id, 'status' => 'planned', 'due_date' => now()->subDays(9)]);

        $this->actingAs($this->admin);
        $k = app(CapaMetrics::class)->summary();
        $this->assertSame(4, $k['actions_total']);
        $this->assertSame(2, $k['actions_overdue']);
        $this->assertEquals(50.0, $k['overdue_rate_pct']);
        $this->assertSame(2, $k['closed_12m']);
        $this->assertSame(1, $k['recurred_12m']);
        $this->assertEquals(50.0, $k['recurrence_rate_pct']);
        $this->assertEquals(15.0, $k['mttr_days']);
        $this->assertSame(1, $k['verifications_due']);
        $this->assertSame(['iso27001' => 3, 'cobit2019' => 1, 'itilv4' => 0], $k['by_framework']);
        $this->assertSame(2, $k['by_stage']['closed']);

        $this->get(route('issues.index', ['framework' => 'cobit2019']))
            ->assertInertia(fn (Assert $p) => $p->has('issues', 1)->where('issues.0.capa_stage', 'verification')->where('capaKpis.verifications_due', 1));
        $this->get(route('issues.index', ['stage' => 'closed']))->assertInertia(fn (Assert $p) => $p->has('issues', 2));
    }

    public function test_csv_register_export(): void
    {
        $issue = $this->walkTo($this->raiseCapa(['title' => '=HYPERLINK("http://x")', 'severity' => 'moderate']), 'rca');
        $viewer = $this->user('Viewer');
        $this->actingAs($viewer)->get(route('issues.capa.export'))->assertForbidden();

        $response = $this->actingAs($this->auditor)->get(route('issues.capa.export', ['framework' => 'iso27001']))->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('CAPA,Title,Severity,Framework', $lines[0]);
        $row = str_getcsv($lines[1]);
        $this->assertSame($issue->issue_code, $row[0]);
        $this->assertSame("'=HYPERLINK(\"http://x\")", $row[1]);
        $this->assertSame('Medium', $row[2]);
        $this->assertSame('ISO/IEC 27001:2022', $row[3]);
        $this->assertSame('CBN IT Standards Blueprint §3.5', $row[6]);
        $this->assertSame('Root cause analysis', $row[8]);

        $this->assertCount(1, array_filter(explode("\n", $this->actingAs($this->auditor)->get(route('issues.capa.export', ['framework' => 'itilv4']))->streamedContent())));
    }

    public function test_show_carries_the_capa_props_contract(): void
    {
        $issue = $this->walkTo($this->raiseCapa(), 'action_planning');
        $this->actingAs($this->grc)->get(route('issues.show', $issue))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Issues/Show')
                ->where('capa.enabled', true)
                ->where('capa.stage', 'action_planning')
                ->has('capa.stages', 7)
                ->where('capa.stages.0.status', 'done')
                ->where('capa.stages.3.status', 'current')
                ->where('capa.stages.4.status', 'pending')
                ->where('capa.can_sign_off', true)
                ->where('capa.gate.can_advance', false)
                ->where('capa.gate.next_stage', 'implementation')
                ->has('capa.verification_criteria', 4)
                ->has('capa.raci', 6)
                ->has('containmentActions', 1)
                ->where('rootCause.complete', true)
                // The RCA relation must not serialise over the issue's root_cause text column.
                ->where('issue.root_cause', fn ($v) => $v === null || is_string($v))
                ->has('capaActions', 0)
                ->has('capaConfig.frameworks', 3)
                ->has('capaConfig.cbnProvisions', 4));

        // A legacy issue still renders, with the CAPA panel disabled.
        $this->actingAs($this->admin)->get(route('issues.show', $this->issue()))
            ->assertInertia(fn (Assert $p) => $p->where('capa.enabled', false)->where('capa.stages', [])->where('rootCause', null));
    }
}
