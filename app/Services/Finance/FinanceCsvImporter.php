<?php

namespace App\Services\Finance;

use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\FxRate;
use App\Models\LegalEntity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CSV imports for FX rates and cost centres (ATH-FIN-001 FIN-01.02, FIN-01.05). Rows are validated
 * one by one: good rows are applied, bad rows are reported with their line number. The report
 * mirrors the future fin_import_batches columns so a later phase can persist it unchanged.
 */
class FinanceCsvImporter
{
    public const FX_RATE = '/^\d{1,10}(\.\d{1,8})?$/';

    private const MAX_REPORTED_ERRORS = 200;

    private const FX_HEADERS = ['from_currency', 'to_currency', 'rate', 'rate_date'];

    private const CC_HEADERS = ['code', 'name', 'parent_code', 'owner_email', 'legal_entity_code', 'business_unit', 'is_active'];

    public function fxRates(int $orgId, UploadedFile $file): array
    {
        $active = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->pluck('code')->all();

        return $this->run('fx_rates', $file, self::FX_HEADERS, function (array $r) use ($orgId, $active) {
            $from = strtoupper($r['from_currency']);
            $to = strtoupper($r['to_currency']);
            if (! in_array($from, $active, true) || ! in_array($to, $active, true)) {
                return 'Unknown or inactive currency code.';
            }
            if ($from === $to) {
                return 'From and to currencies must differ.';
            }
            if (! preg_match(self::FX_RATE, $r['rate']) || bccomp($r['rate'], '0', 8) <= 0 || bccomp($r['rate'], '9999999999', 8) > 0) {
                return 'Rate must be a plain decimal greater than 0 (up to 8 decimals).';
            }
            $date = $this->date($r['rate_date']);
            if (! $date) {
                return 'Invalid rate_date (use YYYY-MM-DD).';
            }
            if ($date->isFuture() && ! $date->isToday()) {
                return 'rate_date cannot be in the future.';
            }

            $row = FxRate::withoutGlobalScopes()->firstOrNew([
                'organization_id' => $orgId, 'from_currency' => $from, 'to_currency' => $to, 'rate_date' => $date->toDateString(),
            ]);
            $row->fill(['rate' => $r['rate'], 'source' => 'import'])->save();

            return null;
        });
    }

    public function costCentres(int $orgId, UploadedFile $file): array
    {
        $users = User::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'email')->mapWithKeys(fn ($id, $e) => [strtolower($e) => $id]);
        $entities = LegalEntity::query()->pluck('id', 'code');
        $codes = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'code')->all();
        $parents = CostCentre::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('parent_id', 'id')->all();

        return $this->run('cost_centres', $file, self::CC_HEADERS, function (array $r) use ($orgId, $users, $entities, &$codes, &$parents) {
            $code = strtoupper($r['code']);
            if ($code === '' || ! preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/', $code)) {
                return 'Invalid code.';
            }
            if ($r['name'] === '' || mb_strlen($r['name']) > 120) {
                return 'Name is required (max 120 characters).';
            }

            $parentId = null;
            if ($r['parent_code'] !== '') {
                $parentId = $codes[strtoupper($r['parent_code'])] ?? null;
                if (! $parentId) {
                    return "Unknown parent_code {$r['parent_code']}.";
                }
            }
            $ownerId = null;
            if ($r['owner_email'] !== '') {
                $ownerId = $users[strtolower($r['owner_email'])] ?? null;
                if (! $ownerId) {
                    return "Unknown owner_email {$r['owner_email']}.";
                }
            }
            $entityId = null;
            if ($r['legal_entity_code'] !== '') {
                $entityId = $entities[$r['legal_entity_code']] ?? null;
                if (! $entityId) {
                    return "Unknown legal_entity_code {$r['legal_entity_code']}.";
                }
            }

            $row = CostCentre::withoutGlobalScopes()->withTrashed()->firstOrNew(['organization_id' => $orgId, 'code' => $code]);
            if ($row->exists && $parentId && $this->createsCycle($parents, (int) $row->id, (int) $parentId)) {
                return 'A cost centre cannot be its own parent or descendant.';
            }
            $row->fill([
                'name' => $r['name'], 'parent_id' => $parentId, 'owner_id' => $ownerId, 'legal_entity_id' => $entityId,
                'business_unit' => $r['business_unit'] ?: null,
                'is_active' => $r['is_active'] === '' ? true : in_array(strtolower($r['is_active']), ['1', 'true', 'yes', 'y'], true),
            ]);
            $row->deleted_at = null;
            $row->save();
            $codes[$code] = $row->id;
            $parents[$row->id] = $parentId;

            return null;
        });
    }

    /** True when $parentId is $id itself or sits below it (walks up from the parent through the cached map). */
    private function createsCycle(array $parents, int $id, int $parentId): bool
    {
        for ($cur = $parentId, $guard = 0; $cur && $guard < 10000; $cur = $parents[$cur] ?? null, $guard++) {
            if ($cur === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(array<string,string>): ?string  $handler  returns an error message, or null when the row was applied
     */
    private function run(string $type, UploadedFile $file, array $headers, callable $handler): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }
        $head = fgetcsv($handle);
        $head = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $head ?: []);
        $missing = array_diff($headers, $head);
        if ($missing) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Missing column(s): '.implode(', ', $missing).'. Expected headers: '.implode(',', $headers).'.']);
        }

        $max = (int) config('finance.import_max_rows');
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            if (count($rows) >= $max) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "The file has more than {$max} rows."]);
            }
            $row = [];
            foreach ($headers as $h) {
                $i = array_search($h, $head, true);
                $row[$h] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = [$line, $row];
        }

        $report = ['type' => $type, 'file_name' => $file->getClientOriginalName(), 'row_count' => count($rows), 'accepted_count' => 0, 'rejected_count' => 0, 'errors' => []];
        DB::transaction(function () use ($rows, $handler, &$report) {
            foreach ($rows as [$line, $row]) {
                $error = $handler($row);
                if ($error) {
                    $report['rejected_count']++;
                    if (count($report['errors']) < self::MAX_REPORTED_ERRORS) {
                        $report['errors'][] = ['row' => $line, 'message' => $error];
                    }
                } else {
                    $report['accepted_count']++;
                }
            }
        });
        fclose($handle);

        return $report;
    }

    private function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $value);

            return $d && $d->toDateString() === $value ? $d : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
