<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetComment;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetLedger;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FiscalCalendarService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsBudgets;
use Tests\TestCase;

/** ATH-FIN-001 §12 FIN-02.01, .02, .05, .14, .16 and FIN-07.06: the budget version lifecycle. */
class BudgetLifecycleTest extends TestCase
{
    use BuildsBudgets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgets();
    }

    /** FIN-02.01 cycles have types, windows and guarded transitions. */
    public function test_cycles_have_types_windows_and_guarded_transitions(): void
    {
        $cycles = app(BudgetCycleService::class);
        $base = ['fiscal_year_id' => $this->fy->id, 'name' => 'FY27', 'type' => 'annual', 'opens_on' => '2026-09-01', 'closes_on' => '2026-11-30'];

        $this->assertRefused(fn () => $cycles->create($this->org->id, ['type' => 'quarterly'] + $base, $this->fin));
        $this->assertRefused(fn () => $cycles->create($this->org->id, ['closes_on' => '2028-02-01'] + $base, $this->fin), 'closes_on');
        $this->assertRefused(fn () => $cycles->create($this->org->id, ['opens_on' => '2026-12-01', 'closes_on' => '2026-11-01'] + $base, $this->fin), 'closes_on');
        foreach (BudgetCycle::TYPES as $type) {
            $this->assertSame($type, $cycles->create($this->org->id, ['type' => $type] + $base, $this->fin)->type);
        }

        $cycle = $cycles->create($this->org->id, $base, $this->fin);
        $this->assertSame('planned', $cycle->status);
        $this->assertRefused(fn () => app(BudgetService::class)->createVersion($cycle, $this->cc, 'zero_based', $this->owner), 'cycle_id');
        $this->assertRefused(fn () => $cycles->transition($cycle, 'close', $this->fin));
        $this->assertRefused(fn () => $cycles->transition($cycle, 'review', $this->fin));
        $this->assertSame('open', $cycles->transition($cycle, 'open', $this->fin)->status);
        $this->assertRefused(fn () => $cycles->transition($cycle->refresh(), 'open', $this->fin));
        $this->assertRefused(fn () => $cycles->transition($cycle, 'bogus', $this->fin), 'action');

        // close is refused while a budget of the cycle is in approval
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($cycle->refresh(), $this->cc, 'zero_based', $this->owner);
        $svc->addLine($b, $this->lineData(), $this->owner);
        $svc->submit($b->refresh(), $this->owner);
        $cycles->transition($cycle->refresh(), 'review', $this->fin);
        $this->assertRefused(fn () => $cycles->transition($cycle->refresh(), 'close', $this->fin), null, 'in approval');
        $this->assertSame('open', $cycles->transition($cycle->refresh(), 'reopen', $this->fin)->status);

        // HTTP: only Finance settings managers run cycles
        $this->actingAs($this->owner)->post(route('finance.cycles.store'), $base)->assertForbidden();
        $admin = $this->userWith('Organization Admin');
        $this->actingAs($admin)->post(route('finance.cycles.store'), ['name' => 'Mid-year'] + ['type' => 'mid_year_reforecast'] + $base)->assertSessionHasNoErrors();
        $this->assertTrue(BudgetCycle::where('name', 'Mid-year')->exists());
    }

    /** FIN-02.02, FIN-02.14: default (unconfigured) path is one approval by someone who is neither submitter nor owner. */
    public function test_draft_submitted_approved_baseline_happy_path_unconfigured(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $this->assertSame('draft', $b->status);
        $this->assertSame('1200000.00', (string) $b->total_base_amount);

        // cannot approve a draft; empty budgets cannot be submitted
        $this->assertRefused(fn () => $svc->approve($b, $this->cfo), 'workflow');
        $empty = $svc->createVersion($this->openCycle(), $this->cc2, 'zero_based', $this->owner2);
        $this->assertRefused(fn () => $svc->submit($empty, $this->owner2), null, 'at least one line');

        $b = $svc->submit($b, $this->owner);
        $this->assertSame('submitted', $b->status);
        $this->assertSame($this->owner->id, $b->submitted_by_id);

        // owner (no approve permission), submitter-with-permission and a stranger from another bank are all refused
        $this->assertRefused(fn () => $svc->approve($b, $this->owner), 'approval');
        $this->actingAs($this->owner)->post(route('finance.budgets.approve', $b))->assertForbidden();
        $b->forceFill(['submitted_by_id' => $this->cfo->id])->save();
        $this->assertRefused(fn () => $svc->approve($b->refresh(), $this->cfo), 'approval', 'segregation of duties');
        $b->forceFill(['submitted_by_id' => $this->owner->id, 'owner_id' => $this->cfo->id])->save();
        $this->assertRefused(fn () => $svc->approve($b->refresh(), $this->cfo), 'approval', 'own this budget');
        $b->forceFill(['owner_id' => $this->owner->id])->save();

        $this->actingAs($this->cfo)->post(route('finance.budgets.approve', $b), ['note' => 'Within guidance'])->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('approved', $b->status);
        $this->assertSame($this->cfo->id, $b->approved_by_id);
        $this->assertNotNull($b->approved_at);

        // baselining needs the approve permission and an approved version
        $this->assertRefused(fn () => $svc->baseline($b, $this->owner), 'workflow');
        $this->actingAs($this->fin)->post(route('finance.budgets.baseline', $b))->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('baseline', $b->status);
        $this->assertSame("{$this->fy->id}:{$this->cc->id}", $b->baseline_slot);
        $this->assertSame($this->fin->id, $b->baselined_by_id);
    }

    /** FIN-02.02, FIN-07.06: a baseline is immutable; an edit creates version 2 and leaves version 1 untouched. */
    public function test_baseline_is_immutable_and_an_edit_creates_version_two(): void
    {
        $b = $this->baselined();
        $line = $this->firstLine($b);

        $this->assertRefused(fn () => $b->update(['title' => 'changed']), 'workflow', 'immutable');
        $this->assertRefused(fn () => $line->update(['annual_amount' => '5.00']), 'workflow');
        $this->assertRefused(fn () => $line->delete(), 'workflow');
        $this->assertRefused(fn () => BudgetLinePeriod::where('budget_line_id', $line->id)->first()->update(['amount' => '1.00']), 'workflow');
        $this->assertRefused(fn () => app(BudgetService::class)->updateLine($line, ['annual_amount' => '5'], $this->owner), 'workflow', 'cannot be edited');

        $this->actingAs($this->owner)->put(route('finance.budgets.lines.update', [$b, $line]), ['annual_amount' => '2000000'])
            ->assertRedirect()->assertSessionHas('warning');
        $versions = Budget::where('budget_code', $b->budget_code)->orderBy('version_no')->get();
        $this->assertCount(2, $versions);
        [$v1, $v2] = [$versions[0], $versions[1]];
        $this->assertSame('baseline', $v1->status);
        $this->assertSame('draft', $v2->status);
        $this->assertSame($v1->id, $v2->previous_version_id);
        $this->assertSame('1200000.00', (string) $line->refresh()->annual_amount);
        $this->assertSame('1200000.00', (string) $v1->refresh()->total_base_amount);
        $this->assertSame('2000000.00', (string) $this->firstLine($v2)->annual_amount);
        $this->assertSame($line->id, $this->firstLine($v2)->source_line_id);
        $this->assertSame('2000000.00', (string) $v2->refresh()->total_base_amount);

        // a second edit reuses the open draft instead of creating version 3
        $this->actingAs($this->owner)->put(route('finance.budgets.lines.update', [$b, $line]), ['description' => 'Finacle AMC (revised)'])->assertSessionHas('warning');
        $this->assertSame(2, Budget::where('budget_code', $b->budget_code)->count());
        // ...and revise refuses while that draft is open
        $this->assertRefused(fn () => app(BudgetService::class)->revise($v1->refresh(), $this->owner), 'workflow', 'already being prepared');
    }

    /** FIN-02.02: only one baseline per fiscal year and cost centre; the old one becomes revised and superseded. */
    public function test_only_one_baseline_per_fiscal_year_and_cost_centre(): void
    {
        $svc = app(BudgetService::class);
        $v1 = $this->baselined();
        $v2 = $svc->revise($v1, $this->owner);
        $svc->updateLine($this->firstLine($v2), ['annual_amount' => '1500000'], $this->owner);
        $svc->submit($v2->refresh(), $this->owner);
        $svc->approve($v2->refresh(), $this->cfo);
        $this->assertSame('baseline', $v1->refresh()->status, 'v1 stays the baseline until v2 is baselined');
        $svc->baseline($v2->refresh(), $this->fin);

        $v1->refresh();
        $v2->refresh();
        $this->assertSame('revised', $v1->status);
        $this->assertSame($v2->id, $v1->superseded_by_id);
        $this->assertNull($v1->baseline_slot);
        $this->assertSame('baseline', $v2->status);
        $this->assertSame("{$this->fy->id}:{$this->cc->id}", $v2->baseline_slot);
        $this->assertSame(1, Budget::where('fiscal_year_id', $this->fy->id)->where('cost_centre_id', $this->cc->id)->where('status', 'baseline')->count());

        // the database refuses a second holder of the slot
        $other = Budget::where('id', $v1->id)->first();
        try {
            DB::transaction(fn () => DB::table('fin_budgets')->where('id', $other->id)->update(['baseline_slot' => $v2->baseline_slot]));
            $this->fail('baseline_slot must be unique per organisation');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }

    /** §5.1 TRANSITIONS: every action from every status the table does not allow is refused. */
    public function test_transitions_refused_from_wrong_status(): void
    {
        $svc = app(BudgetService::class);
        $calls = [
            'submit' => fn (Budget $b) => $svc->submit($b, $this->owner),
            'withdraw' => fn (Budget $b) => $svc->withdraw($b, $this->owner),
            'approve' => fn (Budget $b) => $svc->approve($b, $this->cfo),
            'return' => fn (Budget $b) => $svc->returnToDraft($b, $this->cfo, 'no'),
            'reject' => fn (Budget $b) => $svc->reject($b, $this->cfo, 'no'),
            'baseline' => fn (Budget $b) => $svc->baseline($b, $this->fin),
            'revise' => fn (Budget $b) => $svc->revise($b, $this->owner),
        ];
        $this->assertSame(array_keys(BudgetService::TRANSITIONS), array_keys($calls), 'every transition is covered');

        $b = $this->draft();
        $checked = 0;
        foreach ($calls as $action => $call) {
            foreach (array_diff(Budget::STATUSES, BudgetService::TRANSITIONS[$action]['from']) as $status) {
                DB::table('fin_budgets')->where('id', $b->id)->update(['status' => $status]);
                $this->assertRefused(fn () => $call(Budget::withoutGlobalScopes()->find($b->id)), 'workflow');
                $checked++;
            }
        }
        $this->assertGreaterThan(30, $checked);
        $this->assertSame(0, ApprovalRequest::withoutGlobalScopes()->where('process', 'like', 'finance.%')->count());
    }

    /** FIN-02.14: withdraw, return with per-line comments, and reject then resubmit. */
    public function test_withdraw_return_and_reject_paths(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $svc->addLine($b, $this->lineData(['description' => 'Second line', 'annual_amount' => '300000']), $this->owner);
        $lines = $b->lines()->orderBy('line_no')->get();

        $svc->submit($b->refresh(), $this->owner);
        $this->assertRefused(fn () => $svc->withdraw($b->refresh(), $this->owner2), 'workflow', 'submitted the budget');
        $this->assertSame('draft', $svc->withdraw($b->refresh(), $this->owner)->status);

        $svc->submit($b->refresh(), $this->owner);
        $this->assertRefused(fn () => $svc->returnToDraft($b->refresh(), $this->cfo, ''), 'note');
        $svc->returnToDraft($b->refresh(), $this->cfo, 'Please justify the uplift.', [
            ['line_id' => $lines[0]->id, 'body' => 'Quotation missing.'], ['line_id' => $lines[1]->id, 'body' => 'Merge with line 1.'], ['line_id' => 999999, 'body' => 'Stray note'],
        ]);
        $this->assertSame('draft', $b->refresh()->status);
        $comments = BudgetComment::where('budget_id', $b->id)->where('kind', 'return')->get();
        $this->assertCount(4, $comments);
        $this->assertSame([$lines[0]->id, $lines[1]->id], $comments->whereNotNull('budget_line_id')->pluck('budget_line_id')->sort()->values()->all());
        $this->assertNull($comments->firstWhere('body', 'Stray note')->budget_line_id, 'an unknown line id degrades to a budget-level comment');

        $svc->submit($b->refresh(), $this->owner);
        $svc->reject($b->refresh(), $this->cfo, 'Over the guidance ceiling.');
        $b->refresh();
        $this->assertSame('rejected', $b->status);
        $this->assertSame('Over the guidance ceiling.', $b->rejection_reason);
        $svc->updateLine($b->lines()->first(), ['annual_amount' => '900000'], $this->owner); // rejected versions are editable
        $svc->submit($b->refresh(), $this->owner);
        $this->assertSame('submitted', $b->refresh()->status);
        $this->assertNull($b->rejection_reason);
    }

    /** FIN-02.05, FIN-02.16: incremental from the prior baseline, prior-year columns, from-actuals deferred (C26). */
    public function test_incremental_from_prior_baseline_and_prior_year_columns(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->baselined($this->cc, '1000000.00');
        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        $c2 = $this->openCycle('annual', $fy2);

        $this->assertRefused(fn () => $svc->createVersion($c2, $this->cc, 'incremental', $this->owner), 'increment_pct');
        $this->assertRefused(fn () => $svc->createVersion($c2, $this->cc, 'incremental', $this->owner, ['increment_pct' => 150]), 'increment_pct');
        $this->assertRefused(fn () => $svc->createVersion($c2, $this->cc2, 'incremental', $this->owner2, ['increment_pct' => 5]), 'source_budget_id');
        $this->assertRefused(fn () => $svc->createVersion($c2, $this->cc2, 'from_actuals', $this->owner2), 'mode', 'Phase P3');

        $n = $svc->createVersion($c2, $this->cc, 'incremental', $this->owner, ['increment_pct' => 10]);
        $this->assertSame($b->id, $n->source_budget_id);
        $l = $this->firstLine($n);
        $this->assertSame('1100000.00', (string) $l->annual_amount);
        $this->assertSame('1000000.00', (string) $l->py_budget);
        $this->assertSame('10.00', $l->yoy_pct);
        $this->assertNull($l->py_actual, 'PY actual waits for P3');
        $this->assertSame($this->firstLine($b)->id, $l->source_line_id);
        $this->assertSame('1100000.00', (string) $n->total_base_amount);
        $this->assertSame('1100000.00', bcadd((string) $l->periods()->sum('amount'), '0', 2));

        // a later baseline of the prior year refreshes the draft's prior-year column
        $v2 = $svc->revise($b, $this->owner);
        $svc->updateLine($this->firstLine($v2), ['annual_amount' => '1250000'], $this->owner);
        $svc->submit($v2->refresh(), $this->owner);
        $svc->approve($v2->refresh(), $this->cfo);
        $svc->baseline($v2->refresh(), $this->fin);
        $this->assertSame('1250000.00', (string) $l->refresh()->py_budget);
        $this->assertSame('-12.00', $l->yoy_pct);
    }

    /** FIN-02.02: version diff reports added, removed, changed and unchanged lines. */
    public function test_version_diff_reports_added_removed_changed_lines(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $svc->addLine($b, $this->lineData(['description' => 'Keep as is', 'annual_amount' => '100000']), $this->owner);
        $svc->addLine($b, $this->lineData(['description' => 'To be removed', 'annual_amount' => '50000']), $this->owner);
        $svc->submit($b->refresh(), $this->owner);
        $svc->approve($b->refresh(), $this->cfo);
        $svc->baseline($b->refresh(), $this->fin);

        $v2 = $svc->revise($b->refresh(), $this->owner);
        $lines = $v2->lines()->orderBy('line_no')->get();
        $svc->updateLine($lines[0], ['annual_amount' => '1500000'], $this->owner);
        $svc->addLine($v2->refresh(), $this->lineData(['description' => 'Brand new', 'annual_amount' => '25000']), $this->owner);
        $svc->deleteLine($lines[2]);

        $d = $svc->diff($b->refresh(), $v2->refresh());
        $by = collect($d['lines'])->groupBy('change');
        $this->assertSame(['added', 'changed', 'removed', 'unchanged'], collect(['added', 'changed', 'removed', 'unchanged'])->filter(fn ($k) => $by->has($k))->values()->all());
        $this->assertSame('Brand new', $by['added'][0]['b']['description']);
        $this->assertSame('To be removed', $by['removed'][0]['a']['description']);
        $this->assertSame('300000.00', $by['changed'][0]['base_delta']);
        $this->assertArrayHasKey('annual_amount', $by['changed'][0]['fields']);
        $this->assertSame('Keep as is', $by['unchanged'][0]['b']['description']);
        $this->assertSame('275000.00', $d['totals']['base_delta']); // +300000 +25000 -50000
        $this->assertSame($d['totals']['base_delta'], bcsub((string) $v2->total_base_amount, (string) $b->total_base_amount, 2));

        $this->actingAs($this->owner)->getJson(route('finance.budgets.diff', [$v2, 'against' => $b->id]))->assertOk()
            ->assertJsonPath('a.version_no', 1)->assertJsonPath('b.version_no', 2)->assertJsonPath('totals.base_delta', '275000.00');
        $other = $this->baselined($this->cc2);
        $this->assertRefused(fn () => $svc->diff($b, $other), 'against');
    }

    /** §8 row restriction: a budget owner sees only their own cost centres; others are 403. */
    public function test_budget_owner_sees_only_their_cost_centres(): void
    {
        $mine = $this->draft($this->cc);
        $theirs = $this->draft($this->cc2);

        $this->actingAs($this->owner)->get(route('finance.budgets.index'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('budgets', 1)->where('budgets.0.id', $mine->id));
        $this->get(route('finance.budgets.show', $mine))->assertOk();
        $this->get(route('finance.budgets.show', $theirs))->assertForbidden();
        $this->get(route('finance.budgets.edit', $theirs))->assertForbidden();
        $this->put(route('finance.budgets.lines.update', [$theirs, $this->firstLine($theirs)]), ['annual_amount' => '1'])->assertForbidden();
        $this->post(route('finance.budgets.store'), ['cycle_id' => $theirs->cycle_id, 'cost_centre_id' => $this->cc2->id, 'title' => 'x', 'mode' => 'zero_based'])->assertForbidden();

        $this->actingAs($this->cfo)->get(route('finance.budgets.index'))->assertInertia(fn (Assert $p) => $p->has('budgets', 2));
    }

    /** FIN-07.06: each lifecycle step writes a named event on the it-finance log. */
    public function test_lifecycle_is_activity_logged(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $svc->submit($b, $this->owner);
        $svc->approve($b->refresh(), $this->cfo);
        $svc->baseline($b->refresh(), $this->fin);
        $svc->revise($b->refresh(), $this->owner);

        $events = Activity::where('log_name', 'it-finance')->where('subject_type', Budget::class)->orderBy('id')->get();
        foreach (['created', 'submitted', 'approved', 'baselined', 'revised'] as $event) {
            $this->assertContains($event, $events->pluck('event')->all());
        }
        $order = $events->pluck('event')->unique()->values();
        $this->assertTrue($order->search('submitted') < $order->search('approved') && $order->search('approved') < $order->search('baselined'));
        $this->assertSame($this->owner->id, $events->firstWhere('event', 'submitted')->causer_id);
        $this->assertSame($this->cfo->id, $events->firstWhere('event', 'approved')->causer_id);
        $this->assertSame($this->fin->id, $events->firstWhere('event', 'baselined')->causer_id);
        $this->assertSame('1200000.00', $events->firstWhere('event', 'submitted')->properties['total_base_amount']);
    }

    /** FIN-02.02: a draft can be deleted by the model but a submitted one cannot (guards the lifecycle). */
    public function test_only_drafts_can_be_deleted(): void
    {
        $b = $this->draft();
        app(BudgetService::class)->submit($b, $this->owner);
        $this->assertRefused(fn () => $b->refresh()->delete(), 'workflow', 'Only a draft');
        $this->assertSame(1, BudgetLine::where('budget_id', $b->id)->count());
        $this->assertNotNull(FiscalYear::find($this->fy->id));
        $this->assertRefused(fn () => app(FiscalCalendarService::class)->deleteYear($this->fy->refresh()));
    }

    /** FIN-02.05: incremental mode scales the CURRENT amount (original plus adjustments), the same base as py_budget. */
    public function test_incremental_uses_the_current_amount_including_adjustments(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->baselined($this->cc, '1000000.00');
        $line = $this->firstLine($b);
        $period = Period::where('fiscal_year_id', $this->fy->id)->where('is_adjustment', false)->orderBy('sequence')->firstOrFail();
        app(BudgetLedger::class)->post($line, $period, '100000.00', 'supplementary', 1, $this->fin);
        $this->assertSame('1100000.00', $line->refresh()->current_base_amount);

        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        $n = $svc->createVersion($this->openCycle('annual', $fy2), $this->cc, 'incremental', $this->owner, ['increment_pct' => 10]);
        $l = $this->firstLine($n);
        $this->assertSame('1100000.00', (string) $l->py_budget);
        $this->assertSame('1210000.00', (string) $l->annual_amount);
        $this->assertSame('10.00', $l->yoy_pct);
    }
}
