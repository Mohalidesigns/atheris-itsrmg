<?php

namespace Tests\Feature\Finance;

use App\Http\Controllers\Finance\Concerns\PresentsBudgets;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetAdjustment;
use App\Models\Finance\BudgetCycle;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Organization;
use App\Models\User;
use App\Services\Finance\BudgetCycleService;
use App\Services\Finance\BudgetService;
use App\Services\Finance\BudgetTransferService;
use App\Services\Finance\BudgetWorkbook;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use App\Services\Finance\SupplementaryBudgetService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Backend slice smoke: FIN-02.01/.02/.04/.10/.11/.13 end to end through the services (QA slice adds the full acceptance suite). */
class BudgetBackendSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $cfo;

    private User $fin;

    private FiscalYear $fy;

    private CostCentre $cc;

    private CostCentre $cc2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->org = Organization::factory()->create(['currency' => 'NGN']);
        $mk = fn (string $role) => tap(User::factory()->create(['organization_id' => $this->org->id]), fn ($u) => $u->assignRole($role));
        $this->owner = $mk('IT Budget Owner');
        $this->cfo = $mk('Finance Approver');
        $this->fin = $mk('IT Finance Controller');
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $this->actingAs($this->fin);
        $this->fy = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2027', 'starts_on' => '2027-01-01', 'status' => 'open', 'is_current' => true], $this->fin);
        $this->cc = CostCentre::create(['code' => 'CC-1', 'name' => 'Ops', 'owner_id' => $this->owner->id]);
        $this->cc2 = CostCentre::create(['code' => 'CC-2', 'name' => 'Sec', 'owner_id' => $this->owner->id]);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1500, 'rate_date' => '2026-12-31', 'rate_type' => 'spot']);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1400, 'rate_date' => '2026-12-31', 'rate_type' => 'budget']);
    }

    private function baselined(CostCentre $cc, string $amount = '1200000.01', string $currency = 'NGN'): Budget
    {
        $cycles = app(BudgetCycleService::class);
        $cycle = BudgetCycle::where('fiscal_year_id', $this->fy->id)->first()
            ?? $cycles->create($this->org->id, ['fiscal_year_id' => $this->fy->id, 'name' => 'FY27', 'type' => 'annual', 'opens_on' => '2026-09-01', 'closes_on' => '2026-11-30'], $this->fin);
        if ($cycle->status === 'planned') {
            $cycles->transition($cycle, 'open', $this->fin);
        }
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($cycle, $cc, 'zero_based', $this->owner);
        $svc->addLine($b, [
            'description' => 'Finacle AMC', 'cost_pool_id' => Category::where('code', 'CP-SW')->value('id'), 'tower_id' => Category::where('code', 'TW-APP')->value('id'),
            'annual_amount' => $amount, 'currency' => $currency, 'phasing_method' => 'seasonal',
        ], $this->owner);
        $svc->submit($b->refresh(), $this->owner);
        $svc->approve($b->refresh(), $this->cfo);
        $svc->baseline($b->refresh(), $this->fin);

        return $b->refresh();
    }

    public function test_lifecycle_phasing_immutability_and_revision(): void
    {
        $b = $this->baselined($this->cc);
        $this->assertSame('baseline', $b->status);
        $line = $b->lines()->first();
        $this->assertSame('1200000.01', bcadd((string) $line->periods()->sum('amount'), '0', 2));
        $this->assertSame('1200000.01', (string) $b->total_base_amount);

        try {
            $b->update(['title' => 'x']);
            $this->fail('baseline must be immutable');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('workflow', $e->errors());
        }

        $this->actingAs($this->owner)->put(route('finance.budgets.lines.update', [$b, $line]), ['annual_amount' => '2000000'])->assertRedirect()->assertSessionHas('warning');
        $this->assertSame(2, Budget::where('budget_code', $b->budget_code)->count());
        $this->assertSame('1200000.01', (string) $line->refresh()->annual_amount);
    }

    public function test_submitter_and_owner_cannot_approve(): void
    {
        $cycle = app(BudgetCycleService::class)->create($this->org->id, ['fiscal_year_id' => $this->fy->id, 'name' => 'FY27', 'type' => 'annual', 'opens_on' => '2026-09-01', 'closes_on' => '2026-11-30'], $this->fin);
        app(BudgetCycleService::class)->transition($cycle, 'open', $this->fin);
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($cycle, $this->cc, 'zero_based', $this->fin);
        $svc->addLine($b, ['description' => 'x', 'cost_pool_id' => Category::where('code', 'CP-SW')->value('id'), 'tower_id' => Category::where('code', 'TW-APP')->value('id'), 'annual_amount' => '1200'], $this->fin);
        $svc->submit($b->refresh(), $this->fin);
        $this->expectException(ValidationException::class);
        $svc->approve($b->refresh(), $this->fin);
    }

    public function test_transfer_is_net_zero_and_supplementary_appends_a_line(): void
    {
        $b = $this->baselined($this->cc, '1200000.00');
        $b2 = $this->baselined($this->cc2, '600000.00');
        $from = $b->lines()->first();
        $t = app(BudgetTransferService::class);
        $pool = Category::where('code', 'CP-SW')->value('id');
        $tower = Category::where('code', 'TW-APP')->value('id');

        $tr = $t->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $b2->lines()->first()->id, 'amount' => '100000.01', 'reason' => 'shift'], $this->owner);
        $t->submit($tr, $this->owner);
        $this->assertSame('yes', $tr->refresh()->crosses_cost_centre);
        $this->expectExceptionOrFail(fn () => $t->apply($tr->refresh(), $this->cfo));
        $t->approve($tr->refresh(), $this->cfo);
        $t->apply($tr->refresh(), $this->cfo);
        $this->assertSame('0.00', bcadd((string) BudgetAdjustment::where('source_type', 'transfer')->sum('base_amount'), '0', 2));
        $this->assertSame('1099999.99', $from->refresh()->current_base_amount);

        $this->expectExceptionOrFail(fn () => $t->create(['fiscal_year_id' => $this->fy->id, 'from_line_id' => $from->id, 'to_line_id' => $b2->lines()->first()->id, 'amount' => '99999999', 'reason' => 'x'], $this->owner));

        $s = app(SupplementaryBudgetService::class);
        $r = $s->create(['fiscal_year_id' => $this->fy->id, 'cost_centre_id' => $this->cc->id, 'cost_pool_id' => $pool, 'tower_id' => $tower, 'title' => 'Fraud monitoring', 'currency' => 'NGN', 'amount' => '500000', 'justification' => 'CBN'], $this->owner);
        $s->submit($r, $this->owner);
        $s->approve($r->refresh(), $this->cfo);
        $s->apply($r->refresh(), $this->cfo);
        $line = BudgetLine::find($r->refresh()->resulting_line_id);
        $this->assertSame('supplementary', $line->origin);
        $this->assertSame('500000.00', $line->current_base_amount);
    }

    public function test_workbook_csv_round_trip_is_unchanged(): void
    {
        $this->baselined($this->cc); // ensures cycle
        $cycle = BudgetCycle::first();
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($cycle, $this->cc2, 'zero_based', $this->owner);
        $svc->addLine($b, ['description' => '=SUM(1)', 'cost_pool_id' => Category::where('code', 'CP-SW')->value('id'), 'tower_id' => Category::where('code', 'TW-APP')->value('id'), 'annual_amount' => '1000.01', 'currency' => 'USD'], $this->owner);
        $wb = app(BudgetWorkbook::class);
        [$header, $rows] = $wb->table($b->refresh());
        $path = tempnam(sys_get_temp_dir(), 'wb');
        $fh = fopen($path, 'w');
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($k) => $r[$k] ?? '', $header));
        }
        fclose($fh);
        $report = $wb->import($b, new UploadedFile($path, 'w.csv', 'text/csv', null, true), $this->owner);
        $this->assertSame(1, $report['unchanged'], json_encode($report));
        $this->assertSame(0, $report['updated'] + $report['created']);
        $this->assertSame('1400.00000000', (string) $b->lines()->first()->fx_rate);
    }

    public function test_incremental_next_year_from_baseline_and_diff(): void
    {
        $b = $this->baselined($this->cc, '1000000.00');
        $fy2 = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2028', 'starts_on' => '2028-01-01', 'status' => 'planning'], $this->fin);
        $cycles = app(BudgetCycleService::class);
        $c2 = $cycles->create($this->org->id, ['fiscal_year_id' => $fy2->id, 'name' => 'FY28', 'type' => 'annual', 'opens_on' => '2027-09-01', 'closes_on' => '2027-11-30'], $this->fin);
        $cycles->transition($c2, 'open', $this->fin);
        $svc = app(BudgetService::class);
        $n = $svc->createVersion($c2, $this->cc, 'incremental', $this->owner, ['increment_pct' => 10]);
        $l = $n->lines()->first();
        $this->assertSame('1100000.00', (string) $l->annual_amount);
        $this->assertSame('1000000.00', (string) $l->py_budget);
        $this->assertSame('10.00', $l->yoy_pct);
        $this->expectExceptionOrFail(fn () => $svc->createVersion($c2, $this->cc2, 'from_actuals', $this->owner));

        $v2 = $svc->revise($b, $this->owner);
        $svc->updateLine($v2->lines()->first(), ['annual_amount' => '1500000'], $this->owner);
        $d = $svc->diff($b, $v2->refresh());
        $this->assertSame('changed', $d['lines'][0]['change']);
        $this->assertSame('500000.00', $d['totals']['base_delta']);
    }

    public function test_amounts_are_masked_server_side_without_the_permission(): void
    {
        $b = $this->baselined($this->cc);
        $this->actingAs($this->cfo)->get(route('finance.budgets.show', $b))->assertInertia(fn ($p) => $p->where('can.viewAmounts', true)->where('budget.total_base_amount', '1200000.01'));
        $viewer = tap(User::factory()->create(['organization_id' => $this->org->id]), fn ($u) => $u->givePermissionTo('view it-finance'));
        $this->actingAs($this->owner)->get(route('finance.budgets.show', $b))->assertInertia(fn ($p) => $p->where('can.viewAmounts', true));
        $this->cc->update(['owner_id' => $viewer->id]);
        $this->actingAs($viewer)->get(route('finance.budgets.show', $b))
            ->assertInertia(fn ($p) => $p->where('can.viewAmounts', false)->where('budget.total_base_amount', null)->where('lines.0.annual_amount', null)->where('totals.base', null));
        $v2 = app(BudgetService::class)->revise($b, $this->owner);
        app(BudgetService::class)->updateLine($v2->lines()->first(), ['annual_amount' => '1500000'], $this->owner);
        $this->actingAs($viewer)->get(route('finance.budgets.show', $b))
            ->assertInertia(fn ($p) => $p->where('budget.amount_band', null)->where('budget.amount_band_label', null)->where('lines.0.driver_rate', null));
        $diff = $this->actingAs($viewer)->getJson(route('finance.budgets.diff', $b).'?against='.$v2->id)->assertOk()->json();
        $this->assertNull($diff['totals']['base_delta']);
        $this->assertNull($diff['totals']['capex_delta']);
        $this->assertNull($diff['totals']['opex_delta']);
        $this->assertStringNotContainsString('500000', json_encode($diff));
        $masked = (new class
        {
            use PresentsBudgets;

            public function run(array $d)
            {
                return $this->maskMoney($d);
            }
        })->run(['approval' => ['context' => ['amount_band' => '50m', 'band' => ['label' => 'x', 'min' => '1', 'max' => '2']]], 'driver_rate' => '3']);
        $this->assertSame(['label' => null, 'min' => null, 'max' => null], $masked['approval']['context']['band']);
        $this->assertNull($masked['approval']['context']['amount_band']);
        $this->assertNull($masked['driver_rate']);
        $this->actingAs($this->owner)->get(route('finance.cycles.index'))->assertOk();
        $this->actingAs($this->fin)->get(route('finance.cycles.index'))->assertInertia(fn ($p) => $p->where('baseCurrency', 'NGN'));
    }

    private function expectExceptionOrFail(callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException) {
            $this->assertTrue(true);

            return;
        }
        $this->fail('expected a refusal');
    }
}
