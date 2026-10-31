<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BudgetLinePeriod;
use App\Models\Finance\Category;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\GlAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Budget workbook export / import (FIN-02.13). CSV only in P1 (XLSX needs a spreadsheet library the
 * platform does not have). Period columns are P01..Pnn by period sequence, in the line's currency.
 */
class BudgetWorkbook
{
    use LogsFinanceEvents;

    public const COLUMNS = [
        'line_no', 'description', 'cost_pool', 'tower', 'category', 'gl_account', 'is_capex', 'is_recurring', 'priority', 'rgt', 'vendor',
        'currency', 'quantity', 'unit_cost', 'annual_amount', 'phasing_method', 'justification',
    ];

    private const TEXT = ['description', 'vendor', 'justification'];

    public function __construct(private readonly BudgetService $budgets, private readonly BudgetPhasing $phasing) {}

    public function export(Budget $b, string $format = 'csv', bool $maskAmounts = false): StreamedResponse
    {
        if ($format !== 'csv') {
            $this->fail('Only CSV workbooks are supported; XLSX is not available in this release.', 'format');
        }
        [$header, $rows] = $this->table($b, $maskAmounts);
        $name = "{$b->budget_code}-v{$b->version_no}.csv";

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($k) => $r[$k] ?? '', $header));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: array<int,string>, 1: array<int, array<string,string>>} */
    public function table(Budget $b, bool $maskAmounts = false): array
    {
        $periods = $this->periods($b);
        $header = array_merge(self::COLUMNS, $periods->map(fn ($p) => $this->periodHeader($p))->all());
        $lines = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->orderBy('sort_order')->orderBy('line_no')->get();

        $money = array_merge(['unit_cost', 'annual_amount'], $periods->map(fn ($p) => $this->periodHeader($p))->all());

        return [$header, $lines->map(function (BudgetLine $l) use ($periods, $maskAmounts, $money) {
            $row = $this->rowFor($l, $periods, true);

            return $maskAmounts ? array_merge($row, array_fill_keys($money, '')) : $row;
        })->all()];
    }

    /** @return array<string,string> canonical cells of a line */
    private function rowFor(BudgetLine $l, Collection $periods, bool $forExport): array
    {
        $cat = fn ($id) => $id ? (Category::withoutGlobalScopes()->whereKey($id)->value('code') ?? '') : '';
        $amounts = BudgetLinePeriod::withoutGlobalScopes()->where('budget_line_id', $l->id)->pluck('amount', 'period_id');
        $row = [
            'line_no' => (string) $l->line_no, 'description' => $this->safe((string) $l->description, $forExport),
            'cost_pool' => $cat($l->cost_pool_id), 'tower' => $cat($l->tower_id), 'category' => $cat($l->category_id),
            'gl_account' => $l->gl_account_id ? (string) GlAccount::withoutGlobalScopes()->whereKey($l->gl_account_id)->value('code') : '',
            'is_capex' => $l->is_capex ? 'Y' : 'N', 'is_recurring' => $l->is_recurring ? 'Y' : 'N', 'priority' => $l->priority, 'rgt' => $l->rgt,
            'vendor' => $this->safe($l->vendor_id ? (string) Vendor::withoutGlobalScopes()->whereKey($l->vendor_id)->value('name') : '', $forExport),
            'currency' => $l->currency, 'quantity' => $this->num($l->quantity, 4), 'unit_cost' => $l->unit_cost === null ? '' : $this->num($l->unit_cost, 2),
            'annual_amount' => $this->num($l->annual_amount, 2), 'phasing_method' => $l->phasing_method,
            'justification' => $this->safe((string) ($l->justification ?? ''), $forExport),
        ];
        foreach ($periods as $p) {
            $row[$this->periodHeader($p)] = $this->num($amounts[$p->id] ?? '0', 2);
        }

        return $row;
    }

    /**
     * @return array{type:string,file_name:string,row_count:int,accepted_count:int,rejected_count:int,created:int,updated:int,unchanged:int,errors:array<int,array{row:int,message:string}>}
     */
    public function import(Budget $b, UploadedFile $file, User $by): array
    {
        if (! in_array($b->status, Budget::EDITABLE, true)) {
            $this->fail("Version {$b->version_no} is {$b->status}; import into a draft.");
        }
        if ($file->getSize() > (int) config('finance.workbook.max_kb') * 1024) {
            $this->fail('The file is larger than '.config('finance.workbook.max_kb').' KB.', 'file');
        }
        $handle = fopen($file->getRealPath(), 'r');
        $handle || $this->fail('The file could not be read.', 'file');
        $head = fgetcsv($handle) ?: [];
        $head = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $head);
        if (! in_array('description', $head, true)) {
            fclose($handle);
            $this->fail('Missing column(s): description. Export the workbook for the exact headers.', 'file');
        }
        $unknown = array_diff($head, array_merge(self::COLUMNS, $this->periods($b)->map(fn ($p) => $this->periodHeader($p))->all()));
        if ($unknown) {
            fclose($handle);
            $this->fail('Unknown column(s): '.implode(', ', $unknown).'.', 'file');
        }

        $max = (int) config('finance.workbook.max_rows');
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            if (count($rows) >= $max) {
                fclose($handle);
                $this->fail("The file has more than {$max} rows.", 'file');
            }
            $row = [];
            foreach ($head as $i => $h) {
                $row[$h] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = [$line, $row];
        }
        fclose($handle);

        $report = ['type' => 'budget_workbook', 'file_name' => $file->getClientOriginalName(), 'row_count' => count($rows), 'accepted_count' => 0,
            'rejected_count' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => []];
        $periods = $this->periods($b);
        $existing = BudgetLine::withoutGlobalScopes()->where('budget_id', $b->id)->get()->keyBy('line_no');
        $orgId = (int) $b->organization_id;
        $cats = Category::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'code');
        $gls = GlAccount::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'code');
        $vendors = Vendor::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'name');

        // Pass 1: translate and validate every row without writing.
        $plan = [];
        $seen = [];
        $err = function (int $row, string $message) use (&$report) {
            $report['errors'][] = ['row' => $row, 'message' => $message];
        };
        foreach ($rows as [$lineNo, $r]) {
            $before = count($report['errors']);
            $no = $r['line_no'] ?? '';
            if ($no !== '' && ! ctype_digit($no)) {
                $err($lineNo, 'line_no must be a whole number.');
            }
            if ($no !== '' && isset($seen[(int) $no])) {
                $err($lineNo, "Duplicate line_no {$no} (also on row {$seen[(int) $no]}).");
            }
            $no !== '' && ctype_digit($no) && $seen[(int) $no] ??= $lineNo;

            $data = [];
            foreach (['cost_pool' => 'cost_pool_id', 'tower' => 'tower_id', 'category' => 'category_id'] as $col => $field) {
                if (array_key_exists($col, $r)) {
                    $code = strtoupper($r[$col]);
                    if ($code !== '' && ! isset($cats[$code])) {
                        $err($lineNo, "Unknown {$col} code {$r[$col]}.");
                    }
                    $data[$field] = $code === '' ? null : ($cats[$code] ?? null);
                }
            }
            if (array_key_exists('gl_account', $r)) {
                if ($r['gl_account'] !== '' && ! isset($gls[$r['gl_account']])) {
                    $err($lineNo, "Unknown gl_account {$r['gl_account']}.");
                }
                $data['gl_account_id'] = $r['gl_account'] === '' ? null : ($gls[$r['gl_account']] ?? null);
            }
            if (array_key_exists('vendor', $r)) {
                $vn = $this->unsafe($r['vendor']);
                if ($vn !== '' && ! isset($vendors[$vn])) {
                    $err($lineNo, "Unknown vendor {$vn}.");
                }
                $data['vendor_id'] = $vn === '' ? null : ($vendors[$vn] ?? null);
            }
            foreach (['is_capex', 'is_recurring'] as $col) {
                if (array_key_exists($col, $r)) {
                    $v = strtolower($r[$col]);
                    if (! in_array($v, ['y', 'n', 'yes', 'no', '1', '0', 'true', 'false', ''], true)) {
                        $err($lineNo, "{$col} must be Y or N.");
                    }
                    if ($v !== '') {
                        $data[$col] = in_array($v, ['y', 'yes', '1', 'true'], true);
                    }
                }
            }
            foreach (['priority', 'rgt', 'phasing_method', 'currency'] as $col) {
                if (array_key_exists($col, $r) && $r[$col] !== '') {
                    $data[$col] = $col === 'currency' ? strtoupper($r[$col]) : strtolower($r[$col]);
                }
            }
            foreach (['description', 'justification'] as $col) {
                if (array_key_exists($col, $r)) {
                    $data[$col] = $this->unsafe($r[$col]);
                }
            }
            foreach (['quantity' => 4, 'unit_cost' => 2, 'annual_amount' => 2] as $col => $dp) {
                if (array_key_exists($col, $r) && $r[$col] !== '') {
                    if (! preg_match('/^-?\d{1,16}(\.\d{1,8})?$/', $r[$col])) {
                        $err($lineNo, "{$col} must be a plain number.");
                    } else {
                        $data[$col] = $r[$col];
                    }
                } elseif (array_key_exists($col, $r) && $col === 'unit_cost') {
                    $data['unit_cost'] = null;
                }
            }
            $cells = [];
            foreach ($periods as $p) {
                $h = $this->periodHeader($p);
                if (array_key_exists($h, $r) && $r[$h] !== '') {
                    if (! preg_match('/^-?\d{1,16}(\.\d{1,8})?$/', $r[$h])) {
                        $err($lineNo, "{$h} must be a plain number.");
                    } else {
                        $cells[$p->id] = $r[$h];
                    }
                }
            }
            if (count($report['errors']) === $before) {
                $plan[] = [$lineNo, $no === '' ? null : (int) $no, $data, $cells, $r];
            }
        }
        if ($report['errors']) {
            $rejected = count(array_unique(array_column($report['errors'], 'row')));
            $report['rejected_count'] = $rejected;

            return $report;
        }

        // Pass 2: apply inside one transaction; any refusal rolls everything back.
        try {
            DB::transaction(function () use ($plan, $b, $by, $existing, $periods, &$report) {
                foreach ($plan as [$lineNo, $no, $data, $cells, $r]) {
                    $line = $no !== null ? $existing->get($no) : null;
                    try {
                        if ($line && $this->unchanged($line, $r, $periods)) {
                            $report['unchanged']++;

                            continue;
                        }
                        $method = $data['phasing_method'] ?? $line?->phasing_method ?? 'equal';
                        if ($cells) {
                            $annual = $data['annual_amount'] ?? ($line ? (string) $line->annual_amount : null);
                            $spread = $annual !== null ? $this->phasing->spread(bcadd($annual, '0', 2), $this->phasing->weights($method, $periods->count()), 2) : null;
                            $given = $periods->map(fn ($p) => bcadd($cells[$p->id] ?? '0', '0', 2))->values()->all();
                            if ($method === 'custom' || $spread !== $given) {
                                $data['phasing_method'] = 'custom';
                                $data['amounts'] = $cells;
                                if (! isset($data['annual_amount']) && ! $line) {
                                    $data['annual_amount'] = array_reduce($cells, fn ($c, $a) => bcadd($c, $a, 2), '0');
                                }
                            }
                        }
                        if ($line) {
                            $this->budgets->saveLine($b, $line, $data, $by);
                            $report['updated']++;
                        } else {
                            $this->budgets->saveLine($b, null, $data + ($no !== null ? ['line_no' => $no] : []), $by);
                            $report['created']++;
                        }
                    } catch (ValidationException $e) {
                        foreach ($e->errors() as $field => $messages) {
                            $report['errors'][] = ['row' => $lineNo, 'message' => implode(' ', $messages)];
                        }
                    }
                }
                if ($report['errors']) {
                    throw ValidationException::withMessages(['file' => 'The workbook has errors; nothing was imported.']);
                }
                $this->budgets->recalculate($b);
            });
        } catch (ValidationException $e) {
            if (! $report['errors']) {
                throw $e;
            }
            $report['rejected_count'] = count(array_unique(array_column($report['errors'], 'row')));
            $report['created'] = $report['updated'] = $report['unchanged'] = 0;

            return $report;
        }
        $report['accepted_count'] = $report['row_count'];
        if ($report['created'] + $report['updated']) {
            $this->audit($b, $by, 'workbook_imported', "Workbook imported into {$b->budget_code} {$b->version_label}", array_diff_key($report, ['errors' => 1, 'type' => 1]));
        }

        return $report;
    }

    /** True when the file row equals the line's exported row, cell for cell (numbers compared by value). */
    private function unchanged(BudgetLine $l, array $r, Collection $periods): bool
    {
        $current = $this->rowFor($l, $periods, false);
        foreach ($r as $col => $v) {
            if (! array_key_exists($col, $current)) {
                continue;
            }
            $mine = $current[$col];
            if (in_array($col, ['quantity', 'unit_cost', 'annual_amount'], true) || str_starts_with($col, 'P')) {
                if ($v === '' || $mine === '') {
                    if ($v !== $mine) {
                        return false;
                    }

                    continue;
                }
                if (! preg_match('/^-?\d+(\.\d+)?$/', $v) || bccomp($v, $mine, 8) !== 0) {
                    return false;
                }

                continue;
            }
            if (in_array($col, self::TEXT, true)) {
                $v = $this->unsafe($v);
            } elseif (in_array($col, ['is_capex', 'is_recurring'], true)) {
                $v = in_array(strtolower($v), ['y', 'yes', '1', 'true'], true) ? 'Y' : 'N';
            } elseif (in_array($col, ['priority', 'rgt', 'phasing_method'], true)) {
                $v = strtolower($v);
            } elseif (in_array($col, ['cost_pool', 'tower', 'category', 'currency'], true)) {
                $v = strtoupper($v);
            }
            if ($v !== $mine && ! ($col === 'description' && $v === $this->unsafe($mine))) {
                return false;
            }
        }

        return true;
    }

    private function periods(Budget $b): Collection
    {
        return $this->phasing->periodsFor(FiscalYear::withoutGlobalScopes()->findOrFail($b->fiscal_year_id));
    }

    private function periodHeader($p): string
    {
        return 'P'.str_pad((string) $p->sequence, 2, '0', STR_PAD_LEFT);
    }

    private function num($v, int $dp): string
    {
        return bcadd((string) $v, '0', $dp);
    }

    /** Spreadsheet-injection guard: a leading = + - @ in a text cell is prefixed with an apostrophe. */
    private function safe(string $v, bool $export): string
    {
        return $export && $v !== '' && ! is_numeric($v) && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }

    private function unsafe(string $v): string
    {
        return strlen($v) > 1 && $v[0] === "'" && str_contains("=+-@\t\r", $v[1]) ? substr($v, 1) : $v;
    }
}
