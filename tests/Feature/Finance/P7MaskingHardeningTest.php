<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Budget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/**
 * P7 WP-E: activity rows and the dashboard are masked for viewers without `view it-finance-amounts` (P7-16, P6-sec-M9, P2-sec-L5, P5-sec-L10, FIN-07.10).
 */
class P7MaskingHardeningTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
        $this->viewer = User::factory()->create(['organization_id' => $this->org->id]);
        $this->viewer->givePermissionTo(['view it-finance', 'close it-finance-periods']);
    }

    private function props($response): array
    {
        return $response->original->getData()['page']['props'];
    }

    public function test_investment_activity_changes_are_dropped_for_view_only_user(): void // FIN-07.10, P5-sec-L10
    {
        $inv = $this->approvedInvestment();
        $inv->update(['title' => 'Renamed', 'planned_ends_on' => '2028-01-31']);

        $full = $this->props($this->actingAs($this->fin)->get(route('finance.investments.show', [$inv, 'tab' => 'activity']))->assertOk());
        $this->assertNotEmpty($full['activity']);

        $p = $this->props($this->actingAs($this->viewer)->get(route('finance.investments.show', [$inv, 'tab' => 'activity']))->assertOk());
        $this->assertNotEmpty($p['activity']);
        foreach ($p['activity'] as $row) {
            $this->assertNull($row['changes']);
        }
    }

    public function test_budget_activity_changes_are_dropped_for_view_only_user(): void // FIN-07.10, P7-16
    {
        $budget = Budget::withoutGlobalScopes()->where('organization_id', $this->org->id)->first();
        if (! $budget) {
            $this->markTestSkipped('No budget in the monitoring fixture.');
        }
        $p = $this->props($this->actingAs($this->viewer)->get(route('finance.budgets.show', [$budget, 'tab' => 'activity']))->assertOk());
        foreach ($p['activity'] ?? [] as $row) {
            $this->assertNull($row['changes']);
        }
    }

    public function test_dashboard_investments_by_stage_hides_bac(): void // P6-sec-M9
    {
        $this->approvedInvestment();
        $d = $this->props($this->actingAs($this->viewer)->get(route('finance.dashboard'))->assertOk());
        $this->assertNotEmpty($d['metrics']['investments_by_stage']);
        foreach ($d['metrics']['investments_by_stage'] as $row) {
            $this->assertArrayHasKey('bac', $row);
            $this->assertNull($row['bac']);
        }
    }
}
