<?php

namespace Tests\Feature\Finance\Concerns;

use App\Models\Finance\Actual;
use App\Models\Finance\Allocation;
use App\Models\Finance\Capitalisation;
use App\Models\Finance\CloudAccount;
use App\Models\Finance\CostCentre;
use App\Models\Finance\ExpenseRequest;
use App\Models\Finance\Investment;
use App\Models\Finance\Invoice;
use App\Models\Finance\Period;
use App\Models\Finance\Setting;
use App\Models\User;
use App\Services\Finance\AllocationService;
use App\Services\Finance\CapitalisationService;
use App\Services\Finance\CloudCostService;
use App\Services\Finance\CloudExportParser;
use App\Services\Finance\FinanceSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixtures of the P6 FinOps, showback and capitalisation tests: the P5 bank (NGN, FY2027, "today" 2027-04-05, USD spot 1500) plus baseline
 * lines in the cloud, labour and software cost pools across two cost centres, three showback units (RET, CIB, TRS), and helpers that run
 * through the services. Read-only for Tasks B and E.
 */
trait BuildsFinOps
{
    use BuildsInvestments;

    protected CloudAccount $cloudAccount;

    /** @var array{services:list<string>,tags:array<string,string>,daily:float} what cloudRows() last built, so spike() can repeat it */
    protected array $cloudShape = ['services' => [], 'tags' => [], 'daily' => 0.0];

    /** The FY2027 baseline lines added by bootFinOps(), by cost pool code. @var array<string,\App\Models\Finance\BudgetLine> */
    protected array $poolLines = [];

    protected function bootFinOps(string $now = '2027-04-05 09:00:00'): void
    {
        $this->bootInvestments($now);
        $svcLines = fn ($cc, string $amount, string $pool, string $tower, string $desc) => $this->firstLine($this->baselined($cc, $amount, 'NGN', [
            'description' => $desc, 'cost_pool_id' => $this->cat($pool), 'tower_id' => $this->cat($tower),
        ]));
        $this->poolLines['CP-CLD'] = $svcLines($this->cc, '6000000.00', 'CP-CLD', 'TW-CMP', 'Cloud hosting');
        $this->poolLines['CP-LAB'] = $svcLines($this->cc2, '24000000.00', 'CP-LAB', 'TW-DEL', 'IT staff');
        $this->poolLines['CP-SW'] = $this->monLines[0];

        $setting = app(FinanceSettings::class)->for($this->org->id);
        Setting::withoutGlobalScopes()->whereKey($setting->id)->update([
            'showback_enabled' => true,
            'showback_units' => json_encode([
                ['code' => 'RET', 'name' => 'Retail Banking', 'erp_cost_centre_code' => '5100-RET'], ['code' => 'CIB', 'name' => 'Corporate & Investment Banking', 'erp_cost_centre_code' => '5200-CIB'],
                ['code' => 'TRS', 'name' => 'Treasury', 'erp_cost_centre_code' => '5300-TRS'],
            ]),
        ]);
        app(FinanceSettings::class)->flush();
        $this->actingAs($this->fin);
    }

    /** @param array<string,mixed> $over */
    protected function makeCloudAccount(array $over = []): CloudAccount
    {
        return $this->cloudAccount = app(CloudCostService::class)->storeAccount($over + [
            'provider' => 'aws', 'account_identifier' => '402112345688', 'name' => 'Digital channels production', 'default_format' => 'aws_cur', 'currency' => 'USD',
            'default_cost_centre_id' => $this->ccChild->id, 'owner_id' => $this->requester->id,
            'tag_mapping' => ['CostCentre' => 'cost_centre', 'Service' => 'business_service', 'Application' => 'ea_application'],
        ], $this->fin);
    }

    /**
     * Aggregated daily rows for CloudCostService::ingestRows(): $days days from $from, every service each day, tagged alike.
     *
     * @param  list<string>  $services
     * @param  array<string,string>  $tags
     * @return list<array<string,mixed>>
     */
    protected function cloudRows(string $from, int $days, array $services = ['Amazon EC2', 'Amazon S3'], array $tags = [], float $daily = 100.0): array
    {
        $this->cloudShape = ['services' => $services, 'tags' => $tags, 'daily' => $daily];
        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $d = date('Y-m-d', strtotime("$from +$i days"));
            foreach ($services as $s) {
                $rows[] = ['usage_date' => $d, 'service' => $s, 'resource_id' => 'res-'.preg_replace('/\W+/', '-', strtolower($s)), 'region' => 'eu-west-1', 'tags' => $tags,
                    'cost' => number_format($daily, 6, '.', ''), 'currency' => 'USD'];
            }
        }

        return CloudExportParser::aggregate($rows);
    }

    /** One day of the last cloudRows() shape with $service multiplied by $factor (ingest it to restate that date). @return list<array<string,mixed>> */
    protected function spike(string $date, string $service, float $factor): array
    {
        $rows = [];
        foreach ($this->cloudShape['services'] as $s) {
            $rows[] = ['usage_date' => $date, 'service' => $s, 'resource_id' => 'res-'.preg_replace('/\W+/', '-', strtolower($s)), 'region' => 'eu-west-1', 'tags' => $this->cloudShape['tags'],
                'cost' => number_format($this->cloudShape['daily'] * ($s === $service ? $factor : 1.0), 6, '.', ''), 'currency' => 'USD'];
        }

        return CloudExportParser::aggregate($rows);
    }

    protected function ingest(array $rows, ?CloudAccount $a = null, ?User $by = null): array
    {
        return app(CloudCostService::class)->ingestRows($a ?? $this->cloudAccount, $rows, null, $by ?? $this->fin);
    }

    /** @param array<string,mixed> $over */
    protected function makeAllocation(array $over = []): Allocation
    {
        return app(AllocationService::class)->store($over + [
            'name' => 'Operations to Retail', 'source_type' => 'cost_centre', 'source_id' => $this->ccChild->id, 'target_type' => 'business_unit', 'target_ref' => 'RET',
            'method' => 'percentage', 'percentage' => '40', 'effective_from' => '2027-01-01',
        ], $this->fin);
    }

    protected function closePeriodDirectly(Period $p, string $status = 'closed'): Period
    {
        Period::withoutGlobalScopes()->whereKey($p->id)->update(['status' => $status]);

        return $p->refresh();
    }

    /** Flags an investment capitalisable (the P5 editing path is Task B's). */
    protected function flagCapitalisable(Investment $i, string $standard = 'ias_38'): Investment
    {
        Investment::withoutGlobalScopes()->whereKey($i->id)->update(['is_capitalisable' => true, 'capitalisation_standard' => $standard]);

        return $i->refresh();
    }

    /** A counted, matched capex actual attributed to an investment (investment_id) or to the invoices of a capex expense request. */
    protected function capexActual(Model $source, string $amount, string $postedOn): Actual
    {
        $p = Period::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('is_adjustment', false)->whereDate('starts_on', '<=', $postedOn)->whereDate('ends_on', '>=', $postedOn)->firstOrFail();
        $extra = ['is_capex' => true, 'posted_on' => $postedOn];
        if ($source instanceof Investment) {
            $extra += ['investment_id' => $source->id, 'description' => 'Capex for '.$source->investment_code];
        } else {
            $invoice = $this->invoice(['expense_request_id' => $source->id, 'subtotal' => $amount]);
            Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['expense_request_id' => $source->id]);
            $extra += ['invoice_id' => $invoice->id, 'description' => 'Capex invoice for '.$source->request_code];
        }

        return $this->postActual($this->line, $p, $amount, $extra);
    }

    /** A capex expense request in `received` status (no approvals walked: set directly for the capitalisation tests). */
    protected function capexExpense(string $amount = '3000000.00'): ExpenseRequest
    {
        $e = $this->expense(['title' => 'Core switch replacement', 'amount' => $amount, 'is_capex' => true]);
        ExpenseRequest::withoutGlobalScopes()->whereKey($e->id)->update(['is_capex' => true, 'status' => 'received']);

        return $e->refresh();
    }

    /**
     * A draft capitalisation of the source; `ready` ticks and notes every checklist item so it can be submitted.
     *
     * @param  array<string,mixed>  $over
     */
    protected function makeCapitalisation(Model $source, array $over = [], bool $ready = false): Capitalisation
    {
        $svc = app(CapitalisationService::class);
        $afu = $over['available_for_use_on'] ?? '2027-03-31';
        $e = $svc->eligibility($source instanceof Investment ? 'investment' : 'expense', (int) $source->id, $afu, (int) $this->org->id);
        $c = $svc->store($over + [
            'source_type' => $source instanceof Investment ? 'investment' : 'expense', 'source_id' => $source->id, 'title' => 'Mobile app release 1', 'standard' => $source instanceof Investment ? 'ias_38' : 'ias_16',
            'available_for_use_on' => $afu, 'amount' => $e['remaining'], 'useful_life_months' => 60, 'method' => 'straight_line', 'create_asset' => true,
        ], $this->fin);
        if ($ready) {
            $c = $svc->update($c, ['criteria_checklist' => collect($c->criteria_checklist)->map(fn ($i) => ['key' => $i['key'], 'met' => true, 'note' => 'Evidence is on file for this criterion.'])->all()], $this->fin);
        }

        return $c->refresh();
    }

    /** Approves a submitted subject through the owning service, one decider per stage. */
    protected function decide(Model $subject, string $process, User ...$deciders): Model
    {
        foreach ($deciders as $d) {
            match ($process) {
                CapitalisationService::PROCESS => app(CapitalisationService::class)->approve($subject->refresh(), $d),
                default => throw new \InvalidArgumentException("No decide helper for {$process}"),
            };
        }

        return $subject->refresh();
    }

    protected function costCentreByCode(string $code): CostCentre
    {
        return CostCentre::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', $code)->firstOrFail();
    }
}
