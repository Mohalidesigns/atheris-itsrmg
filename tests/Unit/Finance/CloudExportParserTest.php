<?php

namespace Tests\Unit\Finance;

use App\Services\Finance\CloudExportParser;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** FIN-03.09 (S): AWS CUR, Azure, GCP and generic exports parse to canonical daily rows with tags. */
class CloudExportParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return base_path("tests/Fixtures/finance/cloud/{$name}.csv");
    }

    public function test_aws_cur_rows_tags_and_rejections(): void
    {
        $r = (new CloudExportParser)->parse($this->fixture('aws_cur'), 'aws_cur', '402112345688');

        $this->assertSame(12, $r['row_count']);
        $this->assertCount(10, $r['rows']);
        $this->assertSame('2026-08-03', $r['date_from']);
        $this->assertSame('2026-08-04', $r['date_to']);
        $reasons = collect($r['rejected'])->pluck('reason')->implode('|');
        $this->assertStringContainsString('999999999999 is not 402112345688', $reasons); // account mismatch
        $this->assertStringContainsString('"1e5"', $reasons); // exponent notation refused
        $first = $r['rows'][0];
        $this->assertSame(['CostCentre' => 'KHB-IT-DIG', 'Service' => 'mobile'], collect($first['tags'])->sortKeys()->all());
        $this->assertSame('USD', $first['currency']);
        $this->assertSame('1.250000', $first['cost']);
    }

    public function test_hourly_cur_rows_aggregate_to_one_daily_line(): void
    {
        $r = (new CloudExportParser)->parse($this->fixture('aws_cur'), 'aws_cur', '402112345688');
        $daily = CloudExportParser::aggregate($r['rows']);
        $ec2 = collect($daily)->first(fn ($l) => $l['resource_id'] === 'i-0a1b2c3d' && $l['usage_date'] === '2026-08-03');

        $this->assertSame('3.000000', $ec2['cost']); // 1.25 + 1.25 + 0.50
        $this->assertSame(40, strlen($ec2['line_hash']));
        $this->assertCount(8, $daily);
        $again = CloudExportParser::aggregate($r['rows']);
        $this->assertSame($daily, $again); // deterministic hash
    }

    public function test_negative_credit_is_accepted(): void
    {
        $r = (new CloudExportParser)->parse($this->fixture('aws_cur'), 'aws_cur', '402112345688');
        $credit = collect($r['rows'])->first(fn ($l) => $l['service'] === 'AWS Support (Business)');

        $this->assertSame('-2.500000', $credit['cost']);
    }

    public function test_azure_tag_fragments_and_us_dates(): void
    {
        $r = (new CloudExportParser)->parse($this->fixture('azure'), 'azure', '11111111-2222-3333-4444-555555555555');

        $this->assertCount(5, $r['rows']);
        $this->assertSame('2026-08-03', $r['rows'][0]['usage_date']);
        $this->assertSame(['Application' => 'Finacle', 'CostCentre' => 'KHB-IT-DC'], $r['rows'][0]['tags']);
        $this->assertSame([], $r['rows'][2]['tags']);
        $this->assertSame('2026-08-04', $r['rows'][4]['usage_date']);
    }

    public function test_gcp_label_lists_and_maps(): void
    {
        $r = (new CloudExportParser)->parse($this->fixture('gcp'), 'gcp', 'khb-analytics-1');

        $this->assertCount(4, $r['rows']);
        $this->assertSame(['service' => 'mobile', 'env' => 'prod'], $r['rows'][0]['tags']);
        $this->assertSame(['costcentre' => 'KHB-IT-DC'], $r['rows'][1]['tags']);
    }

    public function test_generic_key_value_tags_and_aggregation(): void
    {
        $r = (new CloudExportParser)->parse($this->fixture('generic'), 'generic', 'ACC-001');
        $this->assertSame(['service' => 'mobile', 'env' => 'prod'], $r['rows'][0]['tags']);

        $daily = CloudExportParser::aggregate($r['rows']);
        $this->assertCount(3, $daily);
        $this->assertSame('15.000000', $daily[0]['cost']);
    }

    public function test_bom_header_is_stripped(): void
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", file_get_contents($this->fixture('aws_cur')));
        $r = (new CloudExportParser)->parse($this->fixture('aws_cur'), 'aws_cur', '402112345688');

        $this->assertNotEmpty($r['rows']); // the BOM did not hide the first column
    }

    public function test_too_many_rows_refuses_the_whole_file(): void
    {
        $this->expectException(ValidationException::class);

        (new CloudExportParser)->parse($this->fixture('aws_cur'), 'aws_cur', '402112345688', ['max_rows' => 3]);
    }

    public function test_missing_required_column_refuses_the_file(): void
    {
        $this->expectException(ValidationException::class);

        (new CloudExportParser)->parse($this->fixture('generic'), 'gcp', 'ACC-001');
    }
}
