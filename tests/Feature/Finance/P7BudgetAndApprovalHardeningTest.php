<?php

namespace Tests\Feature\Finance;

use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalStep;
use App\Models\Finance\BudgetTarget;
use App\Models\Finance\SupplementaryRequest;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetPhasing;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\BudgetWorkbook;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\FinanceApprovalNotifier;
use App\Services\Finance\FinanceApprovals;
use App\Services\Finance\FinanceApprovalSweeper;
use App\Services\Finance\FinancePolicySeeder;
use App\Services\Finance\SupplementaryBudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/**
 * P7 WP-A: budgets, approvals wrapper and settings masking (P5-M12/P7-3, P6-sec-L6, P1-sec-M3, P1-M6/P7-4, P1-L1..L5, P1-M5b, P7-15, P5-sec-L3, P0-S9).
 */
class P7BudgetAndApprovalHardeningTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    private function submittedSupplementary(): SupplementaryRequest
    {
        $this->baselined($this->cc, '1200000.00');
        FinancePolicySeeder::ensureDefaults($this->org->id);
        $svc = app(SupplementaryBudgetService::class);
        $r = $svc->create(['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-SEC'),
            'title' => 'Fraud monitoring', 'is_capex' => false, 'currency' => 'NGN', 'amount' => '5000', 'justification' => 'Directive'], $this->owner);

        return $svc->submit($r, $this->owner);
    }

    public function test_job_title_match_is_segment_exact(): void // P5-M12 / P7-3
    {
        $m = fn ($t, $r) => FinanceApprovals::jobTitleMatches($t, $r);
        $this->assertTrue($m('Head of IT', 'Head of IT'));
        $this->assertTrue($m('head of it', 'Head of IT'));
        $this->assertTrue($m('Managing Director/CEO', 'Managing Director'));
        $this->assertTrue($m('Chief Executive Officer and Managing Director', 'Managing Director'));
        $this->assertTrue($m('Treasurer, Chief Financial Officer', 'Chief Financial Officer'));
        $this->assertFalse($m('Head of IT Operations', 'Head of IT'));
        $this->assertFalse($m('Head of IT Governance', 'Head of IT'));
        $this->assertFalse($m('Deputy Managing Director', 'Managing Director'));
        $this->assertFalse($m('Head of IT', ''));
    }

    public function test_non_matching_title_cannot_decide_a_job_title_stage(): void // P5-M12 / P7-3
    {
        $r = $this->submittedSupplementary();
        $svc = app(SupplementaryBudgetService::class);
        $ops = $this->userWith('Finance Approver', ['job_title' => 'Head of IT Operations']);
        $this->assertRefused(fn () => $svc->approve($r->refresh(), $ops), 'approval', 'job title');
        $this->assertRefused(fn () => $svc->reject($r->refresh(), $ops, 'No'), 'approval', 'job title');
        $this->assertSame('submitted', $r->refresh()->status);

        $head = $this->userWith('Finance Approver', ['job_title' => 'Head of IT']);
        $this->assertStringContainsString('awaiting', strtolower($svc->approve($r->refresh(), $head) ?: ''));
    }

    public function test_trail_nulls_requires_flags_without_amount_permission(): void // P6-sec-L6
    {
        $r = $this->submittedSupplementary();
        $req = ApprovalRequest::withoutGlobalScopes()->where('subject_id', $r->id)->latest('id')->firstOrFail();
        $ctx = $req->context;
        $ctx['conditions']['requires_cfo'] = 'yes';
        $ctx['requires_board'] = 'yes';
        $req->forceFill(['context' => $ctx])->save();
        $viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $viewer->givePermissionTo('view it-finance');

        $masked = app(FinanceApprovals::class)->trail(SupplementaryBudgetService::PROCESS, $r, $viewer);
        $this->assertNull($masked['context']['conditions']['requires_cfo']);
        $this->assertNull($masked['context']['requires_board']);
        $full = app(FinanceApprovals::class)->trail(SupplementaryBudgetService::PROCESS, $r, $this->fin);
        $this->assertSame('yes', $full['context']['conditions']['requires_cfo']);
    }

    public function test_targets_index_is_scoped_to_visible_cost_centres(): void // P1-sec-M3
    {
        $cycle = $this->openCycle();
        $cycles = app(BudgetCycleService::class);
        $cycles->saveTarget($cycle, ['cost_centre_id' => $this->cc->id, 'capex_ceiling' => '1000'], $this->fin);
        $cycles->saveTarget($cycle, ['cost_centre_id' => $this->cc2->id, 'capex_ceiling' => '2000'], $this->fin);
        $cycles->saveTarget($cycle, ['capex_ceiling' => '9000'], $this->fin);

        $mine = $this->props($this->actingAs($this->owner)->get(route('finance.targets.index'))->assertOk());
        $this->assertCount(1, $mine['targets']);
        $this->assertSame($this->cc->id, $mine['targets'][0]['cost_centre']['id']);
        $this->assertContains($this->cc->id, array_column($mine['costCentres'], 'id'));
        $this->assertNotContains($this->cc2->id, array_column($mine['costCentres'], 'id'));

        $all = $this->props($this->actingAs($this->fin)->get(route('finance.targets.index'))->assertOk());
        $this->assertCount(BudgetTarget::count(), $all['targets']);
    }

    public function test_transfer_between_legal_entities_is_refused(): void // P1-M6 / P7-4
    {
        $from = $this->firstLine($this->baselined($this->cc, '1200000.00'));
        $to = $this->firstLine($this->baselined($this->cc2, '600000.00'));
        $a = LegalEntity::create(['organization_id' => $this->org->id, 'code' => 'LE-A', 'name' => 'Bank Ltd']);
        $b = LegalEntity::create(['organization_id' => $this->org->id, 'code' => 'LE-B', 'name' => 'Capital Ltd']);
        \DB::table('fin_budget_lines')->where('id', $from->id)->update(['legal_entity_id' => $a->id]);
        \DB::table('fin_budget_lines')->where('id', $to->id)->update(['legal_entity_id' => $b->id]);
        $data = ['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $to->id, 'amount' => '1000', 'reason' => 'Rebalance'];
        $svc = app(BudgetTransferService::class);

        $this->assertRefused(fn () => $svc->create($data, $this->owner), 'to_line_id', 'legal entities');
        \DB::table('fin_budget_lines')->where('id', $to->id)->update(['legal_entity_id' => $a->id]);
        $this->assertSame('draft', $svc->create($data, $this->owner)->status);
    }

    public function test_spread_never_overshoots_or_goes_negative(): void // P1-L2
    {
        $out = app(BudgetPhasing::class)->spread('0.05', array_fill(0, 10, '1'));
        $this->assertSame('0.05', array_reduce($out, fn ($c, $v) => bcadd($c, $v, 2), '0'));
        foreach ($out as $v) {
            $this->assertGreaterThanOrEqual(0, bccomp($v, '0', 2), "share {$v} is negative");
        }
        $this->assertSame('100.00', array_reduce(app(BudgetPhasing::class)->spread('100.00', ['1', '1', '1']), fn ($c, $v) => bcadd($c, $v, 2), '0'));
    }

    public function test_workbook_guard_covers_tab_cr_and_non_numeric_cells(): void // P1-L3
    {
        $wb = app(BudgetWorkbook::class);
        $safe = new \ReflectionMethod($wb, 'safe');
        $unsafe = new \ReflectionMethod($wb, 'unsafe');
        foreach (["\tcmd", "\rcmd", '=SUM(A1)', '@x', '+1+2', '-cmd'] as $v) {
            $g = $safe->invoke($wb, $v, true);
            $this->assertSame("'".$v, $g, 'guarded');
            $this->assertSame($v, $unsafe->invoke($wb, $g), 'round trip');
        }
        $this->assertSame('-5', $safe->invoke($wb, '-5', true), 'numbers stay numbers');
        $this->assertSame("'quoted", $unsafe->invoke($wb, "'quoted"), 'an ordinary leading apostrophe is kept');
    }

    public function test_sweeps_survive_a_failing_item(): void // P1-L5
    {
        $r = $this->submittedSupplementary();
        ApprovalStep::withoutGlobalScopes()->where('status', 'pending')->update(['due_at' => now()->subDay()]);
        $notifier = $this->createMock(FinanceApprovalNotifier::class);
        $notifier->method('approversFor')->willThrowException(new \RuntimeException('boom'));
        $this->assertSame(0, (new FinanceApprovalSweeper($notifier))->sweep());
        $this->assertNotNull($r->refresh());
    }

    public function test_money_tables_cover_targets_investments_and_business_cases(): void // P1-L1
    {
        foreach (['fin_budget_targets', 'fin_investments', 'fin_business_cases'] as $t) {
            $this->assertContains($t, config('finance.money_tables'));
        }
        $this->makeInvestment();
        $this->assertNotNull(app(CurrencyService::class)->baseLockedReason($this->org->id));
    }

    public function test_demo_seeder_has_no_postgres_only_operator(): void // P1-M5b
    {
        $this->assertStringNotContainsString("'ilike'", file_get_contents(base_path('database/seeders/FinanceDemoSeeder.php')));
    }

    public function test_cross_module_pickers_need_module_view_permission(): void // P5-sec-L2 / P7-15
    {
        $probe = new class
        {
            use PresentsBudgets;

            public function dims(int $orgId): array
            {
                return $this->dimensionsPayload($orgId, 'NGN');
            }
        };
        $viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $viewer->givePermissionTo('view it-finance');
        $this->actingAs($viewer);
        $d = $probe->dims($this->org->id);
        $this->assertSame([], $d['risks']);
        $this->assertSame([], $d['eaApplications']);
        $this->assertSame([], $d['businessServices']);
    }

    public function test_save_line_refuses_investment_outside_visible_cost_centres(): void // P5-sec-L3
    {
        $other = $this->makeInvestment(['cost_centre_id' => $this->cc2->id, 'manager_id' => $this->owner2->id]);
        $b = $this->draft($this->cc);
        $this->assertRefused(fn () => app(BudgetService::class)->saveLine($b, null, $this->lineData(['investment_id' => $other->id]), $this->owner), 'investment_id');
        $own = $this->makeInvestment(['cost_centre_id' => $this->cc->id]);
        $line = app(BudgetService::class)->saveLine($b->refresh(), null, $this->lineData(['investment_id' => $own->id]), $this->owner);
        $this->assertSame($own->id, (int) $line->investment_id);
    }

    public function test_settings_thresholds_are_null_without_amount_permission(): void // P0-S9
    {
        $viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $viewer->givePermissionTo('view it-finance');
        $p = $this->props($this->actingAs($viewer)->get(route('finance.settings', ['tab' => 'doa']))->assertOk());
        foreach (['variance_abs_floor', 'renewal_cfo_from', 'invoice_sod_from', 'invoice_sod_default', 'match_tolerance_abs'] as $k) {
            $this->assertArrayHasKey($k, $p['settings']);
            $this->assertNull($p['settings'][$k], $k);
        }
        foreach ($p['settings']['doa_bands'] as $band) {
            $this->assertNull($band['min']);
            $this->assertNull($band['max']);
        }
        $this->assertNull($p['finopsPolicy']['cloud_anomaly_abs_floor']);

        $full = $this->props($this->actingAs($this->fin)->get(route('finance.settings', ['tab' => 'doa']))->assertOk());
        $this->assertNotNull($full['settings']['variance_abs_floor']);
    }
}
