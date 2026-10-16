<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalDecision;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalWorkflow;
use App\Models\Approvals\ApprovalWorkflowStage;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\NotificationLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Finance\FinanceApprovalOverdueNotification;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Services\Approvals\ApprovalProcesses;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceApprovalSweeper;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\SupplementaryBudgetService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Finance\Concerns\BuildsBudgets;
use Tests\TestCase;

/** Workflow Studio, approval notifications and sweeps for the finance processes (ATH-FIN-001 §6/§7). */
class FinanceWorkflowStudioTest extends TestCase
{
    use BuildsBudgets, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create(['currency' => 'NGN']);
        $this->admin = $this->user('Organization Admin', ['job_title' => 'Chief Financial Officer']);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
    }

    private function user(string $role, array $attrs = []): User
    {
        return tap(User::factory()->create(['organization_id' => $this->org->id] + $attrs), fn ($u) => $u->assignRole($role));
    }

    public function test_defaults_seed_per_process_idempotently_with_band_conditions(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        FinancePolicySeeder::ensureDefaults($this->org->id);

        $wfs = ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'like', 'finance.%')->with('stages')->get()->keyBy('process');
        $this->assertCount(4, $wfs);
        $this->assertCount(4, $wfs['finance.budget_approval']->stages);
        $this->assertCount(3, $wfs['finance.budget_transfer']->stages);
        $this->assertCount(3, $wfs['finance.supplementary_budget']->stages);
        $this->assertCount(2, $wfs['finance.contract_renewal']->stages);

        $md = $wfs['finance.budget_approval']->stages->firstWhere('sequence', 40);
        $this->assertSame('amount_band', $md->condition_field);
        $this->assertNotEmpty($md->condition_values);
        $this->assertSame($md->condition_values, FinancePolicySeeder::bandsFrom($this->org->id, config('finance.approval_defaults.budget_md_ceo_from')));

        // a bank that removed its stages stays unconfigured
        $wfs['finance.budget_transfer']->stages()->delete();
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $this->assertSame(0, $wfs['finance.budget_transfer']->stages()->count());
    }

    public function test_studio_lists_and_shows_finance_processes_with_resolved_conditions_and_accepts_band_values(): void
    {
        $this->actingAs($this->admin)->get(route('workflows.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('processes.IT Financial Management', 4));

        $this->get(route('workflows.process', 'finance.budget_approval'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Workflows/Process')->has('stages', 4)
            ->where('process.conditions.0.field', 'amount_band')
            ->has('process.conditions.0.values'));

        $wf = ApprovalWorkflow::where('process', 'finance.budget_approval')->first();
        $band = FinancePolicySeeder::bandsFrom($this->org->id, 0)[0];
        $this->post(route('workflows.stages.store', 'finance.budget_approval'), [
            'name' => 'Extra', 'approver_type' => 'role', 'approver_ref' => 'Finance Approver', 'min_approvals' => 1, 'sla_hours' => 24,
            'condition_field' => 'amount_band', 'condition_values' => [$band],
        ])->assertSessionHasNoErrors();
        $this->assertSame([$band], $wf->stages()->where('name', 'Extra')->first()->condition_values);

        $this->post(route('workflows.stages.store', 'finance.budget_approval'), [
            'name' => 'Bad', 'approver_type' => 'role', 'approver_ref' => 'Finance Approver', 'min_approvals' => 1, 'sla_hours' => 24,
            'condition_field' => 'amount_band', 'condition_values' => ['no-such-band'],
        ])->assertSessionHasErrors('condition_values');
    }

    public function test_non_finance_processes_still_validate_array_conditions(): void
    {
        $this->actingAs($this->admin);
        $process = collect(ApprovalProcesses::generic())->filter(fn ($d, $k) => ! str_starts_with($k, 'finance.') && ! empty($d['conditions']))->keys()->first();
        if (! $process) {
            $this->markTestSkipped('No non-finance process with conditions.');
        }
        $this->get(route('workflows.process', $process))->assertOk();
    }

    private function submitBudget(User $owner, User $submitter): Budget
    {
        $this->actingAs($submitter);
        $fy = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2027', 'starts_on' => '2027-01-01', 'status' => 'open', 'is_current' => true], $submitter);
        $cc = CostCentre::create(['code' => 'CC-1', 'name' => 'Ops', 'owner_id' => $owner->id]);
        $cycles = app(BudgetCycleService::class);
        $cycle = $cycles->create($this->org->id, ['fiscal_year_id' => $fy->id, 'name' => 'FY27', 'type' => 'annual', 'opens_on' => '2026-09-01', 'closes_on' => '2026-11-30'], $submitter);
        $cycles->transition($cycle, 'open', $submitter);
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($cycle, $cc, 'zero_based', $owner);
        $svc->addLine($b, [
            'description' => 'AMC', 'cost_pool_id' => Category::where('code', 'CP-SW')->value('id'), 'tower_id' => Category::where('code', 'TW-APP')->value('id'),
            'annual_amount' => '1000000', 'currency' => 'NGN', 'phasing_method' => 'seasonal',
        ], $owner);
        $svc->submit($b->refresh(), $owner);

        return $b->refresh();
    }

    public function test_submit_notifies_stage_approvers_and_sweep_is_deduplicated(): void
    {
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $owner = $this->user('IT Budget Owner');
        $headOfIt = $this->user('Finance Approver', ['job_title' => 'Head of IT']);
        Notification::fake();
        $b = $this->submitBudget($owner, $this->user('IT Finance Controller'));

        Notification::assertSentTo($headOfIt, FinanceApprovalRequestedNotification::class, function ($n) use ($b) {
            return $n->toArray($b)['module'] === 'IT Financial Management' && str_contains($n->toArray($b)['action_url'], 'tab=approvals');
        });
        Notification::assertNotSentTo($owner, FinanceApprovalRequestedNotification::class);

        // not overdue yet
        $this->assertSame(0, app(FinanceApprovalSweeper::class)->sweep());

        $this->travel(5)->days();
        $this->assertSame(1, app(FinanceApprovalSweeper::class)->sweep());
        Notification::assertSentTo($headOfIt, FinanceApprovalOverdueNotification::class);
        $this->assertGreaterThan(0, NotificationLog::withoutGlobalScopes()->where('notification_key', 'approval_overdue')->count());

        $this->assertSame(0, app(FinanceApprovalSweeper::class)->sweep());
        $this->travel(2)->days();
        $this->assertSame(1, app(FinanceApprovalSweeper::class)->sweep());
    }

    public function test_console_commands_are_registered_and_run(): void
    {
        $this->assertSame(0, Artisan::call('fin:approvals-sweep'));
        $this->assertSame(0, Artisan::call('fin:doa-activate'));
        $names = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description);
        $this->assertTrue($names->contains('finance.approvals-sweep'));
        $this->assertTrue($names->contains('finance.doa-activate'));
    }

    /* ------------------------------------------------------------------ budget processes (P1 QA) */

    private function fixture(bool $stages = true): void
    {
        $this->bootBudgets($this->org);
        $stages && FinancePolicySeeder::ensureDefaults($this->org->id);
    }

    private function approver(string $title, array $attrs = []): User
    {
        return $this->userWith('Finance Approver', ['job_title' => $title] + $attrs);
    }

    private function request(string $process, $subject): ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()->where('process', $process)->where('subject_id', $subject->id)->with('steps')->latest('id')->firstOrFail();
    }

    /** FIN-07.01: the index lists 15 processes; opening a finance process seeds its stages once and never re-seeds. */
    public function test_three_budget_processes_listed_with_defaults_seeded_on_open(): void
    {
        $this->actingAs($this->admin)->get(route('workflows.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('summary.processes', 15)->has('processes.IT Financial Management', 4));
        $count = fn () => ApprovalWorkflow::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('process', 'like', 'finance.%')->count();
        $this->assertSame(0, $count(), 'nothing is seeded until a bank opens the process or starts using it');

        $expected = ['finance.budget_approval' => 4, 'finance.budget_transfer' => 3, 'finance.supplementary_budget' => 3, 'finance.contract_renewal' => 2];
        foreach ($expected as $process => $stages) {
            $this->get(route('workflows.process', $process))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Workflows/Process')->has('stages', $stages));
        }
        $this->assertSame(4, $count());
        foreach ($expected as $process => $stages) {
            $this->get(route('workflows.process', $process))->assertInertia(fn (Assert $p) => $p->has('stages', $stages));
        }
        $this->assertSame(4, $count());
        $this->assertSame(12, ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());

        $wf = ApprovalWorkflow::where('process', 'finance.budget_transfer')->first();
        $this->delete(route('workflows.stages.destroy', $wf->stages()->first()))->assertSessionHasNoErrors();
        $wf->stages()->delete();
        $this->get(route('workflows.process', 'finance.budget_transfer'))->assertInertia(fn (Assert $p) => $p->has('stages', 0));
        $this->assertSame(0, $wf->stages()->count(), 'a bank that removed its stages is not re-seeded');
    }

    /** FIN-01.07: amount-band condition values are the bank's own DoA bands, and a stage cannot use another bank's key. */
    public function test_amount_band_condition_values_come_from_the_banks_doa_bands(): void
    {
        $this->fixture(false);
        $other = Organization::factory()->create(['currency' => 'NGN']);
        app(FinanceSettings::class)->ensureDefaults($other->id);
        $otherAdmin = $this->userWith('Organization Admin', [], $other->id);
        $otherChecker = $this->userWith('Finance Approver', [], $other->id);
        $svc = app(DoaBandService::class);
        $v = $svc->propose($other->id, [['label' => 'Small', 'min' => 0, 'max' => 5000000], ['label' => 'Large', 'min' => 5000000, 'max' => null]], today()->toDateString(), 'Own limits', $otherAdmin);
        $svc->approve($v, $otherChecker);

        $bands = fn (User $u, string $process) => $this->actingAs($u)->get(route('workflows.process', $process))->assertOk()->viewData('page')['props']['process']['conditions'];
        $mine = collect($bands($this->admin, 'finance.budget_approval'))->keyBy('field');
        $theirs = collect($bands($otherAdmin, 'finance.budget_approval'))->keyBy('field');
        $this->assertSame(['0-1m', '1m-10m', '10m-50m', '50m-250m', '250m+'], array_keys($mine['amount_band']['values']));
        $this->assertSame(['0-5m', '5m+'], array_keys($theirs['amount_band']['values']));
        $this->assertStringContainsString('NGN 5,000,000', $theirs['amount_band']['values']['5m+']);
        $this->assertEqualsCanonicalizing(['amount_band', 'cost_centre_id', 'cycle_type'], $mine->keys()->all());
        $this->assertContains('CC-1', array_map(fn ($l) => explode(' ', $l)[0], $mine['cost_centre_id']['values']));
        $this->assertEqualsCanonicalizing(['amount_band', 'crosses_cost_centre', 'capex_opex'], collect($bands($this->admin, 'finance.budget_transfer'))->pluck('field')->all());
        $this->assertEqualsCanonicalizing(['amount_band', 'category_id', 'capex_opex'], collect($bands($this->admin, 'finance.supplementary_budget'))->pluck('field')->all());

        $stage = fn (string $band) => ['name' => 'Band stage', 'approver_type' => 'role', 'approver_ref' => 'Finance Approver', 'min_approvals' => 1, 'sla_hours' => 24, 'condition_field' => 'amount_band', 'condition_values' => [$band]];
        $this->actingAs($this->admin)->post(route('workflows.stages.store', 'finance.budget_approval'), $stage('5m+'))->assertSessionHasErrors('condition_values');
        $this->post(route('workflows.stages.store', 'finance.budget_approval'), $stage('250m+'))->assertSessionHasNoErrors();
        $this->actingAs($otherAdmin)->post(route('workflows.stages.store', 'finance.budget_approval'), $stage('250m+'))->assertSessionHasErrors('condition_values');
        $this->post(route('workflows.stages.store', 'finance.budget_approval'), $stage('5m+'))->assertSessionHasNoErrors();
    }

    /** FIN-02.14, FIN-07.01: Head of IT, CFO, CIO in order; the MD/CEO stage only for the top band. */
    public function test_budget_runs_through_configured_stages_in_order(): void
    {
        $this->fixture();
        $svc = app(BudgetService::class);
        $headOfIt = $this->approver('Head of IT Governance');
        $cio = $this->approver('Chief Information Officer');
        $md = $this->approver('Managing Director/CEO');

        $small = $this->submitted($this->cc, '1200000.00');
        $request = $this->request('finance.budget_approval', $small);
        $this->assertSame(['Head of IT review', 'CFO approval', 'CIO approval'], $request->steps->sortBy('sequence')->pluck('name')->all());
        $this->assertSame('submitted', $small->status);

        $this->assertRefused(fn () => $svc->approve($small, $this->cfo), 'approval', 'Head of IT review');
        $this->assertRefused(fn () => $svc->approve($small, $cio), 'approval', 'Head of IT review');
        $this->assertStringContainsString('CFO approval', $svc->approve($small, $headOfIt));
        $this->assertSame('under_review', $small->refresh()->status);
        $this->assertRefused(fn () => $svc->approve($small->refresh(), $headOfIt), 'approval');
        $this->assertRefused(fn () => $svc->approve($small->refresh(), $cio), 'approval', 'CFO approval');
        $this->assertStringContainsString('CIO approval', $svc->approve($small->refresh(), $this->cfo));
        $this->assertSame('under_review', $small->refresh()->status);
        $this->assertStringContainsString('approved', $svc->approve($small->refresh(), $cio, 'Fine'));
        $this->assertSame('approved', $small->refresh()->status);
        $this->assertSame($cio->id, $small->approved_by_id);
        $this->assertSame('approved', $request->refresh()->status);
        $this->assertSame(3, $request->steps()->where('status', 'approved')->count());
        $svc->baseline($small->refresh(), $this->fin);
        $this->assertSame('baseline', $small->refresh()->status);

        // a top-band budget adds the MD/CEO stage after the CIO
        $big = $this->submitted($this->cc2, '300000000.00');
        $this->assertSame(['Head of IT review', 'CFO approval', 'CIO approval', 'MD/CEO approval'], $this->request('finance.budget_approval', $big)->steps->sortBy('sequence')->pluck('name')->all());
        foreach ([$headOfIt, $this->cfo, $cio] as $actor) {
            $svc->approve($big->refresh(), $actor);
            $this->assertSame('under_review', $big->refresh()->status);
        }
        $this->assertRefused(fn () => $svc->baseline($big->refresh(), $this->fin), 'workflow');
        $svc->approve($big->refresh(), $md);
        $this->assertSame('approved', $big->refresh()->status);

        // a rejection at stage 2 ends the request and the budget
        $third = CostCentre::create(['code' => 'CC-3', 'name' => 'Third', 'owner_id' => $this->owner->id]);
        $rej = $this->submitted($third, '5000000.00');
        $svc->approve($rej, $headOfIt);
        $this->assertRefused(fn () => $svc->reject($rej->refresh(), $this->cfo, ''), 'note');
        $svc->reject($rej->refresh(), $this->cfo, 'Over the ceiling');
        $this->assertSame('rejected', $rej->refresh()->status);
        $this->assertSame('rejected', $this->request('finance.budget_approval', $rej)->status);
    }

    /** FIN-07.02: owner, initiator, double-stage and cross-bank approvers are refused by the engine. */
    public function test_sod_owner_initiator_and_two_stage_rules(): void
    {
        $this->fixture();
        $svc = app(BudgetService::class);

        // a Head of IT who owns the cost centre holds no approve permission (the seeded SoD), so stage 1 cannot be theirs
        $ownerHead = $this->userWith('IT Budget Owner', ['job_title' => 'Head of IT Operations']);
        $cc = CostCentre::create(['code' => 'CC-H', 'name' => 'Head of IT cost centre', 'owner_id' => $ownerHead->id]);
        $b = $this->submitted($cc, '1000000.00');
        $this->assertRefused(fn () => $svc->approve($b, $ownerHead), 'approval', 'approve it-finance');
        $this->actingAs($ownerHead)->post(route('finance.budgets.approve', $b))->assertForbidden();

        // an owner who also holds the approver role is still refused as owner
        $ownerApprover = $this->userWith('Finance Approver', ['job_title' => 'Head of IT Operations']);
        $b->forceFill(['owner_id' => $ownerApprover->id])->save();
        $this->assertRefused(fn () => $svc->approve($b->refresh(), $ownerApprover), 'approval', 'own this record');

        // the initiator cannot approve what they submitted
        $b2 = $this->draft($this->cc2, '2000000.00');
        $initiator = $this->approver('Head of IT Governance');
        $svc->submit($b2, $initiator);
        $this->assertRefused(fn () => $svc->approve($b2->refresh(), $initiator), 'approval', 'started this approval');

        // one person cannot decide two stages
        $both = $this->approver('Head of IT and Chief Financial Officer');
        $b3 = $this->submitted($this->cc, '3000000.00');
        $svc->approve($b3, $both);
        $this->assertRefused(fn () => $svc->approve($b3->refresh(), $both), 'approval', 'already decided an earlier stage');
        $this->assertSame('under_review', $b3->refresh()->status);
        $this->assertRefused(fn () => $svc->returnToDraft($b3->refresh(), $both, 'back'), 'approval');

        // another bank's CFO is refused
        $otherOrg = Organization::factory()->create();
        $foreign = $this->userWith('Finance Approver', ['job_title' => 'Chief Financial Officer'], $otherOrg->id);
        $this->assertRefused(fn () => $svc->approve($b3->refresh(), $foreign), 'workflow', 'another bank');
        $this->assertSame(0, ApprovalDecision::withoutGlobalScopes()->where('user_id', $foreign->id)->count());
    }

    /** FIN-07.01: the CFO stage of a transfer applies only when it crosses cost centres. */
    public function test_transfer_cfo_stage_only_when_crossing_cost_centres(): void
    {
        $this->fixture(false);
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b1x = $this->baselined($this->cc2, '600000.00');
        FinancePolicySeeder::ensureDefaults($this->org->id); // stages apply from now on
        $a = $this->firstLine($b1);
        $x = $this->firstLine($b1x);
        $svc = app(BudgetTransferService::class);
        BudgetLine::$allowLockedAppend = true;
        $second = BudgetLine::withoutGlobalScopes()->forceCreate(['organization_id' => $this->org->id, 'budget_id' => $b1->id, 'line_no' => 9, 'origin' => 'transfer', 'description' => 'Sibling line',
            'cost_pool_id' => $a->cost_pool_id, 'tower_id' => $a->tower_id, 'cost_centre_id' => $a->cost_centre_id, 'currency' => 'NGN', 'fx_rate' => 1, 'fx_rate_type' => 'spot',
            'annual_amount' => 0, 'base_annual_amount' => 0, 'phasing_method' => 'equal']);
        BudgetLine::$allowLockedAppend = false;

        $within = $svc->create($this->transferFor($a, $second, '5000'), $this->owner);
        $svc->submit($within, $this->owner);
        $this->assertSame('no', $within->refresh()->crosses_cost_centre);
        $this->assertSame(['Finance Controller review'], $this->request('finance.budget_transfer', $within)->steps->pluck('name')->all());
        $svc->approve($within, $this->fin);
        $this->assertSame('approved', $within->refresh()->status);

        $cross = $svc->create($this->transferFor($a, $x, '5000'), $this->owner);
        $svc->submit($cross, $this->owner);
        $this->assertSame('yes', $cross->refresh()->crosses_cost_centre);
        $this->assertSame(['Finance Controller review', 'CFO approval (cross cost centre)'], $this->request('finance.budget_transfer', $cross)->steps->sortBy('sequence')->pluck('name')->all());
        $this->assertRefused(fn () => $svc->approve($cross->refresh(), $this->cfo), 'approval', 'Finance Controller review');
        $this->assertStringContainsString('CFO approval', $svc->approve($cross->refresh(), $this->fin));
        $this->assertSame('submitted', $cross->refresh()->status);
        $svc->approve($cross->refresh(), $this->cfo);
        $this->assertSame('approved', $cross->refresh()->status);
        $this->assertSame(0, BudgetAdjustment::count(), 'approving applies nothing');
    }

    private function transferFor(BudgetLine $from, BudgetLine $to, string $amount): array
    {
        return ['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $to->id, 'amount' => $amount, 'reason' => 'Test'];
    }

    /** FIN-07.01: the supplementary CIO stage applies only from the 50m band upwards. */
    public function test_supplementary_cio_stage_only_from_the_threshold_band(): void
    {
        $this->fixture(false);
        $this->baselined($this->cc, '1200000.00');
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(SupplementaryBudgetService::class);
        $make = fn (string $amount) => $svc->create(['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-SEC'),
            'category_id' => $this->cat('TW-SEC-SEC'), 'title' => 'Request '.$amount, 'currency' => 'NGN', 'amount' => $amount, 'justification' => 'Needed'], $this->owner);

        $small = $make('5000000');
        $svc->submit($small, $this->owner);
        $this->assertSame(['Head of IT review', 'CFO approval'], $this->request('finance.supplementary_budget', $small)->steps->sortBy('sequence')->pluck('name')->all());
        $big = $make('60000000');
        $svc->submit($big, $this->owner);
        $this->assertSame(['Head of IT review', 'CFO approval', 'CIO approval'], $this->request('finance.supplementary_budget', $big)->steps->sortBy('sequence')->pluck('name')->all());
        $this->assertSame('50m-250m', $this->request('finance.supplementary_budget', $big)->context['amount_band']);
    }

    /** C8/S7: the approval request keeps the band it was submitted under, even after the bank changes its bands. */
    public function test_band_snapshot_is_stored_on_the_approval_request(): void
    {
        $this->fixture();
        $b = $this->submitted($this->cc, '300000000.00');
        $request = $this->request('finance.budget_approval', $b);
        $ctx = $request->context;
        $this->assertSame('250m+', $ctx['amount_band']);
        $this->assertSame(1, $ctx['doa_band_version_no']);
        $this->assertSame('300000000.00', $ctx['base_amount']);
        $this->assertSame(250000000, (int) $ctx['band']['min']);
        $this->assertNull($ctx['band']['max']);
        $this->assertSame('250m+', $ctx['conditions']['amount_band']);
        $this->assertSame((string) $this->cc->id, $ctx['conditions']['cost_centre_id']);
        $this->assertSame('annual', $ctx['conditions']['cycle_type']);

        $svc = app(DoaBandService::class);
        $v = $svc->propose($this->org->id, [['label' => 'A', 'min' => 0, 'max' => 2000000], ['label' => 'B', 'min' => 2000000, 'max' => 20000000], ['label' => 'C', 'min' => 20000000, 'max' => 100000000],
            ['label' => 'D', 'min' => 100000000, 'max' => 300000000], ['label' => 'E', 'min' => 300000000, 'max' => null]], today()->toDateString(), 'Re-cut', $this->fin);
        $svc->approve($v, $this->cfo);
        $this->assertSame('300m+', $svc->bandFor($this->org->id, '300000000')['key'], 'the live bands changed');
        $this->assertSame($ctx, $request->refresh()->context, 'the stored snapshot did not');

        $this->actingAs($this->cfo)->get(route('finance.budgets.show', [$b, 'tab' => 'approvals']))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('approval.configured', true)->where('approval.context.amount_band', '250m+')->where('approval.context.doa_band_version_no', 1));
    }

    /** FIN-07.01: with no stages configured the documented default applies: one approver who is not the submitter or owner. */
    public function test_default_when_unconfigured(): void
    {
        $this->fixture(false);
        $svc = app(BudgetService::class);
        $b = $this->submitted();
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('process', 'like', 'finance.%')->count(), 'no engine request without stages');
        $trail = app(FinanceApprovals::class)->trail('finance.budget_approval', $b, $this->cfo);
        $this->assertFalse($trail['configured']);
        $this->assertStringContainsString('One approval', $trail['default']);
        $this->assertNull($trail['request']);

        $this->actingAs($this->cfo)->get(route('finance.budgets.show', [$b, 'tab' => 'approvals']))->assertInertia(fn (Assert $p) => $p->where('approval.configured', false));
        $this->assertRefused(fn () => $svc->approve($b, $this->owner), 'approval');
        $this->assertStringContainsString('approved', $svc->approve($b, $this->cfo));
        $this->assertSame('approved', $b->refresh()->status);
    }

    /** FIN-07.01 / FIN-07.02: a configured workflow where no stage applies must still forbid self-approval. */
    public function test_zero_step_request_still_enforces_segregation_of_duties(): void
    {
        $this->fixture(false);
        $wf = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => 'finance.budget_approval', 'is_active' => true, 'initiator_roles' => null]);
        $wf->stages()->create(['organization_id' => $this->org->id, 'sequence' => 40, 'name' => 'MD/CEO approval', 'approver_type' => 'job_title', 'approver_ref' => 'Managing Director',
            'sla_hours' => 120, 'min_approvals' => 1, 'is_active' => true, 'condition_field' => 'amount_band', 'condition_values' => FinancePolicySeeder::bandsFrom($this->org->id, 250000000)]);
        $cc = CostCentre::create(['code' => 'CC-F', 'name' => 'Finance owned', 'owner_id' => $this->fin->id]);
        $svc = app(BudgetService::class);

        $b = $this->draft($cc, '1000.00', 'NGN', $this->fin);
        $svc->submit($b, $this->fin);
        $this->assertSame(0, $this->request('finance.budget_approval', $b)->steps->count(), 'no stage applies to 1,000 NGN');
        $this->assertRefused(fn () => $svc->approve($b->refresh(), $this->fin), 'approval', 'segregation of duties');
        $this->assertSame('submitted', $b->refresh()->status);
        $svc->approve($b->refresh(), $this->cfo);
        $this->assertSame('approved', $b->refresh()->status);

        // the same for a transfer whose only stage (cross cost centre) does not apply
        $b1 = $this->baselined($this->cc, '1200000.00');
        $a = $this->firstLine($b1);
        BudgetLine::$allowLockedAppend = true;
        $second = BudgetLine::withoutGlobalScopes()->forceCreate(['organization_id' => $this->org->id, 'budget_id' => $b1->id, 'line_no' => 9, 'origin' => 'transfer', 'description' => 'Sibling line',
            'cost_pool_id' => $a->cost_pool_id, 'tower_id' => $a->tower_id, 'cost_centre_id' => $a->cost_centre_id, 'currency' => 'NGN', 'fx_rate' => 1, 'fx_rate_type' => 'spot',
            'annual_amount' => 0, 'base_annual_amount' => 0, 'phasing_method' => 'equal']);
        BudgetLine::$allowLockedAppend = false;
        $tw = ApprovalWorkflow::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'process' => 'finance.budget_transfer', 'is_active' => true, 'initiator_roles' => null]);
        $tw->stages()->create(['organization_id' => $this->org->id, 'sequence' => 20, 'name' => 'CFO approval (cross cost centre)', 'approver_type' => 'job_title', 'approver_ref' => 'Chief Financial Officer',
            'sla_hours' => 48, 'min_approvals' => 1, 'is_active' => true, 'condition_field' => 'crosses_cost_centre', 'condition_values' => ['yes']]);
        $ts = app(BudgetTransferService::class);
        $t = $ts->create($this->transferFor($a, $second, '5000'), $this->fin);
        $ts->submit($t, $this->fin);
        $this->assertRefused(fn () => $ts->approve($t->refresh(), $this->fin), 'approval', 'segregation of duties');
        $ts->approve($t->refresh(), $this->cfo);
        $this->assertSame('approved', $t->refresh()->status);
    }

    /** FIN-07.01: a high-value virement escalates to the CIO by DoA band, a small one does not. */
    public function test_transfer_escalates_to_cio_from_the_threshold_band(): void
    {
        $this->fixture(false);
        $b1 = $this->baselined($this->cc, '100000000.00');
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $a = $this->firstLine($b1);
        BudgetLine::$allowLockedAppend = true;
        $second = BudgetLine::withoutGlobalScopes()->forceCreate(['organization_id' => $this->org->id, 'budget_id' => $b1->id, 'line_no' => 9, 'origin' => 'transfer', 'description' => 'Sibling line',
            'cost_pool_id' => $a->cost_pool_id, 'tower_id' => $a->tower_id, 'cost_centre_id' => $a->cost_centre_id, 'currency' => 'NGN', 'fx_rate' => 1, 'fx_rate_type' => 'spot',
            'annual_amount' => 0, 'base_annual_amount' => 0, 'phasing_method' => 'equal']);
        BudgetLine::$allowLockedAppend = false;
        $svc = app(BudgetTransferService::class);
        $steps = fn ($t) => $this->request('finance.budget_transfer', $t)->steps->sortBy('sequence')->pluck('name')->all();

        $small = $svc->create($this->transferFor($a, $second, '5000'), $this->owner);
        $svc->submit($small, $this->owner);
        $this->assertSame(['Finance Controller review'], $steps($small));
        $big = $svc->create($this->transferFor($a, $second, '60000000'), $this->owner);
        $svc->submit($big, $this->owner);
        $this->assertSame(['Finance Controller review', 'CIO approval (high value)'], $steps($big));
        $this->assertArrayHasKey('amount_band', ApprovalProcesses::conditions('finance.budget_transfer', $this->org->id));
        $cio = ApprovalWorkflowStage::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'CIO approval (high value)')->firstOrFail();
        $this->assertSame(['50m-250m', '250m+'], $cio->condition_values);
    }
}
