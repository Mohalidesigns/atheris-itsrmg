<?php

namespace Tests\Feature\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Contract;
use App\Models\Finance\FxRate;
use App\Models\Finance\SupplementaryRequest;
use App\Models\Organization;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\SupplementaryBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsBudgets;
use Tests\TestCase;

/** ATH-FIN-003 P1 "Deferred" carry-over (WP-D): M4 lock moves, M3 diff/KPI semantics, M1 foreign-currency top-ups. */
class BudgetDomainReviewRegressionTest extends TestCase
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

    private function rebaseline(Budget $v): Budget
    {
        $svc = app(BudgetService::class);
        $svc->submit($v, $this->owner);
        $svc->approve($v->refresh(), $this->cfo);

        return $svc->baseline($v->refresh(), $this->fin)->refresh();
    }

    private function suppData(array $over = []): array
    {
        return $over + ['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'title' => 'Top-up', 'currency' => 'NGN', 'amount' => '250000',
            'justification' => 'Price rise', 'cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-APP')];
    }

    private function applySupp(array $data, ?int $targetLineId): SupplementaryRequest
    {
        $svc = app(SupplementaryBudgetService::class);
        $r = $svc->create($data + ($targetLineId ? ['target_line_id' => $targetLineId] : []), $this->owner);
        $svc->submit($r, $this->owner);
        $svc->approve($r->refresh(), $this->cfo);

        return $svc->apply($r->refresh(), $this->fin);
    }

    /** FIN-07.06: a locked version never moves backwards, and its bookkeeping columns move only with a legal status move. */
    public function test_locked_versions_refuse_illegal_status_moves(): void
    {
        $svc = app(BudgetService::class);
        $approved = $this->draft($this->cc2);
        $svc->submit($approved, $this->owner2);
        $svc->approve($approved->refresh(), $this->cfo);
        $this->assertSame('approved', $approved->refresh()->status);
        $this->assertRefused(fn () => $approved->refresh()->forceFill(['status' => 'draft'])->save(), 'workflow', 'cannot move to draft');
        $this->assertRefused(fn () => $approved->refresh()->forceFill(['status' => 'baseline', 'baseline_slot' => null, 'baselined_at' => now(), 'superseded_by_id' => 1])->save(), 'workflow');

        $b = $this->baselined($this->cc);
        $this->assertRefused(fn () => $b->refresh()->forceFill(['status' => 'draft'])->save(), 'workflow', 'cannot move to draft');
        $this->assertRefused(fn () => $b->refresh()->forceFill(['status' => 'approved'])->save(), 'workflow', 'cannot move to approved');
        $this->assertRefused(fn () => $b->refresh()->forceFill(['status' => 'revised'])->save(), 'workflow', 'superseding it');
        $this->assertRefused(fn () => $b->refresh()->forceFill(['superseded_by_id' => $b->id])->save(), 'workflow');
        $this->assertRefused(fn () => $b->refresh()->forceFill(['baseline_slot' => 'x:y'])->save(), 'workflow');
        $this->assertSame('baseline', $b->refresh()->status);

        // the legal path still passes: revise, then baseline the successor supersedes v1
        $v2 = $svc->revise($b, $this->owner);
        $this->rebaseline($v2);
        $this->assertSame('revised', $b->refresh()->status);
        $this->assertSame($v2->id, $b->superseded_by_id);
        $this->assertNull($b->baseline_slot);
        $this->assertRefused(fn () => $b->refresh()->forceFill(['status' => 'baseline'])->save(), 'workflow', 'cannot move to baseline');
    }

    /** FIN-02.02: v1 against v3 follows source_line_id through v2 instead of calling every line added/removed. */
    public function test_diff_follows_lineage_across_three_versions(): void
    {
        $svc = app(BudgetService::class);
        $b = $this->draft();
        $svc->addLine($b, $this->lineData(['description' => 'Second line', 'annual_amount' => '300000']), $this->owner);
        $v1 = $this->rebaseline($b->refresh());
        $v2 = $this->rebaseline($svc->revise($v1, $this->owner));
        $v3 = $svc->revise($v2, $this->owner);
        $svc->updateLine($v3->lines()->orderBy('line_no')->get()[1], ['annual_amount' => '350000'], $this->owner);

        foreach ([$svc->diff($v1, $v3->refresh()), $svc->diff($v3, $v1)] as $d) {
            $this->assertSame(1, $d['a']['version_no']);
            $this->assertSame(3, $d['b']['version_no']);
            $by = collect($d['lines'])->groupBy('change');
            $this->assertFalse($by->has('added'));
            $this->assertFalse($by->has('removed'));
            $this->assertCount(1, $by['unchanged']);
            $this->assertCount(1, $by['changed']);
            $this->assertSame('50000.00', $by['changed'][0]['revision_delta']);
            $this->assertSame('50000.00', $by['changed'][0]['base_delta'], 'base_delta stays as an alias of revision_delta');
        }
    }

    /** M3b: a virement folded into the next version is not a revision edit. */
    public function test_diff_separates_revision_change_from_virements(): void
    {
        $b1 = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $from = $this->firstLine($b1);
        $t = app(BudgetTransferService::class);
        $tr = $t->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $this->firstLine($b2)->id, 'amount' => '100000.00', 'reason' => 'Rebalance'], $this->owner);
        $t->submit($tr, $this->owner);
        $t->approve($tr->refresh(), $this->cfo);
        $t->apply($tr->refresh(), $this->fin);

        $svc = app(BudgetService::class);
        $v2 = $svc->revise($b1->refresh(), $this->owner);
        $d = $svc->diff($b1, $v2->refresh());
        $row = $d['lines'][0];
        $this->assertSame('-100000.00', $row['a']['adjustments_base']);
        $this->assertSame('1100000.00', $row['a']['current_base']);
        $this->assertSame('1100000.00', $row['b']['original_base']);
        $this->assertSame('0.00', $row['revision_delta']);
        $this->assertSame('0.00', $row['current_delta']);
        $this->assertSame('unchanged', $row['change']);
        $this->assertSame([], $row['periods']);
        $this->assertSame('0.00', $d['totals']['revision_delta']);
        $this->assertSame('-100000.00', $d['totals']['original_delta'], 'the folded virement lowers the new original, not the revision');
        $this->assertSame('-100000.00', $d['totals']['adjustments']['a']);

        $svc->updateLine($v2->lines()->first(), ['annual_amount' => '1150000', 'phasing_method' => 'equal'], $this->owner);
        $d = $svc->diff($b1, $v2->refresh());
        $this->assertSame('50000.00', $d['lines'][0]['revision_delta']);
        $this->assertSame('50000.00', $d['lines'][0]['current_delta']);
        $this->assertSame('changed', $d['lines'][0]['change']);
        $this->assertSame('50000.00', $d['totals']['current_delta']);
    }

    /** M3c/M3d: an applied top-up raises the Index KPI and the current period / priority / CapEx-OpEx totals. */
    public function test_index_kpi_and_totals_use_current_amounts(): void
    {
        $b = $this->baselined($this->cc, '1200000.00');
        $this->applySupp($this->suppData(), $this->firstLine($b)->id);

        $this->actingAs($this->fin)->get(route('finance.budgets.index'))->assertOk()->assertInertia(fn ($p) => $p
            ->where('summary.total_baseline_base', '1200000.00')->where('summary.total_baseline_adjustments_base', '250000.00')
            ->where('summary.total_baseline_current_base', '1450000.00')
            ->where('budgets.0.adjustments_base', '250000.00')->where('budgets.0.current_base_amount', '1450000.00'));

        $resp = $this->actingAs($this->fin)->get(route('finance.budgets.show', $b))->assertOk();
        $totals = $resp->viewData('page')['props']['totals'];
        $this->assertSame('1200000.00', $totals['base']);
        $this->assertSame('1450000.00', $totals['current_base']);
        $this->assertSame('1450000.00', $this->sum($totals['by_period']), 'by_period is current');
        $this->assertSame('1200000.00', $this->sum($totals['by_period_original']));
        $this->assertSame('1450000.00', $this->sum($totals['by_priority']));
        $this->assertSame('1450000.00', $this->sum($totals['by_rgt']));
        $this->assertSame('1450000.00', bcadd($totals['capex_current'], $totals['opex_current'], 2));
        $line = $resp->viewData('page')['props']['lines'][0];
        $this->assertSame('1450000.00', $this->sum($line['current_base_amounts']));
    }

    /** M1a: the request is re-quoted at submit, so the DoA snapshot uses the rate of the day it was submitted. */
    public function test_supplementary_is_requoted_at_submit(): void
    {
        $this->baselined($this->cc, '1200000.00');
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(SupplementaryBudgetService::class);
        $r = $svc->create($this->suppData(['currency' => 'USD', 'amount' => '1000']), $this->owner);
        $before = (string) $r->base_amount;

        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1600, 'rate_date' => now()->toDateString(), 'rate_type' => 'budget']);
        $svc->submit($r->refresh(), $this->owner);
        $r->refresh();

        $this->assertNotSame('1600000.00', $before);
        $this->assertSame('1600000.00', (string) $r->base_amount);
        $this->assertSame('budget', $r->fx_rate_type);
        $ctx = ApprovalRequest::where('subject_type', SupplementaryRequest::class)->where('subject_id', $r->id)->latest('id')->firstOrFail()->context;
        $ctx = is_string($ctx) ? json_decode($ctx, true) : $ctx;
        $this->assertSame('1600000.00', $ctx['base_amount']);
    }

    /** M1b: a USD top-up books exactly the USD amount on a USD line, even when its rate differs from the line's. */
    public function test_foreign_currency_top_up_books_exact_line_amount(): void
    {
        $b = $this->baselined($this->cc, '100000.00', 'USD');
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1520, 'rate_date' => now()->toDateString(), 'rate_type' => 'budget']);
        $line = $this->firstLine($b);
        $this->assertNotSame('1520.00000000', (string) $line->fx_rate, 'the line carries an older planning rate');

        $r = $this->applySupp($this->suppData(['currency' => 'USD', 'amount' => '18000']), $line->id);
        $rows = BudgetAdjustment::where('source_type', 'supplementary')->where('source_id', $r->id)->get();
        $this->assertSame('18000.00', $this->sum($rows->pluck('amount')));
        $this->assertSame('27360000.00', $this->sum($rows->pluck('base_amount')));

        // folding into the next version keeps the USD amount exact
        $v2 = app(BudgetService::class)->revise($b->refresh(), $this->owner);
        $this->assertSame('118000.00', (string) $this->firstLine($v2)->annual_amount);
    }

    /** M1b: a brand-new supplementary line is created in the request currency, not the base currency. */
    public function test_new_supplementary_line_takes_request_currency(): void
    {
        $this->baselined($this->cc, '1200000.00');
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1520, 'rate_date' => now()->toDateString(), 'rate_type' => 'budget']);
        $r = $this->applySupp($this->suppData(['currency' => 'USD', 'amount' => '18000', 'title' => 'FX line']), null);
        $line = BudgetLine::findOrFail($r->resulting_line_id);
        $this->assertSame('USD', $line->currency);
        $this->assertSame('0.00', (string) $line->annual_amount);
        $rows = BudgetAdjustment::where('source_type', 'supplementary')->where('source_id', $r->id)->get();
        $this->assertSame('18000.00', $this->sum($rows->pluck('amount')));
        $this->assertSame('27360000.00', $this->sum($rows->pluck('base_amount')));
    }

    /** D5 (FIN-01.09): a budget line links to a contract of the same bank; foreign and unknown ones are refused. */
    public function test_budget_line_accepts_same_bank_contract_and_refuses_foreign(): void
    {
        $svc = app(BudgetService::class);
        $mk = fn (int $orgId, string $status) => Contract::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'contract_code' => 'CON-T'.$orgId.$status, 'title' => 'Finacle AMC '.$status, 'type' => 'support_maintenance', 'commenced_on' => '2027-01-01',
            'ends_on' => '2027-12-31', 'term_months' => 12, 'renewal_window_opens_on' => '2027-07-01', 'notice_deadline_on' => '2027-09-30', 'currency' => 'NGN', 'status' => $status,
        ]);
        $own = $mk($this->org->id, 'active');
        $draftContract = $mk($this->org->id, 'draft');
        $mk($this->org->id, 'terminated');
        $foreign = $mk(Organization::factory()->create()->id, 'active');

        $b = $this->draft();
        $line = $this->firstLine($b);
        $this->assertRefused(fn () => $svc->updateLine($line, ['contract_id' => $foreign->id], $this->owner), 'contract_id');
        $this->assertRefused(fn () => $svc->updateLine($line, ['contract_id' => 999999], $this->owner), 'contract_id');
        $this->assertRefused(fn () => $svc->updateLine($line, ['investment_id' => 1], $this->owner), 'investment_id');

        $svc->updateLine($line, ['contract_id' => $own->id], $this->owner);
        $this->assertSame($own->id, $line->refresh()->contract_id);
        $svc->updateLine($line, ['justification' => 'untouched link'], $this->owner);
        $this->assertSame($own->id, $line->refresh()->contract_id, 'an edit that does not mention the link keeps it');

        $contracts = $this->actingAs($this->owner)->get(route('finance.budgets.edit', $b))->assertOk()->viewData('page')['props']['availableDimensions']['contracts'] ?? null;
        $this->assertNotNull($contracts);
        $this->assertSame(['CON-T'.$this->org->id.'active', 'CON-T'.$this->org->id.'draft'], array_column($contracts, 'contract_code'));
        $this->assertNotContains($foreign->id, array_column($contracts, 'id'));
    }
}
