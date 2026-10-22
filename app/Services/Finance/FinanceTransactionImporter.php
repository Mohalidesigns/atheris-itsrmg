<?php

namespace App\Services\Finance;

use App\Events\Finance\ImportBatchCompleted;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Category;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\PurchaseOrder;
use App\Models\Finance\TaxType;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bulk CSV import of expense requests and invoices as drafts (FIN-03.15). Fixed headers; preview first, then apply. */
class FinanceTransactionImporter
{
    use LogsFinanceEvents;

    public const EXPENSE_HEADERS = ['title', 'cost_centre_code', 'budget_line_ref', 'category_code', 'vendor', 'amount', 'currency', 'tax_code', 'is_capex', 'needed_by', 'justification'];

    public const INVOICE_HEADERS = ['vendor_invoice_no', 'vendor', 'po_code', 'contract_code', 'invoice_date', 'due_on', 'currency', 'subtotal', 'tax_code', 'wht_code', 'cost_centre_code', 'budget_line_ref', 'description'];

    public function __construct(private readonly ExpenseService $expenses, private readonly InvoiceService $invoices, private readonly FinanceSettings $settings) {}

    /** @return array<string,mixed> report */
    public function expenses(UploadedFile $f, User $by, bool $apply): array
    {
        return $this->run('expenses', self::EXPENSE_HEADERS, $f, $by, $apply, function (array $r, int $orgId) use ($by) {
            $fy = $this->fiscalYearFor($orgId, $r['needed_by'] ?? null);
            $data = [
                'title' => $r['title'], 'fiscal_year_id' => $fy->id, 'cost_centre_id' => $this->lookup(CostCentre::class, 'code', $r['cost_centre_code'], $orgId, 'cost_centre_code'),
                'category_id' => $r['category_code'] !== '' ? $this->lookup(Category::class, 'code', $r['category_code'], $orgId, 'category_code') : null,
                'vendor_id' => $r['vendor'] !== '' ? $this->lookup(Vendor::class, 'name', $r['vendor'], $orgId, 'vendor') : null,
                'amount' => $r['amount'], 'currency' => $r['currency'] ?: $this->settings->baseCurrency($orgId),
                'tax_type_id' => $r['tax_code'] !== '' ? $this->lookup(TaxType::class, 'code', $r['tax_code'], $orgId, 'tax_code') : null,
                'is_capex' => in_array(strtolower($r['is_capex']), ['1', 'yes', 'true', 'y'], true), 'needed_by' => $r['needed_by'] ?: null, 'justification' => $r['justification'],
            ];
            if ($r['budget_line_ref'] !== '') {
                $data['budget_line_id'] = $this->lineByRef($orgId, $r['budget_line_ref'], $fy->id);
            }

            return fn () => $this->expenses->create($data, $by)->request_code;
        }, $by);
    }

    /** @return array<string,mixed> report */
    public function invoices(UploadedFile $f, User $by, bool $apply): array
    {
        return $this->run('invoices', self::INVOICE_HEADERS, $f, $by, $apply, function (array $r, int $orgId) use ($by) {
            $po = $r['po_code'] !== '' ? $this->lookup(PurchaseOrder::class, 'po_code', $r['po_code'], $orgId, 'po_code') : null;
            $fy = $this->fiscalYearFor($orgId, $r['invoice_date']);
            $data = [
                'vendor_invoice_no' => $r['vendor_invoice_no'], 'vendor_id' => $r['vendor'] !== '' ? $this->lookup(Vendor::class, 'name', $r['vendor'], $orgId, 'vendor') : null,
                'po_id' => $po, 'contract_id' => $r['contract_code'] !== '' ? $this->lookup(Contract::class, 'contract_code', $r['contract_code'], $orgId, 'contract_code') : null,
                'invoice_date' => $r['invoice_date'], 'due_on' => $r['due_on'] ?: null, 'currency' => $r['currency'] ?: null, 'subtotal' => $r['subtotal'],
                'tax_type_id' => $r['tax_code'] !== '' ? $this->lookup(TaxType::class, 'code', $r['tax_code'], $orgId, 'tax_code') : null,
                'wht_tax_type_id' => $r['wht_code'] !== '' ? $this->lookup(TaxType::class, 'code', $r['wht_code'], $orgId, 'wht_code') : null,
                'cost_centre_id' => $r['cost_centre_code'] !== '' ? $this->lookup(CostCentre::class, 'code', $r['cost_centre_code'], $orgId, 'cost_centre_code') : null,
                'description' => $r['description'],
            ];
            if ($r['budget_line_ref'] !== '') {
                $data['budget_line_id'] = $this->lineByRef($orgId, $r['budget_line_ref'], $fy->id);
            }
            $data = array_filter($data, fn ($v) => $v !== null);

            return fn () => $this->invoices->capture($data, $by)->invoice_code;
        }, $by);
    }

    /** @param callable(array<string,string>,int):\Closure $prepare returns the closure that creates the draft */
    private function run(string $type, array $headers, UploadedFile $f, User $by, bool $apply, callable $prepare, User $actor): array
    {
        $orgId = (int) $by->organization_id;
        $report = ['mode' => $apply ? 'apply' : 'preview', 'type' => $type, 'file_name' => $f->getClientOriginalName(), 'rows' => 0, 'accepted' => 0, 'rejected' => 0, 'duplicates' => 0,
            'matched' => 0, 'unmatched' => 0, 'errors' => [], 'sample' => [], 'batch_id' => null];
        if ($f->getSize() > config('finance.actuals.max_kb') * 1024) {
            throw ValidationException::withMessages(['file' => 'The file is too large.']);
        }
        $fh = fopen($f->getRealPath(), 'r');
        $head = fgetcsv($fh);
        $head = array_map(fn ($h) => mb_strtolower(trim(ltrim((string) $h, "\xEF\xBB\xBF"))), $head ?: []);
        $missing = array_diff($headers, $head);
        $missing && ($m = 'Missing columns: '.implode(', ', $missing)) && throw ValidationException::withMessages(['file' => $m]);
        $creators = [];
        $n = 1;
        while (($cells = fgetcsv($fh)) !== false) {
            $n++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $report['rows']++;
            $report['rows'] > config('finance.import_max_rows') && throw ValidationException::withMessages(['file' => 'Too many rows; split the file.']);
            $row = [];
            foreach ($headers as $h) {
                $row[$h] = trim((string) ($cells[array_search($h, $head, true)] ?? ''));
            }
            try {
                DB::beginTransaction();
                $create = $prepare($row, $orgId);
                $code = $create();
                $apply ? DB::commit() : DB::rollBack();
                $report['accepted']++;
                count($report['sample']) < 20 && ($report['sample'][] = ['row' => $n, 'code' => $apply ? $code : null] + $row);
            } catch (ValidationException $e) {
                DB::rollBack();
                $report['rejected']++;
                foreach ($e->errors() as $field => $msgs) {
                    count($report['errors']) < 200 && ($report['errors'][] = ['row' => $n, 'field' => (string) $field, 'message' => $msgs[0]]);
                }
            }
        }
        fclose($fh);
        if ($apply) {
            $batch = ImportBatch::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'type' => $type, 'file_name' => mb_substr($f->getClientOriginalName(), 0, 255),
                'file_sha256' => hash_file('sha256', $f->getRealPath()), 'row_count' => $report['rows'], 'accepted_count' => $report['accepted'], 'rejected_count' => $report['rejected'],
                'report' => $report, 'imported_by_id' => $by->id, 'status' => $report['rejected'] > 0 ? 'completed_with_errors' : 'completed', 'completed_at' => now(),
            ]);
            $report['batch_id'] = $batch->id;
            $batch->forceFill(['report' => $report])->save();
            $this->audit($batch, $by, 'imported', ucfirst($type)." import {$batch->file_name}: {$report['accepted']} drafts created, {$report['rejected']} rejected");
            ImportBatchCompleted::dispatch($batch);
        }

        return $report;
    }

    private function lookup(string $class, string $col, string $value, int $orgId, string $field): int
    {
        $row = $class::withoutGlobalScopes()->where('organization_id', $orgId)->whereRaw("lower({$col}) = ?", [mb_strtolower($value)])->first();
        $row || throw ValidationException::withMessages([$field => "Unknown {$field} \"{$value}\"."]);

        return (int) $row->id;
    }

    private function fiscalYearFor(int $orgId, ?string $date): FiscalYear
    {
        $q = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open');
        $d = $date ? (strtotime($date) ? date('Y-m-d', strtotime($date)) : null) : null;
        $fy = $d ? (clone $q)->whereDate('starts_on', '<=', $d)->whereDate('ends_on', '>=', $d)->first() : null;
        $fy ??= (clone $q)->where('is_current', true)->first() ?? $q->orderByDesc('starts_on')->first();

        return $fy ?? throw ValidationException::withMessages(['fiscal_year_id' => 'There is no open fiscal year.']);
    }

    /** "BUD-0001:3" = budget code and line number, resolved to a line of the baseline budget in the year. */
    private function lineByRef(int $orgId, string $ref, int $fyId): int
    {
        [$code, $no] = array_pad(explode(':', $ref, 2), 2, '');
        $budget = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('budget_code', trim($code))->where('fiscal_year_id', $fyId)->where('status', 'baseline')->first();
        $line = $budget ? BudgetLine::withoutGlobalScopes()->where('budget_id', $budget->id)->where('line_no', (int) $no)->first() : null;

        return $line?->id ?? throw ValidationException::withMessages(['budget_line_ref' => "Unknown budget line \"{$ref}\" (use BUDGETCODE:line_no of the baseline)."]);
    }
}
