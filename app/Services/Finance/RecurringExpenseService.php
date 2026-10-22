<?php

namespace App\Services\Finance;

use App\Models\Finance\Accrual;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceLine;
use App\Models\Finance\Period;
use App\Models\Finance\RecurringExpense;
use App\Models\Finance\TaxType;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Recurring expenses (FIN-03.07): each due date creates an expected invoice (draft) or a proposed accrual, once. */
class RecurringExpenseService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public function __construct(
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly FinanceTax $tax,
        private readonly AccrualService $accruals,
    ) {}

    public function save(?RecurringExpense $r, array $data, User $by): RecurringExpense
    {
        $orgId = (int) $by->organization_id;
        $get = fn (string $k, $d = null) => array_key_exists($k, $data) ? $data[$k] : ($r?->{$k} ?? $d);
        $title = trim((string) $get('title', ''));
        $title === '' && $this->fail('Give the recurring expense a title.', 'title');
        array_key_exists((string) $get('frequency'), RecurringExpense::FREQUENCIES) || $this->fail('Pick a frequency.', 'frequency');
        in_array($get('auto_generate', 'expected_invoice'), RecurringExpense::AUTO_GENERATE, true) || $this->fail('Unknown generation mode.', 'auto_generate');
        $this->inBank(CostCentre::class, $orgId, $get('cost_centre_id'), 'cost_centre_id');
        foreach (['contract_id' => Contract::class, 'budget_line_id' => BudgetLine::class, 'vendor_id' => Vendor::class, 'category_id' => Category::class, 'gl_account_id' => GlAccount::class, 'tax_type_id' => TaxType::class] as $col => $class) {
            $get($col) && $this->inBank($class, $orgId, $get($col), $col);
        }
        $amount = $this->money($get('amount'), 'amount');
        $currency = strtoupper((string) $get('currency', $this->settings->baseCurrency($orgId)));
        $start = $this->date($get('starts_on'), 'starts_on');
        $end = $get('ends_on') ? $this->date($get('ends_on'), 'ends_on') : null;
        $end && $end->lt($start) && $this->fail('The end date is before the start date.', 'ends_on');
        $contract = $get('contract_id') ? Contract::withoutGlobalScopes()->find($get('contract_id')) : null;
        ($get('vendor_id') ?? $contract?->vendor_id) || ($get('auto_generate', 'expected_invoice') !== 'expected_invoice') || $this->fail('Pick a vendor (or a contract) so the expected invoice has one.', 'vendor_id');

        $attrs = [
            'title' => mb_substr($title, 0, 200), 'contract_id' => $get('contract_id') ?: null, 'budget_line_id' => $get('budget_line_id') ?: null, 'vendor_id' => $get('vendor_id') ?: $contract?->vendor_id,
            'category_id' => $get('category_id') ?: null, 'gl_account_id' => $get('gl_account_id') ?: null, 'tax_type_id' => $get('tax_type_id') ?: null, 'cost_centre_id' => (int) $get('cost_centre_id'),
            'frequency' => $get('frequency'), 'amount' => $amount, 'currency' => $currency, 'starts_on' => $start->toDateString(), 'ends_on' => $end?->toDateString(),
            'auto_generate' => $get('auto_generate', 'expected_invoice'), 'is_active' => (bool) $get('is_active', true), 'notes' => $get('notes'),
        ];
        if ($r === null) {
            $r = RecurringExpense::withoutGlobalScopes()->create($attrs + [
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'recurring_code' => RecurringExpense::generateNextCode($orgId), 'next_due_on' => $start->toDateString(),
            ]);
            $this->audit($r, $by, 'created', "Recurring expense {$r->recurring_code} created");
        } else {
            $r->fill($attrs);
            $r->isDirty('starts_on') && ! $r->last_generated_on && $r->next_due_on = $start->toDateString();
            $r->save();
            $this->audit($r, $by, 'updated', "Recurring expense {$r->recurring_code} updated");
        }

        return $r->refresh();
    }

    public function delete(RecurringExpense $r, User $by): void
    {
        $this->audit($r, $by, 'deleted', "Recurring expense {$r->recurring_code} deleted");
        $r->delete();
    }

    /** The first date of the series on or after $from + 1 day. */
    public function nextDue(RecurringExpense $r, CarbonInterface $from): CarbonImmutable
    {
        $step = RecurringExpense::FREQUENCIES[$r->frequency];
        $anchor = CarbonImmutable::parse($r->starts_on->toDateString());
        $due = $anchor;
        for ($k = 0; $k < 2400 && $due->lte($from); $k++) {
            $due = $anchor->addMonthsNoOverflow(($k + 1) * $step);
        }

        return $due;
    }

    /** Generates everything due on or before $today; returns how many items were created. */
    public function generateDue(?CarbonInterface $today = null, ?int $orgId = null): int
    {
        $today ??= FinanceClock::today();
        $made = 0;
        $q = RecurringExpense::withoutGlobalScopes()->where('is_active', true)->whereNull('deleted_at')->whereDate('next_due_on', '<=', $today->toDateString())
            ->when($orgId, fn ($q, $v) => $q->where('organization_id', $v));
        foreach ($q->orderBy('id')->get() as $r) {
            try {
                $made += $this->generateFor($r, $today);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $made;
    }

    /** Generates every date of one recurring expense that is due on or before $today (catch-up capped). */
    public function generateFor(RecurringExpense $r, CarbonInterface $today): int
    {
        $made = 0;
        $cap = (int) config('finance.recurring.catch_up_max', 12);
        for ($n = 0; $n < $cap; $n++) {
            $due = CarbonImmutable::parse($r->next_due_on->toDateString());
            if ($due->toDateString() > $today->toDateString() || ($r->ends_on && $due->toDateString() > $r->ends_on->toDateString())) {
                break;
            }
            try {
                $this->generate($r, $due) && $made++;
            } catch (ValidationException $e) {
                report($e); // e.g. no FX rate: skip this date and carry on, the next run will not retry it
            }
            $r->forceFill(['last_generated_on' => $due->toDateString(), 'next_due_on' => $this->nextDue($r, $due)->toDateString()])->save();
        }

        return $made;
    }

    public function generate(RecurringExpense $r, CarbonInterface $dueOn, ?User $by = null): Invoice|Accrual|null
    {
        $dueOn = CarbonImmutable::parse($dueOn->toDateString());
        $orgId = (int) $r->organization_id;
        $contract = $r->contract_id ? Contract::withoutGlobalScopes()->find($r->contract_id) : null;
        $creator = $by?->id ?? $r->created_by_id;

        if ($r->auto_generate === 'accrual') {
            $key = "rec:{$r->id}:{$dueOn->toDateString()}";
            $existing = Accrual::withoutGlobalScopes()->where('organization_id', $orgId)->where('dedupe_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $p = $this->periodContaining($orgId, $dueOn) ?? $this->fail('No period covers the due date.');
            $conv = $this->fx->convert($orgId, (string) $r->amount, $r->currency, $dueOn->gt(FinanceClock::today()) ? FinanceClock::today() : $dueOn, 'spot');

            return Accrual::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $creator, 'fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id, 'type' => 'accrual', 'source' => 'recurring',
                'dedupe_key' => $key, 'recurring_expense_id' => $r->id, 'budget_line_id' => $r->budget_line_id, 'gl_account_id' => $r->gl_account_id, 'cost_centre_id' => $r->cost_centre_id,
                'description' => "{$r->title} ({$dueOn->toDateString()})", 'currency' => $r->currency, 'fx_rate' => $conv['fx_rate'], 'amount' => $conv['amount'], 'base_amount' => $conv['base_amount'],
                'reversal_period_id' => Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->whereDate('starts_on', '>', $p->starts_on->toDateString())->orderBy('starts_on')->value('id'),
                'status' => 'proposed',
            ]);
        }
        if ($r->auto_generate !== 'expected_invoice') {
            return null;
        }
        $existing = Invoice::withoutGlobalScopes()->withTrashed()->where('recurring_expense_id', $r->id)->whereDate('recurring_due_on', $dueOn->toDateString())->first();
        if ($existing) {
            return $existing;
        }
        $vendorId = $r->vendor_id ?? $contract?->vendor_id ?? $this->fail('No vendor for the expected invoice.');
        $period = $this->periodContaining($orgId, $dueOn)
            ?? Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->where('status', 'open')->orderBy('starts_on')->first()
            ?? $this->fail('No open period for the expected invoice.');
        $period->status !== 'open' && ($period = Period::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_adjustment', false)->where('status', 'open')
            ->whereDate('starts_on', '>=', $period->starts_on->toDateString())->orderBy('starts_on')->first() ?? $period);
        $line = $r->budget_line_id ? BudgetLine::withoutGlobalScopes()->find($r->budget_line_id) : null;
        $vat = $r->tax_type_id ? TaxType::withoutGlobalScopes()->find($r->tax_type_id) : null;
        $totals = $this->tax->compute((string) $r->amount, $r->currency, $vat, null);
        $conv = $this->fx->convert($orgId, $totals['total_amount'], $r->currency, $dueOn->gt(FinanceClock::today()) ? FinanceClock::today() : $dueOn, 'spot');
        $days = (int) ($contract?->payment_terms_days ?? config('finance.invoices.default_payment_days', 30));

        return DB::transaction(function () use ($r, $orgId, $creator, $dueOn, $vendorId, $period, $line, $vat, $totals, $conv, $days, $contract) {
            $i = Invoice::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $creator, 'invoice_code' => Invoice::generateNextCode($orgId), 'vendor_invoice_no' => 'EXPECTED-'.$r->recurring_code.'-'.$dueOn->format('Ymd'),
                'vendor_id' => $vendorId, 'contract_id' => $r->contract_id, 'recurring_expense_id' => $r->id, 'recurring_due_on' => $dueOn->toDateString(), 'budget_line_id' => $line?->id,
                'cost_centre_id' => $r->cost_centre_id, 'category_id' => $r->category_id, 'gl_account_id' => $r->gl_account_id ?? $line?->gl_account_id, 'legal_entity_id' => $line?->legal_entity_id,
                'fiscal_year_id' => $period->fiscal_year_id, 'period_id' => $period->id, 'invoice_date' => $dueOn->toDateString(), 'due_on' => $dueOn->addDays($days)->toDateString(),
                'currency' => $r->currency, 'fx_rate' => $conv['fx_rate'], 'fx_rate_type' => $conv['rate_type'], 'fx_rate_date' => $conv['rate_date'],
                'subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax_amount'], 'wht_amount' => '0.00', 'total_amount' => $totals['total_amount'], 'base_total_amount' => $conv['base_amount'],
                'tax_type_id' => $vat?->id, 'match_status' => 'unmatched', 'status' => 'draft', 'is_expected' => true, 'is_capex' => (bool) $line?->is_capex,
                'is_lease' => (bool) $contract?->is_ifrs16_lease, 'description' => $r->title,
            ]);
            InvoiceLine::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $creator, 'invoice_id' => $i->id, 'line_no' => 1, 'description' => mb_substr($r->title, 0, 255),
                'quantity' => '1', 'unit_cost' => $totals['subtotal'], 'amount' => $totals['subtotal'],
            ]);
            $this->audit($i, $creator ? User::withoutGlobalScopes()->find($creator) : null, 'created', "Expected invoice {$i->invoice_code} generated from {$r->recurring_code}");

            return $i;
        });
    }
}
