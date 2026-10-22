<?php

namespace Tests\Feature\Finance\Concerns;

use App\Models\Finance\Budget;
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
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\FiscalCalendarService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/**
 * Shared fixtures for the P1 budgeting tests: one NGN bank with a 2027 fiscal year, two cost centres,
 * a budget owner, a CFO-type approver and a finance controller. Everything runs through the services
 * so the tests exercise the same code paths as the demo seeder.
 */
trait BuildsBudgets
{
    protected Organization $org;

    protected User $owner;

    protected User $owner2;

    protected User $cfo;

    protected User $fin;

    protected FiscalYear $fy;

    protected CostCentre $cc;

    protected CostCentre $cc2;

    protected function bootBudgets(?Organization $org = null): void
    {
        $this->withoutVite();
        if ($org === null) { // a caller that passes its own bank has already seeded the roles
            $this->seed(RolesAndPermissionsSeeder::class);
        }
        $this->org = $org ?? Organization::factory()->create(['currency' => 'NGN']);
        $this->owner = $this->userWith('IT Budget Owner');
        $this->owner2 = $this->userWith('IT Budget Owner');
        $this->cfo = $this->userWith('Finance Approver', ['job_title' => 'Chief Financial Officer']);
        $this->fin = $this->userWith('IT Finance Controller', ['job_title' => 'IT Finance Controller']);
        app(FinanceSettings::class)->ensureDefaults($this->org->id);
        $this->actingAs($this->fin);
        $this->fy = app(FiscalCalendarService::class)->createYear($this->org->id, ['year_label' => 'FY2027', 'starts_on' => '2027-01-01', 'status' => 'open', 'is_current' => true], $this->fin);
        $this->cc = CostCentre::create(['code' => 'CC-1', 'name' => 'Operations', 'owner_id' => $this->owner->id]);
        $this->cc2 = CostCentre::create(['code' => 'CC-2', 'name' => 'Security', 'owner_id' => $this->owner2->id]);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1500, 'rate_date' => '2026-01-01', 'rate_type' => 'spot']);
        FxRate::create(['from_currency' => 'USD', 'to_currency' => 'NGN', 'rate' => 1400, 'rate_date' => '2026-12-31', 'rate_type' => 'budget']);
    }

    protected function userWith(string $role, array $attrs = [], ?int $orgId = null): User
    {
        $u = User::factory()->create(['organization_id' => $orgId ?? $this->org->id] + $attrs);
        $u->assignRole($role);

        return $u;
    }

    protected function cat(string $code): int
    {
        return (int) Category::where('code', $code)->value('id');
    }

    protected function openCycle(string $type = 'annual', ?FiscalYear $fy = null): BudgetCycle
    {
        $fy ??= $this->fy;
        $cycles = app(BudgetCycleService::class);
        $cycle = BudgetCycle::where('fiscal_year_id', $fy->id)->where('type', $type)->first()
            ?? $cycles->create($this->org->id, ['fiscal_year_id' => $fy->id, 'name' => "{$fy->year_label} {$type}", 'type' => $type, 'opens_on' => '2026-09-01', 'closes_on' => '2026-11-30'], $this->fin);
        if ($cycle->status === 'planned') {
            $cycles->transition($cycle, 'open', $this->fin);
        }

        return $cycle->refresh();
    }

    /** @return array<string,mixed> */
    protected function lineData(array $over = []): array
    {
        return $over + [
            'description' => 'Finacle AMC', 'cost_pool_id' => $this->cat('CP-SW'), 'tower_id' => $this->cat('TW-APP'),
            'annual_amount' => '1200000.00', 'currency' => 'NGN', 'phasing_method' => 'equal',
        ];
    }

    protected function draft(?CostCentre $cc = null, string $amount = '1200000.00', string $currency = 'NGN', ?User $by = null, array $line = []): Budget
    {
        $cc ??= $this->cc;
        $by ??= $cc->owner_id ? User::find($cc->owner_id) : $this->owner;
        $svc = app(BudgetService::class);
        $b = $svc->createVersion($this->openCycle(), $cc, 'zero_based', $by);
        $svc->addLine($b, $this->lineData(['annual_amount' => $amount, 'currency' => $currency] + $line), $by);

        return $b->refresh();
    }

    protected function submitted(?CostCentre $cc = null, string $amount = '1200000.00'): Budget
    {
        $b = $this->draft($cc, $amount);

        return app(BudgetService::class)->submit($b, User::find($b->owner_id));
    }

    /** Draft, submitted, approved by the CFO under the unconfigured default, baselined by Finance. */
    protected function baselined(?CostCentre $cc = null, string $amount = '1200000.00', string $currency = 'NGN', array $line = []): Budget
    {
        $svc = app(BudgetService::class);
        $b = $this->draft($cc, $amount, $currency, null, $line);
        $svc->submit($b, User::find($b->owner_id));
        $svc->approve($b->refresh(), $this->cfo);

        return $svc->baseline($b->refresh(), $this->fin)->refresh();
    }

    protected function assertRefused(callable $fn, ?string $key = null, ?string $contains = null): ValidationException
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            if ($key !== null) {
                $this->assertArrayHasKey($key, $e->errors(), 'Expected a refusal on "'.$key.'" but got '.json_encode($e->errors()));
            }
            if ($contains !== null) {
                $this->assertStringContainsString($contains, implode(' ', array_merge(...array_values($e->errors()))));
            }

            return $e;
        }
        $this->fail('Expected a ValidationException'.($key ? " on {$key}" : '').'.');
    }

    protected function firstLine(Budget $b): BudgetLine
    {
        return $b->lines()->orderBy('line_no')->firstOrFail();
    }
}
