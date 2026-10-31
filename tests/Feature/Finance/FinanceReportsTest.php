<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\VarianceNote;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\Reports\FinanceReports;
use App\Services\Finance\VarianceService;
use App\Services\Reports\ReportFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Feature\Finance\Concerns\BuildsMonitoring;
use Tests\TestCase;

/**
 * ATH-FIN-001 §12 FIN-07.07 (the eight finance reports export as XLSX, CSV and PDF and can be scheduled), FIN-07.10 (amounts masked),
 * FIN-04.11 (ReportFigures) and tenancy. Fixture: BuildsMonitoring plus a red variance with commentary, a vendor posting, an unbudgeted
 * posting, a live contract, an open PO and a submitted invoice.
 */
class FinanceReportsTest extends TestCase
{
    use BuildsMonitoring, RefreshDatabase;

    private const KEYS = ['bva', 'budget_book', 'variance_commentary', 'commitments', 'invoice_ageing', 'unbudgeted', 'contract_register', 'vendor_spend'];

    private const TYPES = ['csv' => 'text/csv', 'xlsx' => 'spreadsheetml.sheet', 'pdf' => 'application/pdf'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMonitoring();
        foreach ([1, 2, 3] as $n) {
            $this->postActual($this->monLines[0], $this->periodSeq($n), '1200000.00');
        }
        $this->postActual($this->monLines[0], $this->periodSeq(1), '250000.00', ['vendor_id' => $this->vendor->id]);
        $this->postActual($this->monLines[1], $this->periodSeq(2), '400000.00', ['match_status' => 'unbudgeted', 'budget_line_id' => null, 'vendor_id' => $this->vendor->id, 'source_ref' => 'UNB-1']);
        app(VarianceService::class)->evaluate($this->periodSeq(3), $this->fin);
        $note = VarianceNote::withoutGlobalScopes()->where('severity', 'red')->where('direction', 'over')->firstOrFail();
        app(VarianceService::class)->explain($note, '=HYPERLINK("http://example.test","click") licence true-up', '@Renegotiate', $this->requester);
        $this->contract(['title' => 'Switch AMC']);
        $this->po = $this->po();
        app(InvoiceService::class)->submit($this->invoice(['vendor_invoice_no' => 'AGE-1']), $this->procurement);
    }

    private PurchaseOrder $po;

    private function viewerWith(array $permissions): User
    {
        $u = User::factory()->create(['organization_id' => $this->org->id]);
        $u->givePermissionTo($permissions);

        return $u;
    }

    /** @return list<array<string,string>> */
    private function csvRows(string $csv): array
    {
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
        $head = array_shift($lines);

        return array_map(fn ($l) => array_combine($head, $l), $lines);
    }

    private function download(string $key, string $format, ?User $as = null, array $query = [])
    {
        return $this->actingAs($as ?? $this->fin)->get(route('finance.reports.show', ['report' => $key, 'format' => $format] + $query));
    }

    /** FIN-07.07: each of the eight reports returns rows from the mini-estate. */
    public function test_each_report_returns_rows(): void
    {
        $reports = app(FinanceReports::class);
        $this->assertEqualsCanonicalizing(array_merge(self::KEYS, ['investment_portfolio', 'benefits_register', 'cloud_spend', 'showback_statement', 'capitalisation_register', 'application_tco']), array_keys($reports->available()));
        foreach (self::KEYS as $key) {
            $r = $reports->run($key, $this->org->id, [], $this->fin);
            $this->assertGreaterThan(0, $r['row_count'], $key);
            $this->assertSame(count($r['rows']), $r['row_count'], $key);
            $this->assertFalse($r['truncated'], $key);
            $this->assertNotEmpty($r['columns'], $key);
        }
        $book = $reports->run('budget_book', $this->org->id, [], $this->fin);
        $this->assertSame('36000000.00', $book['totals']['current']);
        $bva = $reports->run('bva', $this->org->id, [], $this->fin);
        $this->assertCount(3, $bva['rows']);
        $this->assertSame('3850000.00', $bva['totals']['actual']); // 3.6m + 250,000 on the lines; the unbudgeted posting is not on a line
        $this->assertSame('Switch AMC', $reports->run('contract_register', $this->org->id, [], $this->fin)['rows'][0]['title']);
        $this->assertContains($this->po->po_code, array_column($reports->run('commitments', $this->org->id, [], $this->fin)['rows'], 'document'));
        $this->assertSame(['UNB-1'], array_column($reports->run('unbudgeted', $this->org->id, [], $this->fin)['rows'], 'reference'));
        $vendor = $reports->run('vendor_spend', $this->org->id, [], $this->fin)['rows'][0];
        $this->assertSame(['Infosys Finacle', '650000.00', 1], [$vendor['vendor'], $vendor['actual_ytd'], $vendor['live_contracts']]);
        $this->assertSame('Infosys Finacle', $reports->run('invoice_ageing', $this->org->id, [], $this->fin)['rows'][0]['vendor']);
        $this->assertSame('red', $reports->run('variance_commentary', $this->org->id, [], $this->fin)['rows'][0]['severity']);
    }

    /** FIN-07.07: every report downloads in all three formats with the right content type and file name. */
    public function test_every_report_downloads_in_csv_xlsx_and_pdf(): void
    {
        foreach (self::KEYS as $key) {
            foreach (self::TYPES as $format => $type) {
                $r = $this->download($key, $format)->assertOk();
                $this->assertStringContainsString($type, (string) $r->headers->get('Content-Type'), "{$key} {$format}");
                $this->assertStringContainsString('it-finance-'.str_replace('_', '-', $key).'.'.$format, (string) $r->headers->get('Content-Disposition'), "{$key} {$format}");
            }
        }
        $this->assertStringContainsString('text/csv', (string) $this->download('bva', 'nonsense')->assertOk()->headers->get('Content-Type'), 'an unknown format falls back to CSV');
        $this->assertStringContainsString('text/csv', (string) $this->actingAs($this->fin)->get(route('finance.reports.show', 'bva'))->headers->get('Content-Type'), 'CSV is the default');
    }

    /** FIN-07.07: the XLSX is a valid zip workbook whose first sheet has the header and a value; the PDF is a PDF. */
    public function test_xlsx_is_a_valid_workbook_and_pdf_starts_with_the_marker(): void
    {
        $content = $this->download('budget_book', 'xlsx')->assertOk()->getContent();
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $content);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true, 'the XLSX opens as a zip');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $part) {
            $this->assertNotFalse($zip->getFromName($part), $part);
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);
        $xml = simplexml_load_string($sheet);
        $this->assertNotFalse($xml, 'the sheet is well-formed XML');
        $this->assertStringContainsString('Cost centre', $sheet);
        $this->assertStringContainsString('Core banking AMC', $sheet);
        $this->assertStringContainsString('<v>12000000', $sheet, 'amounts are numeric cells');

        $this->assertStringStartsWith('%PDF', $this->download('bva', 'pdf')->getContent());
    }

    /** FIN-07.07: CSV cells that start with a formula character are neutralised. */
    public function test_csv_cells_are_formula_guarded(): void
    {
        $csv = $this->download('variance_commentary', 'csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $row = $this->csvRows($csv)[0];
        $this->assertStringStartsWith("'=HYPERLINK", $row['Explanation']);
        $this->assertStringStartsWith("'@Renegotiate", $row['Corrective action']);
        foreach (explode("\n", $csv) as $line) {
            $this->assertDoesNotMatchRegularExpression('/(^|,)"?[=@]/', $line, 'no cell starts with a formula character');
        }
    }

    /** FIN-07.07: exporting needs "export it-finance". */
    public function test_export_permission_is_required(): void
    {
        $viewer = $this->viewerWith(['view it-finance', 'view it-finance-amounts', 'close it-finance-periods']);
        $this->download('bva', 'csv', $viewer)->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.monitor.bva.export'))->assertForbidden();
        $this->actingAs($viewer)->get(route('finance.reports.index'))->assertOk(); // the hub itself only needs view
        $this->download('bva', 'csv', $this->viewerWith(['export it-finance']))->assertForbidden(); // and view is needed too
    }

    /** FIN-07.10: without "view it-finance-amounts" every money and percentage column is blank, in the run, the files and the totals. */
    public function test_money_blank_without_amounts(): void
    {
        $viewer = $this->viewerWith(['view it-finance', 'export it-finance', 'close it-finance-periods']);
        $reports = app(FinanceReports::class);
        foreach (self::KEYS as $key) {
            $r = $reports->run($key, $this->org->id, [], $viewer);
            $this->assertGreaterThan(0, $r['row_count'], $key);
            $money = array_column(array_filter($r['columns'], fn ($c) => in_array($c['type'], ['money', 'pct'], true)), 'key');
            $this->assertNotEmpty($money, $key);
            foreach (array_merge($r['rows'], $r['totals'] ? [$r['totals']] : []) as $row) {
                foreach ($money as $k) {
                    $this->assertNull($row[$k], "{$key}.{$k}");
                }
            }
        }
        $csv = $this->download('budget_book', 'csv', $viewer)->assertOk()->getContent();
        $this->assertStringNotContainsString('12000000', $csv);
        $this->assertStringContainsString('Core banking AMC', $csv);
        $row = $this->csvRows($csv)[0];
        $this->assertSame('', $row['Current']);
        // an authorised viewer still sees the figures
        $this->assertStringContainsString('12000000.00', $this->download('budget_book', 'csv')->getContent());
    }

    /** FIN-07.10, tenancy: cost-centre visibility applies to reports, and another bank's data never appears. */
    public function test_cost_centre_visibility_and_tenant_isolation(): void
    {
        $other = $this->firstLine($this->baselined($this->cc2, '6000000.00')); // owned by owner2
        $this->postActual($other, $this->periodSeq(1), '777777.00');
        $mine = $this->csvRows($this->download('budget_book', 'csv', tap($this->requester, fn ($u) => $u->givePermissionTo('export it-finance')))->assertOk()->getContent());
        $centres = collect($mine)->pluck('Cost centre')->reject(fn ($c) => in_array($c, ['', 'Total'], true))->unique()->values()->all();
        $this->assertSame(['CC-1A Ops Support'], $centres);
        $this->assertStringNotContainsString('777777', $this->download('bva', 'csv', $this->requester)->getContent());
        $all = collect($this->csvRows($this->download('budget_book', 'csv')->getContent()))->pluck('Cost centre')->reject(fn ($c) => in_array($c, ['', 'Total'], true))->unique()->count();
        $this->assertSame(2, $all);

        $bank = $this->otherBank();
        $stranger = $this->userWith('IT Finance Controller', [], $bank->id);
        $csv = $this->download('budget_book', 'csv', $stranger)->assertOk()->getContent();
        $this->assertStringNotContainsString('Core banking AMC', $csv);
        foreach (self::KEYS as $key) {
            $this->assertSame(0, app(FinanceReports::class)->run($key, $bank->id, [], $stranger)['row_count'], $key);
            $this->download($key, 'pdf', $stranger)->assertOk();
        }
    }

    /** FIN-07.07: an unknown report key is a 404, and the row caps are honoured. */
    public function test_unknown_report_and_row_caps(): void
    {
        $this->actingAs($this->fin)->get(route('finance.reports.show', 'nope'))->assertNotFound();
        config(['finance.reports.max_rows' => 2]);
        $r = app(FinanceReports::class)->run('budget_book', $this->org->id, [], $this->fin);
        $this->assertSame([2, true], [$r['row_count'], $r['truncated']]);
        config(['finance.reports.max_rows' => 20000, 'finance.reports.pdf_max_rows' => 1]);
        $this->assertStringContainsString('Truncated', $this->download('budget_book', 'pdf')->getContent());
    }

    /** FIN-04.11, FIN-07.07: a scheduled finance report records its figures, and ReportFigures serves the board figures. */
    public function test_report_figures_finance(): void
    {
        $this->actingAs($this->fin);
        $board = ReportFigures::summary('finance', $this->org->id);
        foreach (['fiscal_year', 'as_of_period', 'red', 'amber', 'unexplained_red', 'generated_at'] as $k) {
            $this->assertArrayHasKey($k, $board, $k);
        }
        $this->assertArrayNotHasKey('budget_total', $board, 'run summaries carry no money');
        $this->assertSame('FY2027', $board['fiscal_year']);
        $this->assertSame(3, $board['unexplained_red'], 'lines 1 and 2 are unexplained red under-spends; line 0 is explained but not yet acknowledged, so it still counts (P7 L9, as the close checklist does)');
        $r = ReportFigures::summary('finance.bva', $this->org->id);
        $this->assertSame(['bva', 3], [$r['report'], $r['rows']]);
        $this->assertArrayNotHasKey('totals', $r, 'run summaries carry no money');
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey("finance.{$key}", ScheduledReport::TYPES);
            $this->assertSame($key, ReportFigures::summary("finance.{$key}", $this->org->id)['report']);
        }
        $this->assertSame(['nope', 0], array_values(Arr::only(ReportFigures::summary('finance.nope', $this->org->id), ['report', 'rows'])));
    }
}
