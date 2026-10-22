<?php

namespace App\Services\Finance;

use App\Events\Finance\ImportBatchCompleted;
use App\Models\Finance\Actual;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Contract;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\GlAccount;
use App\Models\Finance\ImportBatch;
use App\Models\Finance\ImportMapping;
use App\Models\Finance\Invoice;
use App\Models\Finance\MatchRule;
use App\Models\Finance\Period;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The actuals ledger (FIN-03.05, .15): the single writer of fin_actuals. Invoice approval, accruals, prepayment releases and GL
 * CSV imports all post here, into an open period, idempotently by posting key. Rows are immutable; only the budget-line match moves.
 */
class ActualsService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    private const ERROR_CAP = 200;

    public function __construct(
        private readonly CurrencyService $fx,
        private readonly FinanceSettings $settings,
        private readonly ReconciliationService $recon,
        private readonly BudgetService $budgets,
        private readonly BudgetLedger $ledger,
    ) {}

    /** @param array<string,mixed> $attrs */
    public function post(array $attrs, User $by): Actual
    {
        $orgId = (int) $by->organization_id;
        if (! empty($attrs['posting_key']) && ($found = Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('posting_key', $attrs['posting_key'])->first())) {
            return $found;
        }
        $posted = $this->date($attrs['posted_on'] ?? FinanceClock::today()->toDateString(), 'posted_on');
        $period = ! empty($attrs['period_id'])
            ? Period::withoutGlobalScopes()->where('organization_id', $orgId)->find((int) $attrs['period_id'])
            : $this->periodContaining($orgId, $posted);
        $this->assertOpenPeriod($period);
        $ccId = (int) ($attrs['cost_centre_id'] ?? 0) ?: $this->fail('Give the cost centre.', 'cost_centre_id');
        $this->inBank(CostCentre::class, $orgId, $ccId, 'cost_centre_id');
        $amount = $this->money($attrs['amount'] ?? null, 'amount', false);
        bccomp($amount, '0', 2) === 0 && $this->fail('An actual cannot be zero.', 'amount');
        $currency = strtoupper((string) ($attrs['currency'] ?? $this->settings->baseCurrency($orgId)));
        if (isset($attrs['fx_rate'], $attrs['base_amount'])) {
            $fx = ['fx_rate' => (string) $attrs['fx_rate'], 'rate_type' => $attrs['fx_rate_type'] ?? 'spot', 'rate_date' => $attrs['fx_rate_date'] ?? $posted->toDateString(), 'base_amount' => (string) $attrs['base_amount']];
        } else {
            $fx = $this->fx->convert($orgId, $amount, $currency, $posted->gt(FinanceClock::today()) ? FinanceClock::today() : $posted, 'spot');
        }

        $row = [
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'fiscal_year_id' => $period->fiscal_year_id, 'period_id' => $period->id, 'posted_on' => $posted->toDateString(),
            'source' => $attrs['source'] ?? 'manual', 'source_ref' => isset($attrs['source_ref']) ? mb_substr((string) $attrs['source_ref'], 0, 120) : null, 'posting_key' => $attrs['posting_key'] ?? null,
            'invoice_id' => $attrs['invoice_id'] ?? null, 'accrual_id' => $attrs['accrual_id'] ?? null, 'import_batch_id' => $attrs['import_batch_id'] ?? null,
            'gl_account_id' => $attrs['gl_account_id'] ?? null, 'category_id' => $attrs['category_id'] ?? null, 'vendor_id' => $attrs['vendor_id'] ?? null,
            'contract_id' => $attrs['contract_id'] ?? null, 'asset_id' => $attrs['asset_id'] ?? null, 'cost_centre_id' => $ccId, 'budget_line_id' => $attrs['budget_line_id'] ?? null,
            'investment_id' => $attrs['investment_id'] ?? null, 'ea_application_id' => $attrs['ea_application_id'] ?? null,
            'description' => mb_substr(trim((string) ($attrs['description'] ?? 'Posting')), 0, 255), 'amount' => $amount, 'currency' => $currency,
            'fx_rate' => $fx['fx_rate'], 'fx_rate_type' => $fx['rate_type'], 'fx_rate_date' => $fx['rate_date'], 'base_amount' => $fx['base_amount'],
            'is_capex' => (bool) ($attrs['is_capex'] ?? false), 'is_lease' => (bool) ($attrs['is_lease'] ?? false), 'reverses_actual_id' => $attrs['reverses_actual_id'] ?? null,
            'match_status' => $attrs['match_status'] ?? 'unmatched', 'match_reason' => $attrs['match_reason'] ?? null, 'duplicate_of_id' => $attrs['duplicate_of_id'] ?? null,
        ];
        if (! isset($attrs['match_status'])) {
            if ($row['budget_line_id']) {
                $row['match_status'] = 'matched';
                $row['match_reason'] ??= 'source';
            } elseif (in_array($row['source'], ['invoice', 'accrual', 'accrual_reversal', 'prepayment_release'], true)) {
                $row['match_status'] = 'unbudgeted'; // the source document had no budget line: it is, by definition, unbudgeted spend
            } else {
                $probe = new Actual;
                $probe->forceFill($row);
                $r = $this->recon->autoMatch($probe);
                if ($r['line']) {
                    $row['budget_line_id'] = $r['line']->id;
                    $row['match_status'] = 'matched';
                    $row['match_rule_id'] = $r['rule_id'];
                }
                $row['match_reason'] = $r['reason'];
            }
            $row['match_status'] === 'matched' && ($row['matched_at'] = now());
        }
        $actual = Actual::withoutGlobalScopes()->create($row);
        $actual->match_rule_id && MatchRule::withoutGlobalScopes()->whereKey($actual->match_rule_id)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);

        return $actual->refresh();
    }

    /** Plan P3-3: the actual is the invoice's budget cost (subtotal + VAT) at the invoice rate, in the invoice's period. */
    public function postInvoice(Invoice $i, User $by): Actual
    {
        $period = Period::withoutGlobalScopes()->findOrFail($i->period_id);
        $date = $i->invoice_date->toDateString();
        $posted = $date < $period->starts_on->toDateString() ? $period->starts_on->toDateString() : ($date > $period->ends_on->toDateString() ? $period->ends_on->toDateString() : $date);

        return $this->post([
            'posted_on' => $posted, 'period_id' => $period->id, 'source' => 'invoice', 'source_ref' => $i->invoice_code, 'posting_key' => 'invoice:'.$i->id, 'invoice_id' => $i->id,
            'gl_account_id' => $i->gl_account_id, 'category_id' => $i->category_id, 'vendor_id' => $i->vendor_id, 'contract_id' => $i->contract_id, 'cost_centre_id' => $i->cost_centre_id,
            'budget_line_id' => $i->budget_line_id ? (BudgetLine::withoutGlobalScopes()->find($i->budget_line_id) ? $this->currentLineId((int) $i->budget_line_id) : null) : null,
            'description' => "{$i->vendor_invoice_no} {$i->description}", 'amount' => (string) $i->total_amount, 'currency' => $i->currency, 'fx_rate' => (string) $i->fx_rate,
            'fx_rate_type' => $i->fx_rate_type, 'fx_rate_date' => $i->fx_rate_date?->toDateString(), 'base_amount' => (string) $i->base_total_amount,
            'is_capex' => $i->is_capex, 'is_lease' => $i->is_lease,
        ], $by);
    }

    private function currentLineId(int $lineId): ?int
    {
        $line = BudgetLine::withoutGlobalScopes()->find($lineId);

        return $line ? ($this->budgets->resolveBaselineLine($line)?->id ?? $line->id) : null;
    }

    /* ------------------------------------------------------------------ GL import */

    /** @param ImportMapping|array<string,mixed> $map @return array<string,mixed> */
    public function previewGl(UploadedFile $f, ImportMapping|array $map, int $orgId): array
    {
        return $this->analyse($f, $this->mapping($map), $orgId)['report'] + ['mode' => 'preview'];
    }

    /** @param ImportMapping|array<string,mixed> $map */
    public function importGl(UploadedFile $f, ImportMapping|array $map, User $by, bool $skipInvalid = false, bool $force = false): ImportBatch
    {
        $orgId = (int) $by->organization_id;
        $mapping = $this->mapping($map);
        $hash = hash_file('sha256', $f->getRealPath());
        if (! $force && ImportBatch::withoutGlobalScopes()->where('organization_id', $orgId)->where('type', 'actuals')->where('file_sha256', $hash)->where('status', '!=', 'failed')->exists()) {
            $this->fail('This exact file was already imported. Re-upload it with "import anyway" if you really mean to.', 'file');
        }
        $a = $this->analyse($f, $mapping, $orgId);
        $report = $a['report'];
        $fileError = $a['file_error'];
        $reject = $fileError !== null || ($report['rejected'] > 0 && ! $skipInvalid);

        return DB::transaction(function () use ($f, $by, $orgId, $hash, $a, $report, $reject, $map) {
            $batch = ImportBatch::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'created_by_id' => $by->id, 'type' => 'actuals', 'file_name' => mb_substr($f->getClientOriginalName(), 0, 255), 'file_sha256' => $hash,
                'mapping_id' => $map instanceof ImportMapping ? $map->id : null, 'row_count' => $report['rows'], 'imported_by_id' => $by->id, 'status' => 'failed',
            ]);
            if ($reject) {
                $report['mode'] = 'apply';
                $report['accepted'] = 0;
                $report['batch_id'] = $batch->id;
                $batch->forceFill(['rejected_count' => $report['rejected'], 'report' => $report, 'completed_at' => now()])->save();
                $this->audit($batch, $by, 'failed', "GL import {$batch->file_name} rejected", ['rejected' => $report['rejected']]);
                ImportBatchCompleted::dispatch($batch);

                return $batch->refresh();
            }
            $matched = $unmatched = $accepted = $dups = 0;
            foreach ($a['valid'] as $row) {
                $dupOf = $this->recon->duplicateOf($orgId, $row['source_ref']);
                $actual = $this->post($row['attrs'] + [
                    'source' => 'gl_import', 'source_ref' => $row['source_ref'], 'posting_key' => "gl:{$batch->id}:{$row['row']}", 'import_batch_id' => $batch->id,
                ] + ($dupOf ? ['match_status' => 'duplicate', 'duplicate_of_id' => $dupOf->id, 'match_reason' => 'invoice_posting'] : []), $by);
                if ($actual->match_status === 'duplicate') {
                    $dups++;
                } else {
                    $accepted++;
                    $actual->match_status === 'matched' ? $matched++ : $unmatched++;
                }
            }
            $report['mode'] = 'apply';
            $report['accepted'] = $accepted;
            $report['duplicates'] = $dups + $report['duplicates_skipped'];
            $report['matched'] = $matched;
            $report['unmatched'] = $unmatched;
            $report['batch_id'] = $batch->id;
            unset($report['duplicates_skipped']);
            $batch->forceFill([
                'accepted_count' => $accepted, 'rejected_count' => $report['rejected'], 'duplicate_count' => $report['duplicates'], 'matched_count' => $matched, 'unmatched_count' => $unmatched,
                'report' => $report, 'status' => $report['rejected'] > 0 ? 'completed_with_errors' : 'completed', 'completed_at' => now(),
            ])->save();
            $this->audit($batch, $by, 'imported', "GL import {$batch->file_name}: {$accepted} accepted, {$report['rejected']} rejected, {$report['duplicates']} duplicates", ['unmatched' => $unmatched]);
            ImportBatchCompleted::dispatch($batch);

            return $batch->refresh();
        });
    }

    public function match(Actual $a, BudgetLine $line, User $by, bool $remember = false): Actual
    {
        (int) $by->organization_id === (int) $a->organization_id || $this->fail('This actual belongs to another bank.');
        $a->match_status === 'duplicate' && $this->fail('A duplicate row is excluded from the totals; it cannot be matched.');
        in_array($a->source, ['gl_import', 'manual'], true) || $this->fail('Only GL and manual rows can be matched; a posting from a document keeps the document\'s line.');
        $this->assertOpenPeriod(Period::withoutGlobalScopes()->find($a->period_id));
        $current = $this->budgets->resolveBaselineLine($line) ?? $this->fail('Pick a line of the current baseline budget.', 'budget_line_id');
        (int) Budget::withoutGlobalScopes()->whereKey($current->budget_id)->value('fiscal_year_id') === (int) $a->fiscal_year_id
            || $this->fail('The line must belong to the actual\'s fiscal year.', 'budget_line_id');
        in_array((int) $current->cost_centre_id, $this->recon->tree((int) $a->organization_id, (int) $a->cost_centre_id), true)
            || $this->fail('The line must be in the actual\'s cost centre (or its parent or children).', 'budget_line_id');
        $a->forceFill(['budget_line_id' => $current->id, 'match_status' => 'matched', 'match_reason' => 'manual', 'match_rule_id' => null, 'matched_by_id' => $by->id, 'matched_at' => now()])->save();
        $this->audit($a, $by, 'matched', "Actual #{$a->id} matched to budget line {$current->line_no}", ['line_id' => $current->id]);
        if ($remember) {
            $cond = array_filter(['gl_account_id' => $a->gl_account_id, 'vendor_id' => $a->vendor_id, 'contract_id' => $a->contract_id]);
            $cond && $this->recon->saveRule($cond + ['name' => 'Remembered: '.mb_substr((string) $a->description, 0, 80), 'budget_line_id' => $current->id, 'cost_centre_id' => $a->cost_centre_id, 'priority' => 100], $by);
        }

        return $a->refresh();
    }

    public function unmatch(Actual $a, User $by): Actual
    {
        (int) $by->organization_id === (int) $a->organization_id || $this->fail('This actual belongs to another bank.');
        in_array($a->source, ['gl_import', 'manual'], true) || $this->fail('Only GL and manual rows can be unmatched; a posting from a document keeps the document\'s line.');
        $a->match_status === 'duplicate' && $this->fail('A duplicate row cannot be unmatched.');
        $this->assertOpenPeriod(Period::withoutGlobalScopes()->find($a->period_id));
        $a->forceFill(['budget_line_id' => null, 'match_status' => 'unmatched', 'match_reason' => null, 'match_rule_id' => null, 'matched_by_id' => null, 'matched_at' => null])->save();
        $this->audit($a, $by, 'unmatched', "Actual #{$a->id} returned to the unmatched queue");

        return $a->refresh();
    }

    public function acknowledgeUnbudgeted(Actual $a, User $by, string $note): Actual
    {
        (int) $by->organization_id === (int) $a->organization_id || $this->fail('This actual belongs to another bank.');
        $this->assertOpenPeriod(Period::withoutGlobalScopes()->find($a->period_id));
        $a->match_status === 'unmatched' || $this->fail('Only an unmatched row can be acknowledged as unbudgeted.');
        mb_strlen(trim($note)) >= 5 || $this->fail('Give a short note for the acknowledgement.', 'note');
        $a->forceFill(['match_status' => 'unbudgeted', 'match_reason' => 'acknowledged', 'matched_by_id' => $by->id, 'matched_at' => now()])->save();
        $this->audit($a, $by, 'unbudgeted_acknowledged', "Actual #{$a->id} acknowledged as unbudgeted", ['note' => $note]);

        return $a->refresh();
    }

    /**
     * C26: the base-currency actuals of a prior-year line (its whole lineage), annualised by run-rate when that year still has
     * open periods. Null when nothing was posted.
     */
    public function priorYearActual(BudgetLine $priorLine): ?string
    {
        $fyId = (int) Budget::withoutGlobalScopes()->whereKey($priorLine->budget_id)->value('fiscal_year_id');
        $sum = (string) Actual::withoutGlobalScopes()->whereIn('budget_line_id', $this->ledger->lineageIds($priorLine))->where('fiscal_year_id', $fyId)
            ->where('match_status', '!=', 'duplicate')->sum('base_amount');
        if (bccomp($sum, '0', 2) === 0) {
            return null;
        }
        $periods = Period::withoutGlobalScopes()->where('fiscal_year_id', $fyId)->where('is_adjustment', false)->get(['status']);
        $open = $periods->where('status', 'open')->count();
        $elapsed = $periods->count() - $open;
        if ($open > 0) {
            if ($elapsed < 1) {
                return null;
            }
            $sum = bcmul(bcdiv($sum, (string) $elapsed, 10), (string) $periods->count(), 2);
        }

        return bcadd($sum, '0', 2);
    }

    /* ------------------------------------------------------------------ parsing */

    /** @param ImportMapping|array<string,mixed> $map @return array<string,mixed> */
    private function mapping(ImportMapping|array $map): array
    {
        $m = $map instanceof ImportMapping ? $map->only(['columns', 'delimiter', 'date_format', 'decimal_comma', 'has_header', 'default_currency']) : $map;
        $m['columns'] = (array) ($m['columns'] ?? []);
        $m['delimiter'] = ($m['delimiter'] ?? ',') === '\t' || ($m['delimiter'] ?? '') === 'tab' ? "\t" : (($m['delimiter'] ?? '') !== '' ? $m['delimiter'] : ',');
        $m['date_format'] = $m['date_format'] ?? 'Y-m-d';
        $m['decimal_comma'] = (bool) ($m['decimal_comma'] ?? false);
        $m['has_header'] = (bool) ($m['has_header'] ?? true);
        in_array($m['date_format'], config('finance.actuals.date_formats'), true) || $this->fail('Unsupported date format.', 'date_format');
        foreach (ImportMapping::REQUIRED as $k) {
            empty($m['columns'][$k]) && $this->fail("Map the \"{$k}\" column.", 'columns');
        }

        return $m;
    }

    /** @return array{report:array<string,mixed>,valid:list<array<string,mixed>>,file_error:?string} */
    private function analyse(UploadedFile $f, array $m, int $orgId): array
    {
        $errors = [];
        $err = function (int $row, string $field, string $msg) use (&$errors) {
            count($errors) < self::ERROR_CAP && ($errors[] = ['row' => $row, 'field' => $field, 'message' => $msg]);
        };
        $base = ['mode' => 'preview', 'type' => 'actuals', 'file_name' => $f->getClientOriginalName(), 'rows' => 0, 'accepted' => 0, 'rejected' => 0, 'duplicates' => 0,
            'duplicates_skipped' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => [], 'sample' => [], 'batch_id' => null];
        $fail = function (string $msg) use ($base, $err, &$errors) {
            $err(0, 'file', $msg);

            return ['report' => ['errors' => $errors, 'rejected' => 1] + $base, 'valid' => [], 'file_error' => $msg];
        };
        if ($f->getSize() > config('finance.actuals.max_kb') * 1024) {
            return $fail('The file is larger than '.config('finance.actuals.max_kb').' KB.');
        }
        $fh = fopen($f->getRealPath(), 'r');
        if (! $fh) {
            return $fail('The file could not be read.');
        }
        $rows = [];
        $header = null;
        $n = 0;
        while (($cells = fgetcsv($fh, 0, $m['delimiter'])) !== false) {
            $n++;
            if ($n === 1 && $m['has_header']) {
                $header = array_map(fn ($h) => mb_strtolower(trim(ltrim((string) $h, "\xEF\xBB\xBF"))), $cells);

                continue;
            }
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $rows[] = [$n, $cells];
            if (count($rows) > config('finance.import_max_rows')) {
                fclose($fh);

                return $fail('The file has more than '.config('finance.import_max_rows').' rows; split it.');
            }
        }
        fclose($fh);
        $index = [];
        foreach ($m['columns'] as $key => $col) {
            if ($m['has_header']) {
                $pos = array_search(mb_strtolower(trim((string) $col)), $header ?? [], true);
                if ($pos === false) {
                    if (in_array($key, ImportMapping::REQUIRED, true)) {
                        return $fail("Column \"{$col}\" (for {$key}) was not found in the file header.");
                    }

                    continue;
                }
                $index[$key] = $pos;
            } else {
                $index[$key] = max(0, (int) $col - 1);
            }
        }

        $ccs = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->pluck('id', 'code');
        $gls = GlAccount::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->get(['id', 'code', 'type', 'name'])->keyBy('code');
        $vendors = Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower($name) => $id]);
        $contracts = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'contract_code');
        $currencies = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->pluck('code')->all();
        $baseCurrency = $this->settings->baseCurrency($orgId);
        $valid = [];
        $sample = [];
        $rejected = 0;
        $duplicatesSkipped = 0;
        foreach ($rows as [$line, $cells]) {
            $get = fn (string $k) => isset($index[$k]) ? trim((string) ($cells[$index[$k]] ?? '')) : '';
            $bad = false;
            $date = \DateTime::createFromFormat('!'.$m['date_format'], $get('posted_on'));
            $dateErrs = \DateTime::getLastErrors();
            if (! $date || ($dateErrs && ($dateErrs['warning_count'] || $dateErrs['error_count']))) {
                $err($line, 'posted_on', "Date \"{$get('posted_on')}\" does not match {$m['date_format']}.");
                $bad = true;
                $date = null;
            }
            $period = null;
            if ($date) {
                $period = $this->periodContaining($orgId, CarbonImmutable::instance($date));
                if (! $period) {
                    $err($line, 'posted_on', 'No fiscal period covers this date.');
                    $bad = true;
                } elseif ($period->status !== 'open') {
                    $err($line, 'posted_on', "{$period->name} is {$period->status}; GL rows can only be posted into an open period.");
                    $bad = true;
                }
            }
            $ccId = $ccs[$get('cost_centre_code')] ?? null;
            ! $ccId && ($err($line, 'cost_centre_code', "Unknown or inactive cost centre \"{$get('cost_centre_code')}\".") || true) && ($bad = true);
            $gl = $gls[$get('gl_code')] ?? null;
            ! $gl && ($err($line, 'gl_code', "Unknown or inactive GL account \"{$get('gl_code')}\".") || true) && ($bad = true);
            $raw = $get('amount');
            if ($m['decimal_comma']) {
                $raw = str_replace(['.', ' '], '', $raw);
                $raw = str_replace(',', '.', $raw);
            }
            if (! preg_match('/^-?\d{1,16}(\.\d{1,2})?$/', $raw) || bccomp($raw, '0', 2) === 0) {
                $err($line, 'amount', "Amount \"{$get('amount')}\" is not a plain non-zero decimal number.");
                $bad = true;
            }
            $currency = strtoupper($get('currency') ?: ($m['default_currency'] ?? '') ?: $baseCurrency);
            if (! in_array($currency, $currencies, true)) {
                $err($line, 'currency', "Currency {$currency} is not active for this bank.");
                $bad = true;
            }
            $ref = $get('reference');
            mb_strlen($ref) > 120 && ($err($line, 'reference', 'The reference is longer than 120 characters.') || true) && ($bad = true);
            $vendorId = $get('vendor') !== '' ? ($vendors[mb_strtolower($get('vendor'))] ?? null) : null;
            $contractId = $get('contract_code') !== '' ? ($contracts[$get('contract_code')] ?? null) : null;
            if ($bad) {
                $rejected++;

                continue;
            }
            $posted = CarbonImmutable::instance($date)->toDateString();
            if ($ref !== '' && Actual::withoutGlobalScopes()->where('organization_id', $orgId)->where('source', 'gl_import')->where('source_ref', $ref)->where('gl_account_id', $gl->id)
                ->where('cost_centre_id', $ccId)->whereDate('posted_on', $posted)->where('amount', $raw)->exists()) {
                $duplicatesSkipped++;

                continue;
            }
            $attrs = [
                'posted_on' => $posted, 'period_id' => $period->id, 'cost_centre_id' => $ccId, 'gl_account_id' => $gl->id, 'amount' => $raw, 'currency' => $currency,
                'description' => $get('description') ?: $gl->name, 'vendor_id' => $vendorId, 'contract_id' => $contractId, 'is_capex' => $gl->type === 'capex',
            ];
            $valid[] = ['row' => $line, 'attrs' => $attrs, 'source_ref' => $ref ?: null];
            count($sample) < 20 && ($sample[] = ['row' => $line] + $attrs + ['reference' => $ref]);
        }

        $matched = $unmatched = 0;
        foreach ($valid as $v) {
            $probe = new Actual;
            $probe->forceFill($v['attrs'] + ['organization_id' => $orgId, 'fiscal_year_id' => Period::withoutGlobalScopes()->whereKey($v['attrs']['period_id'])->value('fiscal_year_id'), 'source' => 'gl_import']);
            $this->recon->duplicateOf($orgId, $v['source_ref']) ? null : ($this->recon->autoMatch($probe)['line'] ? $matched++ : $unmatched++);
        }

        return ['report' => [
            'mode' => 'preview', 'type' => 'actuals', 'file_name' => $f->getClientOriginalName(), 'rows' => count($rows), 'accepted' => count($valid), 'rejected' => $rejected,
            'duplicates' => $duplicatesSkipped, 'duplicates_skipped' => $duplicatesSkipped, 'matched' => $matched, 'unmatched' => $unmatched, 'errors' => $errors, 'sample' => $sample, 'batch_id' => null,
        ], 'valid' => $valid, 'file_error' => null];
    }
}
