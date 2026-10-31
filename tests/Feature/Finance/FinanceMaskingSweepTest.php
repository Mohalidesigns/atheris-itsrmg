<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\StageGate;
use App\Models\User;
use App\Services\Finance\FinanceMasking;
use App\Services\Finance\Reports\FinanceReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\Finance\Concerns\BuildsFinOps;
use Tests\TestCase;

/**
 * Amount masking sweep (P7 H2, FIN-07.10): seed records with distinctive amounts, then prove that a viewer without
 * `view it-finance-amounts` never receives them from any finance page, report export, board section or KRI payload,
 * while a viewer with the permission does (so the sweep is not vacuous). The check is key-agnostic: it searches the
 * serialised response for the digits.
 */
class FinanceMaskingSweepTest extends TestCase
{
    use BuildsFinOps, RefreshDatabase;

    /** Distinctive amounts (digits only); each is searched with and without thousands separators. */
    private const NEEDLES = ['7654321', '4567891', '3456789', '2345671', '8765432'];

    private User $blind;

    private User $sighted;

    /** @var array<string,string> */
    private array $showUrls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFinOps();

        $this->actingAs($this->fin);
        $contract = $this->contract(['title' => 'Mask AMC', 'contract_value' => '7654321.00']);
        $expense = $this->expense(['amount' => '4567891.00', 'title' => 'Mask expense']);
        $invoice = $this->invoice(['subtotal' => '3456789.00']);
        $inv = $this->approvedInvestment(['title' => 'Mask programme'], '2345671');
        $budget = $this->baselined($this->cc2, '8765432.00');
        $renewal = $this->submittedRenewal($contract, '7654321');

        $gate = StageGate::withoutGlobalScopes()->where('investment_id', $inv->id)->latest('id')->first();
        $this->showUrls = array_filter([
            route('finance.contracts.show', $contract), route('finance.contracts.show', [$contract, 'tab' => 'activity']),
            route('finance.expenses.show', $expense), route('finance.invoices.show', $invoice),
            route('finance.investments.show', $inv), route('finance.investments.show', [$inv, 'tab' => 'activity']),
            route('finance.budgets.show', $budget), route('finance.budgets.show', [$budget, 'tab' => 'activity']),
            route('finance.renewals.show', $renewal),
            $gate ? route('finance.stage-gates.show', $gate) : null,
        ]);

        $this->blind = User::factory()->create(['organization_id' => $this->org->id]);
        // 'close it-finance-periods' lifts the cost-centre row restriction without granting amounts
        $this->blind->givePermissionTo(['view it-finance', 'export it-finance', 'close it-finance-periods']);
        $this->sighted = User::factory()->create(['organization_id' => $this->org->id]);
        $this->sighted->givePermissionTo(['view it-finance', 'export it-finance', 'close it-finance-periods', 'view it-finance-amounts']);
    }

    private function body($response): string
    {
        $base = $response->baseResponse;
        $text = $base instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();

        return str_replace(['\\u20a6'], '', $text);
    }

    /** @return array<int,string> */
    private function leaks(string $text): array
    {
        return array_values(array_filter(self::NEEDLES, fn ($n) => str_contains($text, $n) || str_contains($text, number_format((float) $n)) || str_contains($text, substr($n, 0, 1).','.substr($n, 1, 3).','.substr($n, 4))));
    }

    /** @return array<int,string> */
    private function pages(): array
    {
        $skip = ['finance.calendar.ics', 'finance.calendar.export', 'finance.expenses.availability', 'finance.monitor.tco.detail', 'finance.monitor.showback.export', 'finance.monitor.bva.export', 'finance.reports.show'];
        $urls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $r) => str_starts_with((string) $r->getName(), 'finance.') && in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{') && ! in_array($r->getName(), $skip, true))
            ->map(fn (RouteDefinition $r) => route($r->getName()))->values()->all();

        return array_merge($urls, $this->showUrls, [route('finance.monitor.bva', ['view' => 'fy']), route('finance.calendar', ['view' => 'list'])]);
    }

    private function sweep(User $u): array
    {
        $this->actingAs($u);
        $found = [];
        foreach ($this->pages() as $url) {
            $r = $this->get($url);
            $this->assertLessThan(500, $r->getStatusCode(), $url);
            foreach ($this->leaks($this->body($r)) as $n) {
                $found[$n][] = $url;
            }
        }

        return $found;
    }

    /** Control: with the permission the same pages carry the seeded amounts, so the blind sweep below proves something. */
    public function test_a_viewer_with_the_permission_sees_the_amounts(): void
    {
        $found = $this->sweep($this->sighted);
        $this->assertGreaterThanOrEqual(4, count($found), 'The control sweep found too few seeded amounts: '.implode(',', array_keys($found)));
    }

    /** FIN-07.10, H2 */
    public function test_a_viewer_without_the_permission_receives_no_seeded_amount_on_any_page(): void
    {
        $found = $this->sweep($this->blind);
        $report = collect($found)->map(fn ($urls, $n) => "{$n}: ".implode(' ', array_unique($urls)))->implode("\n");
        $this->assertSame([], $found, "Amounts leaked to a viewer without view it-finance-amounts:\n{$report}");
    }

    /** FIN-07.07, FIN-07.10, H2: CSV exports carry no seeded amount for the same viewer. */
    public function test_report_exports_are_masked(): void
    {
        $this->actingAs($this->blind);
        $leaks = [];
        foreach (array_keys(app(FinanceReports::class)->available()) as $key) {
            $r = $this->get(route('finance.reports.show', ['report' => $key, 'format' => 'csv']));
            $r->assertOk();
            foreach ($this->leaks($this->body($r)) as $n) {
                $leaks[] = "{$key}:{$n}";
            }
        }
        $this->assertSame([], $leaks, 'Report CSVs leak: '.implode(', ', $leaks));
    }

    /** FIN-07.10: calendar CSV carries no amount for the viewer. */
    public function test_calendar_csv_is_masked(): void
    {
        $this->actingAs($this->blind);
        $r = $this->get(route('finance.calendar.export'));
        $this->assertSame([], $this->leaks($this->body($r)));
    }

    /** FIN-07.10, P6-sec-M9: the board sections and money KRI payloads blank their amounts. */
    public function test_board_content_and_kri_payloads_are_masked(): void
    {
        $content = [
            ['key' => 'it_financial_position', 'data' => ['budget_total' => 7654321, 'actual_ytd' => 4567891, 'top_variances' => [['name' => 'x', 'variance_pct' => 12.5]]]],
            ['key' => 'it_investment_portfolio', 'data' => ['bac_total' => 2345671, 'by_stage' => [['stage' => 'approved', 'bac' => 2345671, 'count' => 1]]]],
            ['key' => 'kri_panel', 'data' => ['kris' => [['code' => 'KRI-FIN-VAR', 'value' => 8765432, 'trend' => [['value' => 3456789]]], ['code' => 'KRI-OTHER', 'value' => 5]]]],
        ];
        $masking = app(FinanceMasking::class);
        $this->assertSame([], $this->leaks(json_encode($masking->boardContent($content, $this->blind))));
        $this->assertNotSame([], $this->leaks(json_encode($masking->boardContent($content, $this->sighted))));
        $kri = $masking->boardContent($content, $this->blind)[2]['data']['kris'];
        $this->assertNull($kri[0]['value']);
        $this->assertSame(5, $kri[1]['value'], 'non-money KRIs keep their value');

        foreach (config('finance.masked_kri_codes') as $code) {
            $this->assertNull($masking->maskKriPayload(['code' => $code, 'value' => 9], null)['value']);
        }
    }
}
