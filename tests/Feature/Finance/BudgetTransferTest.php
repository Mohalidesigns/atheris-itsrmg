<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetTransfer;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
use App\Models\Finance\SupplementaryRequest;
use App\Services\Finance\BudgetLedger;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\SupplementaryBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Finance\Concerns\BuildsBudgets;
use Tests\TestCase;

/** ATH-FIN-001 §12 FIN-02.10 (transfers) and FIN-02.11 (supplementary), with FIN-07.02 segregation of duties. */
class BudgetTransferTest extends TestCase
{
    use BuildsBudgets, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBudgets();
    }

    private function sum(iterable $values): string
    {
        $t = '0';
        foreach ($values as $v) {
            $t = bcadd($t, (string) $v, 2);
        }

        return $t;
    }

    private function transferData(BudgetLine $from, BudgetLine $to, string $amount = '100000.01', array $over = []): array
    {
        return $over + ['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $to->id, 'amount' => $amount, 'reason' => 'Rebalance after vendor renegotiation'];
    }

    private function period(int $seq): Period
    {
        return Period::where('fiscal_year_id', $this->fy->id)->where('sequence', $seq)->firstOrFail();
    }

    /** FIN-02.10: nothing moves until the transfer is approved and applied; the postings net to zero. */
    public function test_transfer_is_net_zero_and_applied_only_after_approval(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $from = $this->firstLine($b1);
        $to = $this->firstLine($b2);
        $originalPeriods = $from->periods()->pluck('base_amount', 'period_id')->map(fn ($v) => (string) $v)->all();

        // the whole path over HTTP, as the people who would really do it
        $this->actingAs($this->owner)->post(route('finance.transfers.store'), $this->transferData($from, $to))->assertSessionHasNoErrors();
        $t = BudgetTransfer::firstOrFail();
        $this->assertSame('draft', $t->status);
        $this->assertSame($this->owner->id, $t->requested_by_id);
        $this->assertSame('100000.01', (string) $t->base_amount);
        $this->assertSame('NGN', $t->currency);

        $this->assertRefused(fn () => app(BudgetTransferService::class)->apply($t->refresh(), $this->fin), 'workflow', 'draft transfer cannot be applied');
        $this->post(route('finance.transfers.submit', $t))->assertSessionHasNoErrors();
        $this->assertSame('submitted', $t->refresh()->status);
        $this->assertRefused(fn () => app(BudgetTransferService::class)->apply($t->refresh(), $this->fin), 'workflow', 'submitted transfer cannot be applied');
        $this->post(route('finance.transfers.apply', $t))->assertForbidden();
        $this->assertSame('1200000.00', $from->refresh()->current_base_amount);

        $this->actingAs($this->cfo)->post(route('finance.transfers.approve', $t), ['note' => 'OK'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $t->refresh()->status);
        $this->assertSame($this->cfo->id, $t->approved_by_id);
        $this->assertSame('1200000.00', $from->refresh()->current_base_amount, 'approval alone moves no money');
        $this->assertSame(0, BudgetAdjustment::count());

        $this->actingAs($this->fin)->post(route('finance.transfers.apply', $t))->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('applied', $t->status);
        $this->assertSame($this->fin->id, $t->applied_by_id);
        $this->assertNotNull($t->applied_at);

        $rows = BudgetAdjustment::where('source_type', 'transfer')->where('source_id', $t->id)->get();
        $this->assertGreaterThanOrEqual(2, $rows->count());
        $this->assertSame('0.00', $this->sum($rows->pluck('base_amount')), 'Σ adjustments = 0');
        $this->assertSame('-100000.01', $this->sum($rows->where('budget_line_id', $from->id)->pluck('base_amount')));
        $this->assertSame('100000.01', $this->sum($rows->where('budget_line_id', $to->id)->pluck('base_amount')));
        $this->assertSame('1099999.99', $from->refresh()->current_base_amount);
        $this->assertSame('700000.01', $to->refresh()->current_base_amount);

        // the baseline itself never changes: original amounts, phasing and totals
        $this->assertSame('1200000.00', (string) $from->annual_amount);
        $this->assertSame($originalPeriods, $from->periods()->pluck('base_amount', 'period_id')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('1200000.00', (string) $b1->refresh()->total_base_amount);
        $this->assertSame('600000.00', (string) $b2->refresh()->total_base_amount);
        $this->assertSame('baseline', $b1->status);

        // the ledger is append-only
        $this->assertRefused(fn () => $rows->first()->update(['base_amount' => '0.00']), 'workflow');
        $this->assertRefused(fn () => $rows->first()->delete(), 'workflow');
        $this->assertRefused(fn () => app(BudgetTransferService::class)->apply($t->refresh(), $this->fin), 'workflow', 'applied transfer cannot be applied');
        $this->assertTrue(Activity::where('log_name', 'it-finance')->where('event', 'applied')->where('subject_type', BudgetTransfer::class)->exists());
    }

    /** FIN-02.10: a period-to-period move posts exactly one debit and one credit, also net zero. */
    public function test_period_transfer_posts_a_matched_pair(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $line = $this->firstLine($b1);
        $svc = app(BudgetTransferService::class);
        $t = $svc->create($this->transferData($line, $line, '40000.00', ['from_period_id' => $this->period(11)->id, 'to_period_id' => $this->period(12)->id]), $this->owner);
        $svc->submit($t, $this->owner);
        $svc->approve($t->refresh(), $this->cfo);
        $svc->apply($t->refresh(), $this->fin);

        $rows = BudgetAdjustment::where('source_id', $t->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['-40000.00', '40000.00'], $rows->pluck('base_amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame([$this->period(11)->id, $this->period(12)->id], $rows->pluck('period_id')->all());
        $current = app(BudgetLedger::class)->currentByPeriod($line);
        $this->assertSame('60000.00', $current[$this->period(11)->id]);
        $this->assertSame('140000.00', $current[$this->period(12)->id]);
        $this->assertSame('1200000.00', $line->refresh()->current_base_amount, 'the line total is unchanged by a timing move');
    }

    /** FIN-02.10: availability, CapEx/OpEx, period and basic-data rules. */
    public function test_transfer_cannot_exceed_availability_or_cross_capex_opex(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $cc3 = CostCentre::create(['code' => 'CC-3', 'name' => 'Capital', 'owner_id' => $this->owner2->id]);
        $capex = $this->baselined($cc3, '900000.00', 'NGN', ['is_capex' => true, 'description' => 'Servers']);
        $from = $this->firstLine($b1);
        $to = $this->firstLine($b2);
        $svc = app(BudgetTransferService::class);

        $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, '1200000.01'), $this->owner), 'amount', 'exceeds');
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, '100000.01', ['from_period_id' => $this->period(2)->id, 'amount' => '100000.01']), $this->owner), 'amount', 'exceeds');
        foreach (['0', '-5', '10.001', 'abc', ''] as $bad) {
            $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, $bad), $this->owner), 'amount');
        }
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, '10', ['reason' => ' ']), $this->owner), 'reason');
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $from, '10'), $this->owner), 'to_line_id');
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $from, '10', ['from_period_id' => $this->period(2)->id, 'to_period_id' => $this->period(2)->id]), $this->owner), 'to_line_id');
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, '10', ['from_period_id' => 999999]), $this->owner), 'from_period_id');
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, '10', ['fiscal_year_id' => 999999]), $this->owner), 'fiscal_year_id');

        // lines of a draft budget cannot be transferred
        $draft = $this->draft($this->cc2);
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $this->firstLine($draft), '10'), $this->owner), 'to_line_id', 'baseline budget');

        // a closed period is not a valid posting date
        Period::whereKey($this->period(3)->id)->update(['status' => 'closed']);
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $to, '10', ['from_period_id' => $this->period(3)->id]), $this->owner), 'from_period_id', 'open period');

        // OpEx to CapEx is refused unless the bank allows it
        $capexLine = $this->firstLine($capex);
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $capexLine, '10'), $this->owner), 'to_line_id', 'CapEx and OpEx');
        config(['finance.transfers.allow_capex_opex' => true]);
        $this->assertSame('draft', $svc->create($this->transferData($from, $capexLine, '10'), $this->owner)->status);
        config(['finance.transfers.allow_capex_opex' => false]);

        // availability is re-checked at apply: two approved transfers cannot both draw the same money
        $a = $svc->create($this->transferData($from, $to, '900000.00'), $this->owner);
        $b = $svc->create($this->transferData($from, $to, '500000.00'), $this->owner);
        foreach ([$a, $b] as $t) {
            $svc->submit($t, $this->owner);
            $svc->approve($t->refresh(), $this->cfo);
        }
        $svc->apply($a->refresh(), $this->fin);
        $this->assertRefused(fn () => $svc->apply($b->refresh(), $this->fin), 'amount', 'exceeds');
        $this->assertSame('approved', $b->refresh()->status);
        $this->assertSame(0, BudgetAdjustment::where('source_id', $b->id)->where('source_type', 'transfer')->count());

        // the requester cannot draw from a cost centre they do not own
        $this->assertRefused(fn () => $svc->create($this->transferData($to, $from, '10'), $this->owner), 'from_line_id', 'cost centres you own');
    }

    /** FIN-02.10: a transfer can create a new line in another cost centre's baseline. */
    public function test_transfer_to_a_new_line_in_another_cost_centre(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $from = $this->firstLine($b1);
        $svc = app(BudgetTransferService::class);
        $spec = ['to_line_id' => null, 'to_cost_centre_id' => $this->cc2->id, 'to_cost_pool_id' => $this->cat('CP-SW'), 'to_tower_id' => $this->cat('TW-SEC'), 'to_description' => 'New SOC tooling'];

        $t = $svc->create($this->transferData($from, $from, '250000.00', $spec), $this->owner);
        $this->assertSame('yes', $t->crosses_cost_centre);
        $this->assertSame('opex', $t->capex_opex);
        $this->assertNull($t->to_line_id);
        foreach (['to_cost_centre_id', 'to_cost_pool_id', 'to_tower_id', 'to_description'] as $field) {
            $this->assertRefused(fn () => $svc->create($this->transferData($from, $from, '1', array_merge($spec, [$field => null])), $this->owner), $field);
        }
        $empty = CostCentre::create(['code' => 'CC-9', 'name' => 'No budget yet', 'owner_id' => $this->owner2->id]);
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $from, '1', array_merge($spec, ['to_cost_centre_id' => $empty->id])), $this->owner), 'to_cost_centre_id', 'no baseline');
        $this->assertRefused(fn () => $svc->create($this->transferData($from, $from, '1', array_merge($spec, ['to_cost_pool_id' => $this->cat('CP-HW')])), $this->owner), 'to_line_id', 'CapEx and OpEx');

        $svc->submit($t, $this->owner);
        $svc->approve($t->refresh(), $this->cfo);
        $svc->apply($t->refresh(), $this->fin);

        $t->refresh();
        $line = BudgetLine::findOrFail($t->to_line_id);
        $this->assertSame($b2->id, $line->budget_id);
        $this->assertSame('transfer', $line->origin);
        $this->assertSame('New SOC tooling', $line->description);
        $this->assertSame('0.00', (string) $line->annual_amount, 'the original budget of the new line is zero');
        $this->assertSame('250000.00', $line->current_base_amount);
        $this->assertSame(2, $line->line_no);
        $this->assertSame('600000.00', (string) $b2->refresh()->total_base_amount);
        $this->assertSame('baseline', $b2->status);
        $this->assertSame('950000.00', $from->refresh()->current_base_amount);
        $this->assertSame('0.00', $this->sum(BudgetAdjustment::where('source_id', $t->id)->where('source_type', 'transfer')->pluck('base_amount')));
        $this->assertFalse(BudgetLine::$allowLockedAppend, 'the locked-append switch is always reset');
        $this->assertRefused(fn () => $line->update(['description' => 'sneaky']), 'workflow');
    }

    /** FIN-07.02: requesters cannot approve their own transfer, with or without configured stages. */
    public function test_requester_cannot_approve_own_transfer(): void
    {
        $from = $this->firstLine($this->baselined($this->cc, '1200000.00'));
        $to = $this->firstLine($this->baselined($this->cc2, '600000.00'));
        $svc = app(BudgetTransferService::class);
        $cfo2 = $this->userWith('Finance Approver', ['job_title' => 'Chief Financial Officer']);

        // unconfigured default: the CFO requests, so the CFO cannot approve
        $t = $svc->create($this->transferData($from, $to, '10000'), $this->cfo);
        $svc->submit($t, $this->cfo);
        $this->assertRefused(fn () => $svc->approve($t->refresh(), $this->cfo), 'approval', 'segregation of duties');
        $this->actingAs($this->cfo)->post(route('finance.transfers.approve', $t))->assertSessionHasErrors('approval');
        $this->actingAs($this->owner)->post(route('finance.transfers.approve', $t))->assertForbidden();
        $this->assertRefused(fn () => $svc->submit($t->refresh(), $this->cfo), 'workflow');
        $this->assertRefused(fn () => $svc->reject($t->refresh(), $cfo2, ''), 'note');
        $this->assertRefused(fn () => $svc->withdraw($t->refresh(), $cfo2), 'workflow', 'requested the transfer');
        $svc->approve($t->refresh(), $cfo2);
        $this->assertSame('approved', $t->refresh()->status);

        // configured stages: stage 1 is the Finance Controller role; the controller cannot decide their own request
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $own = $svc->create($this->transferData($from, $to, '20000'), $this->fin);
        $svc->submit($own, $this->fin);
        $this->assertRefused(fn () => $svc->approve($own->refresh(), $this->fin), 'approval', 'started this approval');
        $this->assertRefused(fn () => $svc->approve($own->refresh(), $cfo2), 'approval', 'Finance Controller review');
        $fin2 = $this->userWith('IT Finance Controller');
        $this->assertStringContainsString('Now awaiting', $svc->approve($own->refresh(), $fin2));
        $this->assertSame('submitted', $own->refresh()->status, 'the transfer crosses cost centres, so the CFO stage must follow');
        $svc->approve($own->refresh(), $cfo2);
        $this->assertSame('approved', $own->refresh()->status);
    }

    /** FIN-02.11: a supplementary request creates a new baseline line, only after approval, at the spot rate. */
    public function test_supplementary_request_lifecycle_creates_resulting_line(): void
    {
        $b = $this->baselined($this->cc, '1200000.00');
        $svc = app(SupplementaryBudgetService::class);
        $data = ['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-SEC'),
            'category_id' => $this->cat('TW-SEC-SEC'), 'title' => 'CBN directive: enhanced fraud monitoring', 'is_capex' => false, 'currency' => 'USD',
            'amount' => '1000', 'justification' => 'CBN circular requires real-time monitoring.'];

        $this->assertRefused(fn () => $svc->create(array_merge($data, ['justification' => '']), $this->owner), 'justification');
        $this->assertRefused(fn () => $svc->create(array_merge($data, ['amount' => '0']), $this->owner), 'amount');
        $this->assertRefused(fn () => $svc->create(array_merge($data, ['title' => '']), $this->owner), 'title');
        $this->assertRefused(fn () => $svc->create(array_merge($data, ['cost_centre_id' => $this->cc2->id]), $this->owner), 'cost_centre_id');
        $this->assertRefused(fn () => $svc->create(array_merge($data, ['currency' => 'EUR']), $this->owner), 'currency');

        $this->actingAs($this->owner)->post(route('finance.supplementary.store'), $data)->assertSessionHasNoErrors();
        $r = SupplementaryRequest::firstOrFail();
        $this->assertSame('draft', $r->status);
        $this->assertSame('spot', $r->fx_rate_type);
        $this->assertSame('1500.00000000', (string) $r->fx_rate);
        $this->assertSame('1500000.00', (string) $r->base_amount);

        $this->assertRefused(fn () => $svc->apply($r, $this->fin), 'workflow', 'draft request cannot be applied');
        $this->post(route('finance.supplementary.submit', $r))->assertSessionHasNoErrors();
        $this->assertRefused(fn () => $svc->apply($r->refresh(), $this->fin), 'workflow', 'submitted request cannot be applied');

        $this->actingAs($this->cfo)->post(route('finance.supplementary.approve', $r))->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->refresh()->status);
        $this->assertSame(1, $b->lines()->count(), 'approval adds no line');
        $this->actingAs($this->fin)->post(route('finance.supplementary.apply', $r))->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('applied', $r->status);
        $line = BudgetLine::findOrFail($r->resulting_line_id);
        $this->assertSame($b->id, $line->budget_id);
        $this->assertSame('supplementary', $line->origin);
        $this->assertSame('0.00', (string) $line->annual_amount);
        $this->assertSame('1500000.00', $line->current_base_amount);
        $this->assertSame('1500000.00', $this->sum(BudgetAdjustment::where('source_type', 'supplementary')->where('source_id', $r->id)->pluck('base_amount')));
        $this->assertSame('1200000.00', (string) $b->refresh()->total_base_amount, 'the baseline total is untouched');
        $this->assertSame('baseline', $b->status);
        $this->assertSame(2, $b->lines()->count());

        // reject and withdraw paths
        $r2 = $svc->create(array_merge($data, ['currency' => 'NGN', 'amount' => '5000']), $this->owner);
        $svc->submit($r2, $this->owner);
        $this->assertRefused(fn () => $svc->reject($r2->refresh(), $this->cfo, ''), 'note');
        $this->assertSame('rejected', $svc->reject($r2->refresh(), $this->cfo, 'Not in this year\'s envelope')->status);
        $this->assertSame('Not in this year\'s envelope', $r2->refresh()->rejection_reason);
        $r3 = $svc->create(array_merge($data, ['currency' => 'NGN', 'amount' => '7000']), $this->owner);
        $this->assertSame('withdrawn', $svc->withdraw($r3, $this->owner)->status);

        // a cost centre with no baseline cannot take a new line
        $r4 = $svc->create(array_merge($data, ['cost_centre_id' => $this->cc2->id, 'currency' => 'NGN']), $this->fin);
        $this->assertRefused(fn () => $svc->submit($r4, $this->fin), 'workflow', 'no baseline');
    }

    /** FIN-02.11: a top-up of an existing line adds to its current budget without creating a line. */
    public function test_supplementary_top_up_of_existing_line(): void
    {
        $b = $this->baselined($this->cc, '1200000.00');
        $other = $this->baselined($this->cc2, '300000.00');
        $line = $this->firstLine($b);
        $svc = app(SupplementaryBudgetService::class);
        $base = ['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'title' => 'Finacle AMC uplift', 'currency' => 'NGN', 'amount' => '250000', 'justification' => 'Vendor price rise'];

        $this->assertRefused(fn () => $svc->create($base + ['target_line_id' => $this->firstLine($other)->id], $this->owner), 'target_line_id');
        $this->assertRefused(fn () => $svc->create($base + ['target_line_id' => 999999], $this->owner), 'target_line_id');

        $r = $svc->create($base + ['target_line_id' => $line->id], $this->owner);
        $this->assertSame($this->cat('TW-APP'), $r->tower_id, 'pool and tower come from the target line');
        $svc->submit($r, $this->owner);
        $svc->approve($r->refresh(), $this->cfo);
        $svc->apply($r->refresh(), $this->fin);

        $r->refresh();
        $this->assertSame($line->id, $r->resulting_line_id);
        $this->assertSame(1, $b->lines()->count());
        $this->assertSame('1450000.00', $line->refresh()->current_base_amount);
        $this->assertSame('1200000.00', (string) $line->annual_amount);
        $rows = BudgetAdjustment::where('source_type', 'supplementary')->where('source_id', $r->id)->get();
        $this->assertSame('250000.00', $this->sum($rows->pluck('base_amount')));
        $this->assertGreaterThan(1, $rows->count(), 'spread over the remaining open periods');

        // a later revision folds the top-up into the new version's amounts
        $v2 = app(BudgetService::class)->revise($b->refresh(), $this->owner);
        $this->assertSame('1450000.00', (string) $this->firstLine($v2)->annual_amount);
        $this->assertSame('1450000.00', (string) $v2->refresh()->total_base_amount);
    }

    /** C22 / FIN-02.10: an approved transfer applies to the current baseline after the budgets were revised and re-baselined. */
    public function test_transfer_approved_before_a_rebaseline_applies_to_the_new_baseline(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $from = $this->firstLine($b1);
        $to = $this->firstLine($b2);
        $ts = app(BudgetTransferService::class);
        $bs = app(BudgetService::class);
        $t = $ts->create($this->transferData($from, $to, '100000.00'), $this->owner);
        $ts->submit($t, $this->owner);
        $ts->approve($t->refresh(), $this->cfo);

        foreach ([[$b1, $this->owner], [$b2, $this->owner2]] as [$b, $own]) {
            $v = $bs->revise($b, $own);
            $bs->submit($v, $own);
            $bs->approve($v->refresh(), $this->cfo);
            $bs->baseline($v->refresh(), $this->fin);
        }
        $this->assertSame('revised', $b1->refresh()->status);

        $ts->apply($t->refresh(), $this->fin);
        $newFrom = $this->firstLine($b1->refresh()->supersededBy);
        $newTo = $this->firstLine($b2->refresh()->supersededBy);
        $this->assertSame('applied', $t->refresh()->status);
        $this->assertSame([$newFrom->id, $newTo->id], [$t->from_line_id, $t->to_line_id]);
        $this->assertSame('1100000.00', $newFrom->refresh()->current_base_amount);
        $this->assertSame('700000.00', $newTo->refresh()->current_base_amount);
        $this->assertSame(0, BudgetAdjustment::where('budget_line_id', $from->id)->count(), 'nothing posted on the superseded line');
    }

    /** C22: an adjustment applied to a baseline while its successor was in draft is carried onto the new baseline. */
    public function test_adjustments_applied_during_a_revision_survive_the_rebaseline(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $ts = app(BudgetTransferService::class);
        $bs = app(BudgetService::class);
        $v = $bs->revise($b1, $this->owner);
        $this->travel(2)->minutes();
        $t = $ts->create($this->transferData($this->firstLine($b1), $this->firstLine($b2), '100000.00'), $this->owner);
        $ts->submit($t, $this->owner);
        $ts->approve($t->refresh(), $this->cfo);
        $ts->apply($t->refresh(), $this->fin);
        $this->assertSame('1100000.00', $this->firstLine($b1)->refresh()->current_base_amount);

        $this->travel(2)->minutes();
        $bs->submit($v->refresh(), $this->owner);
        $bs->approve($v->refresh(), $this->cfo);
        $bs->baseline($v->refresh(), $this->fin);
        $this->assertSame('1100000.00', $this->firstLine($v->refresh())->current_base_amount);
    }
}
