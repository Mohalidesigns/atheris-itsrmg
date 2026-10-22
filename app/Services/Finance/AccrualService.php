<?php

namespace App\Services\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\Actual;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\GlAccount;
use App\Models\Finance\GoodsReceiptLine;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\Period;
use App\Models\Finance\PurchaseOrder;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Illuminate\Support\Facades\DB;

/**
 * Accruals and prepayments (FIN-03.06, plan P3-14): received-not-invoiced accruals proposed per period, posted as +actual and
 * reversed in the next period; prepaid invoices released period by period instead of posting a single actual.
 */
class AccrualService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public function __construct(
        private readonly ActualsService $actuals,
        private readonly BudgetPhasing $phasing,
        private readonly BudgetService $budgets,
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly FinanceTax $tax,
    ) {}

    /** Proposes an accrual for every purchase order with goods received (to the period end) and not yet invoiced. */
    public function propose(Period $p, User $by): int
    {
        $made = 0;
        foreach ($this->candidates($p) as $c) {
            $po = $c['po'];
            $this->create([
                'type' => 'accrual', 'source' => 'po', 'dedupe_key' => $c['dedupe_key'], 'period_id' => $p->id, 'po_id' => $po->id, 'budget_line_id' => $po->budget_line_id,
                'gl_account_id' => $po->expenseRequest?->gl_account_id, 'cost_centre_id' => $po->cost_centre_id, 'description' => "Received not invoiced: {$po->po_code}",
                'currency' => $po->currency, 'fx_rate' => (string) $po->fx_rate, 'amount' => $c['amount'], 'base_amount' => $c['base_amount'],
            ], $p, $by);
            $made++;
        }

        return $made;
    }

    /**
     * Dry run of propose(): the received-not-invoiced accruals the period still needs (plan P4-14). Received value is invoiced
     * against approved, scheduled or paid invoices of periods starting on or before the period end (M8), and the accrual carries
     * VAT at the purchase order's tax type so it is on the same cost basis as the invoice (M7). Existing dedupe keys are skipped.
     *
     * @return list<array{po_id:int,po:PurchaseOrder,dedupe_key:string,amount:string,base_amount:string,tax_amount:string}>
     */
    public function candidates(Period $p): array
    {
        $orgId = (int) $p->organization_id;
        $out = [];
        $pos = PurchaseOrder::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['open', 'partially_received', 'received', 'closed'])
            ->whereDate('po_date', '<=', $p->ends_on->toDateString())->with('taxType')->get();
        $periodIds = Period::withoutGlobalScopes()->where('organization_id', $orgId)->whereDate('starts_on', '<=', $p->ends_on->toDateString())->select('id');
        foreach ($pos as $po) {
            $key = "po:{$po->id}:{$p->id}";
            if (Accrual::withoutGlobalScopes()->where('organization_id', $orgId)->where('dedupe_key', $key)->exists()) {
                continue;
            }
            $received = (string) (GoodsReceiptLine::withoutGlobalScopes()->join('fin_goods_receipts as g', 'g.id', '=', 'fin_goods_receipt_lines.grn_id')
                ->join('fin_purchase_order_lines as pl', 'pl.id', '=', 'fin_goods_receipt_lines.po_line_id')->where('g.po_id', $po->id)
                ->whereDate('g.received_on', '<=', $p->ends_on->toDateString())->where('fin_goods_receipt_lines.accepted', true)
                ->selectRaw('coalesce(sum(fin_goods_receipt_lines.quantity * pl.unit_cost), 0) as v')->value('v') ?? '0');
            $invoiced = (string) InvoiceLine::withoutGlobalScopes()->whereIn('invoice_id', Invoice::withoutGlobalScopes()->where('po_id', $po->id)
                ->whereIn('status', ['approved', 'scheduled', 'paid'])->whereIn('period_id', $periodIds)->select('id'))->sum('amount');
            $net = $this->phasing->round(bcsub($received, $invoiced, 8), 2);
            if (bccomp($net, '0', 2) <= 0) {
                continue;
            }
            $t = $this->tax->compute($net, (string) $po->currency, $po->taxType, null);
            $out[] = [
                'po_id' => (int) $po->id, 'po' => $po, 'dedupe_key' => $key, 'amount' => $t['total_amount'], 'tax_amount' => $t['tax_amount'],
                'base_amount' => $this->phasing->round(bcmul($t['total_amount'], (string) $po->fx_rate, 8), 2),
            ];
        }

        return $out;
    }

    /** What still blocks the close of a period: proposed accruals and releases, accruals nobody has proposed yet (M9) and accruals due for reversal. @return array{accruals:int,releases:int,candidates:int,reversals:int} */
    public function pendingFor(Period $p): array
    {
        $q = fn (string $type) => Accrual::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('period_id', $p->id)->where('type', $type)->where('status', 'proposed')->count();

        // Posted accruals that must be reversed in this period (posted, not yet reversed) also block the close.
        $reversals = Accrual::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('reversal_period_id', $p->id)->where('type', 'accrual')->where('status', 'posted')->count();

        return ['accruals' => $q('accrual'), 'releases' => $q('prepayment_release'), 'candidates' => count($this->candidates($p)), 'reversals' => $reversals];
    }

    public function store(array $data, User $by): Accrual
    {
        $orgId = (int) $by->organization_id;
        $p = $this->inBank(Period::class, $orgId, $data['period_id'] ?? null, 'period_id');
        $this->inBank(CostCentre::class, $orgId, $data['cost_centre_id'] ?? null, 'cost_centre_id');
        ! empty($data['budget_line_id']) && $this->inBank(BudgetLine::class, $orgId, $data['budget_line_id'], 'budget_line_id');
        ! empty($data['gl_account_id']) && $this->inBank(GlAccount::class, $orgId, $data['gl_account_id'], 'gl_account_id');
        $desc = trim((string) ($data['description'] ?? ''));
        $desc === '' && $this->fail('Describe the accrual.', 'description');
        $currency = strtoupper((string) ($data['currency'] ?? $this->settings->baseCurrency($orgId)));
        $conv = $this->fx->convert($orgId, $this->money($data['amount'] ?? null, 'amount'), $currency, $p->ends_on, 'spot');

        return $this->create([
            'type' => 'accrual', 'source' => 'manual', 'dedupe_key' => null, 'period_id' => $p->id, 'budget_line_id' => $data['budget_line_id'] ?? null, 'gl_account_id' => $data['gl_account_id'] ?? null,
            'cost_centre_id' => (int) $data['cost_centre_id'], 'description' => mb_substr($desc, 0, 255), 'currency' => $currency, 'fx_rate' => $conv['fx_rate'],
            'amount' => $conv['amount'], 'base_amount' => $conv['base_amount'],
        ], $p, $by);
    }

    public function post(Accrual $a, User $by): Accrual
    {
        $a->status === 'proposed' || $this->fail("A {$a->status} accrual cannot be posted.");
        (int) $by->organization_id === (int) $a->organization_id || $this->fail('This accrual belongs to another bank.');
        $p = $this->assertOpenPeriod(Period::withoutGlobalScopes()->find($a->period_id));

        return DB::transaction(function () use ($a, $by, $p) {
            $isRelease = $a->type === 'prepayment_release';
            $reversal = $isRelease ? null : $this->nextPeriod($p);
            ! $isRelease && ! $reversal && $this->fail('There is no later period to reverse this accrual in; extend the fiscal calendar.');
            $inv = $a->invoice_id ? Invoice::withoutGlobalScopes()->find($a->invoice_id) : null;
            $actual = $this->actuals->post([
                'posted_on' => $this->clamp($p), 'period_id' => $p->id, 'source' => $isRelease ? 'prepayment_release' : 'accrual', 'source_ref' => "ACR-{$a->id}", 'posting_key' => "accrual:{$a->id}",
                'accrual_id' => $a->id, 'gl_account_id' => $a->gl_account_id, 'cost_centre_id' => $a->cost_centre_id, 'budget_line_id' => $this->currentLine($a->budget_line_id),
                'vendor_id' => $inv?->vendor_id, 'contract_id' => $inv?->contract_id, 'description' => $a->description, 'amount' => (string) $a->amount, 'currency' => $a->currency,
                'fx_rate' => (string) $a->fx_rate, 'base_amount' => (string) $a->base_amount, 'is_lease' => (bool) $inv?->is_lease, 'is_capex' => (bool) $inv?->is_capex,
            ], $by);
            $a->forceFill(['status' => 'posted', 'posted_actual_id' => $actual->id, 'posted_by_id' => $by->id, 'posted_at' => now(), 'reversal_period_id' => $reversal?->id])->save();
            $this->audit($a, $by, 'posted', "Accrual #{$a->id} posted to {$p->name}");

            return $a->refresh();
        });
    }

    public function reverse(Accrual $a, User $by): Accrual
    {
        $a->status === 'posted' || $this->fail("A {$a->status} accrual cannot be reversed.");
        $a->type === 'accrual' || $this->fail('A prepayment release is not reversed.');
        (int) $by->organization_id === (int) $a->organization_id || $this->fail('This accrual belongs to another bank.');
        $p = $this->assertOpenPeriod(Period::withoutGlobalScopes()->find($a->reversal_period_id), 'reversal_period_id');

        return DB::transaction(function () use ($a, $by, $p) {
            $original = Actual::withoutGlobalScopes()->find($a->posted_actual_id);
            $actual = $this->actuals->post([
                'posted_on' => $this->clamp($p), 'period_id' => $p->id, 'source' => 'accrual_reversal', 'source_ref' => "ACR-{$a->id}", 'posting_key' => "accrual-rev:{$a->id}",
                'accrual_id' => $a->id, 'gl_account_id' => $a->gl_account_id, 'cost_centre_id' => $a->cost_centre_id, 'budget_line_id' => $original?->budget_line_id,
                'description' => 'Reversal: '.$a->description, 'amount' => bcmul((string) $a->amount, '-1', 2), 'currency' => $a->currency, 'fx_rate' => (string) $a->fx_rate,
                'base_amount' => bcmul((string) $a->base_amount, '-1', 2), 'reverses_actual_id' => $a->posted_actual_id,
            ], $by);
            $a->forceFill(['status' => 'reversed', 'reversal_actual_id' => $actual->id, 'reversed_by_id' => $by->id, 'reversed_at' => now()])->save();
            $this->audit($a, $by, 'reversed', "Accrual #{$a->id} reversed in {$p->name}");

            return $a->refresh();
        });
    }

    /** Posts every proposed accrual of the period. */
    public function postAll(Period $p, User $by): int
    {
        $n = 0;
        foreach (Accrual::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('period_id', $p->id)->where('type', 'accrual')->where('status', 'proposed')->get() as $a) {
            $this->post($a, $by);
            $n++;
        }

        return $n;
    }

    /** Posts the prepayment releases proposed for the period. */
    public function releasePrepayments(Period $p, User $by): int
    {
        $n = 0;
        foreach (Accrual::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('period_id', $p->id)->where('type', 'prepayment_release')->where('status', 'proposed')->get() as $a) {
            $this->post($a, $by);
            $n++;
        }

        return $n;
    }

    /**
     * Spreads an approved prepaid invoice over its months, one proposed release per period from the invoice period (plan P3-14).
     *
     * @return list<Accrual>
     */
    public function schedulePrepayment(Invoice $i, User $by): array
    {
        $months = (int) $i->prepayment_months;
        $start = Period::withoutGlobalScopes()->findOrFail($i->period_id);
        $periods = Period::withoutGlobalScopes()->where('organization_id', $i->organization_id)->where('is_adjustment', false)
            ->whereDate('starts_on', '>=', $start->starts_on->toDateString())->orderBy('starts_on')->orderBy('sequence')->limit($months)->get();
        $periods->count() < $months && $this->fail("The fiscal calendar has only {$periods->count()} periods from {$start->name}; extend it to prepay over {$months} months.", 'prepayment_months');
        $weights = array_fill(0, $months, '1');
        $amounts = $this->phasing->spread((string) $i->total_amount, $weights, 2);
        $bases = $this->phasing->spread((string) $i->base_total_amount, $weights, 2);
        $out = [];
        foreach ($periods->values() as $n => $p) {
            $key = "pre:{$i->id}:".($n + 1);
            $out[] = Accrual::withoutGlobalScopes()->where('organization_id', $i->organization_id)->where('dedupe_key', $key)->first() ?? $this->create([
                'type' => 'prepayment_release', 'source' => 'prepayment', 'dedupe_key' => $key, 'period_id' => $p->id, 'invoice_id' => $i->id, 'budget_line_id' => $i->budget_line_id,
                'gl_account_id' => $i->gl_account_id, 'cost_centre_id' => $i->cost_centre_id, 'description' => 'Prepayment release '.($n + 1)."/{$months}: {$i->invoice_code}",
                'currency' => $i->currency, 'fx_rate' => (string) $i->fx_rate, 'amount' => $amounts[$n], 'base_amount' => $bases[$n],
            ], $p, $by);
        }

        return $out;
    }

    public function cancel(Accrual $a, User $by, string $reason = ''): Accrual
    {
        $a->status === 'proposed' || $this->fail('Only a proposed accrual can be cancelled.');
        $reason = trim($reason);
        mb_strlen($reason) >= 10 || $this->fail('Give the reason for cancelling (at least 10 characters).', 'reason');
        $a->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()])->save();
        $this->audit($a, $by, 'cancelled', "Accrual #{$a->id} cancelled", ['reason' => $reason]);

        return $a->refresh();
    }

    /** @param array<string,mixed> $attrs */
    private function create(array $attrs, Period $p, User $by): Accrual
    {
        $nextPeriod = $attrs['type'] === 'accrual' ? $this->nextPeriod($p) : null;
        $a = Accrual::withoutGlobalScopes()->create($attrs + [
            'organization_id' => $p->organization_id, 'created_by_id' => $by->id, 'fiscal_year_id' => $p->fiscal_year_id, 'reversal_period_id' => $nextPeriod?->id, 'status' => 'proposed',
        ]);
        $this->audit($a, $by, 'proposed', "Accrual #{$a->id} proposed for {$p->name}");

        return $a;
    }

    private function nextPeriod(Period $p): ?Period
    {
        return Period::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('is_adjustment', false)
            ->whereDate('starts_on', '>', $p->starts_on->toDateString())->orderBy('starts_on')->first();
    }

    private function clamp(Period $p): string
    {
        $t = FinanceClock::today()->toDateString();

        return $t < $p->starts_on->toDateString() ? $p->starts_on->toDateString() : ($t > $p->ends_on->toDateString() ? $p->ends_on->toDateString() : $t);
    }

    private function currentLine(?int $lineId): ?int
    {
        $line = $lineId ? BudgetLine::withoutGlobalScopes()->find($lineId) : null;

        return $line ? ($this->budgets->resolveBaselineLine($line)?->id ?? $line->id) : null;
    }
}
