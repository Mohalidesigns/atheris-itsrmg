<?php

namespace App\Services\Finance;

use Illuminate\Validation\ValidationException;

/**
 * Reads a cloud provider cost export (AWS CUR, Azure, GCP billing export, or the generic layout) into canonical rows
 * (plan P6-2). Pure: no database, so every format is unit-tested from a fixture file.
 */
class CloudExportParser
{
    /**
     * @param  array{max_rows?:int,default_currency?:?string}  $options
     * @return array{rows:list<array{usage_date:string,service:string,resource_id:?string,region:?string,tags:array<string,string>,cost:string,currency:string}>,rejected:list<array{line:int,reason:string}>,row_count:int,date_from:?string,date_to:?string}
     */
    public function parse(string $path, string $format, string $accountIdentifier, array $options = []): array
    {
        $spec = config("finance.cloud.formats.{$format}");
        $spec || $this->refuse("Unknown export format \"{$format}\".");
        $max = (int) ($options['max_rows'] ?? config('finance.cloud.max_rows', 200000));
        $fh = @fopen($path, 'r');
        $fh || $this->refuse('The file could not be read.');

        $header = fgetcsv($fh, 0, ',', '"', '');
        if (! $header || $header === [null]) {
            fclose($fh);
            $this->refuse('The file is empty.');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($h) => trim((string) $h), $header);
        $lower = array_map('mb_strtolower', $header);
        $index = fn (array|string $names): ?int => collect((array) $names)->map(fn ($n) => array_search(mb_strtolower($n), $lower, true))->first(fn ($i) => $i !== false);

        $col = [];
        foreach (['date', 'service', 'resource_id', 'region', 'cost', 'currency', 'account'] as $k) {
            $col[$k] = $index((array) ($spec[$k] ?? []));
        }
        $missing = [];
        $col['date'] === null && $missing[] = implode(' / ', $spec['date']);
        $col['cost'] === null && $missing[] = implode(' / ', $spec['cost']);
        if ($missing) {
            fclose($fh);
            $this->refuse('The file has no '.implode(' and ', $missing).' column for the '.$format.' format.');
        }
        $jsonCols = array_values(array_filter(array_map($index, (array) ($spec['tags_json'] ?? [])), fn ($i) => $i !== null));
        $kvCols = array_values(array_filter(array_map($index, (array) ($spec['tags_kv'] ?? [])), fn ($i) => $i !== null));
        $prefix = isset($spec['tag_prefix']) ? mb_strtolower((string) $spec['tag_prefix']) : null;
        $prefixCols = [];
        if ($prefix !== null) {
            foreach ($lower as $i => $h) {
                str_starts_with($h, $prefix) && strlen($h) > strlen($prefix) && $prefixCols[$i] = trim(substr($header[$i], strlen($prefix)));
            }
        }

        $rows = [];
        $rejected = [];
        $count = 0;
        $line = 1;
        $from = $to = null;
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || ! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            if (++$count > $max) {
                fclose($fh);
                $this->refuse("The file has more than {$max} rows; split it by month.");
            }
            $get = fn (?int $i): string => $i === null ? '' : trim((string) ($cells[$i] ?? ''));

            $account = $get($col['account']);
            if ($col['account'] !== null && $account !== '' && $account !== $accountIdentifier) {
                $rejected[] = ['line' => $line, 'reason' => "Account {$account} is not {$accountIdentifier}"];

                continue;
            }
            $date = $this->date($get($col['date']));
            if ($date === null) {
                $rejected[] = ['line' => $line, 'reason' => 'Unreadable date "'.mb_substr($get($col['date']), 0, 40).'"'];

                continue;
            }
            $cost = $get($col['cost']);
            if (! preg_match('/^-?\d{1,14}(\.\d{1,10})?$/', $cost)) {
                $rejected[] = ['line' => $line, 'reason' => 'Cost "'.mb_substr($cost, 0, 30).'" is not a plain number'];

                continue;
            }
            $currency = strtoupper($get($col['currency']) ?: (string) ($options['default_currency'] ?? ''));
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                $rejected[] = ['line' => $line, 'reason' => 'Currency "'.mb_substr($currency, 0, 10).'" is not a 3-letter code'];

                continue;
            }
            $tags = [];
            foreach ($prefixCols as $i => $key) {
                $v = $get($i);
                $v !== '' && $key !== '' && $tags[mb_substr($key, 0, 128)] = mb_substr($v, 0, 255);
            }
            foreach ($jsonCols as $i) {
                $tags = $this->mergeJson($tags, $get($i));
            }
            foreach ($kvCols as $i) {
                foreach (explode(';', $get($i)) as $pair) {
                    if (str_contains($pair, '=')) {
                        [$k, $v] = array_map('trim', explode('=', $pair, 2));
                        $k !== '' && $v !== '' && $tags[mb_substr($k, 0, 128)] = mb_substr($v, 0, 255);
                    }
                }
            }
            $resource = $get($col['resource_id']);
            $region = $get($col['region']);
            $rows[] = [
                'usage_date' => $date, 'service' => mb_substr($get($col['service']) ?: 'Unspecified', 0, 160), 'resource_id' => $resource === '' ? null : mb_substr($resource, 0, 512),
                'region' => $region === '' ? null : mb_substr($region, 0, 64), 'tags' => $tags, 'cost' => bcadd($cost, '0', 6), 'currency' => $currency,
            ];
            $from = $from === null || $date < $from ? $date : $from;
            $to = $to === null || $date > $to ? $date : $to;
        }
        fclose($fh);

        return ['rows' => $rows, 'rejected' => $rejected, 'row_count' => $count, 'date_from' => $from, 'date_to' => $to];
    }

    /**
     * Sums rows to daily lines keyed (usage_date, service, resource_id, region, tags); line_hash = sha1 of that key (plan P6-2).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    public static function aggregate(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $tags = (array) ($r['tags'] ?? []);
            ksort($tags);
            $hash = sha1(json_encode([$r['usage_date'], $r['service'], $r['resource_id'] ?? null, $r['region'] ?? null, $tags], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $k = $hash.'|'.($r['currency'] ?? '');
            if (isset($out[$k])) {
                $out[$k]['cost'] = bcadd($out[$k]['cost'], (string) $r['cost'], 6);

                continue;
            }
            $out[$k] = ['tags' => $tags, 'line_hash' => $hash] + $r;
        }

        return array_values($out);
    }

    private function date(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) { // Azure: m/d/Y
            return checkdate((int) $m[1], (int) $m[2], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]) : null;
        }

        return null;
    }

    /** @param array<string,string> $tags @return array<string,string> */
    private function mergeJson(array $tags, string $raw): array
    {
        if ($raw === '') {
            return $tags;
        }
        $data = json_decode($raw, true);
        if (! is_array($data) && ! str_starts_with($raw, '{') && ! str_starts_with($raw, '[')) {
            $data = json_decode('{'.$raw.'}', true); // Azure exports the tags as a fragment without braces
        }
        if (! is_array($data)) {
            return $tags;
        }
        foreach ($data as $k => $v) {
            if (is_array($v) && isset($v['key'])) { // GCP labels as a list of {key, value}
                $k = $v['key'];
                $v = $v['value'] ?? '';
            }
            $k = trim((string) $k);
            $v = is_scalar($v) ? trim((string) $v) : '';
            $k !== '' && $v !== '' && $tags[mb_substr($k, 0, 128)] = mb_substr($v, 0, 255);
        }

        return $tags;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
