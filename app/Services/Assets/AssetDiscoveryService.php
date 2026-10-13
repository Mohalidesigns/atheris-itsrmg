<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetSyncJob;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Asset discovery and auto-population (plan gap D1.1).
 *
 * Every connector run and CSV import goes through one reconciler, which keeps the register clean:
 * 1. a record already known by (source, external_id) is updated in place and marked seen;
 * 2. otherwise a record whose hostname or IP matches an existing asset is adopted by it (a
 *    de-duplication, counted as an update), so a scanner does not create a second Finacle server;
 * 3. anything else is created. Connector finds land as "under_review" with no owner, in the
 *    review queue on Asset Discovery; nothing a scanner found becomes part of the governed
 *    register until a person accepts it. CSV rows are curated by a person, so they land active.
 *
 * Connectors run in sandbox mode: no bank has supplied AD, AWS, Tenable or Qualys credentials, so
 * each connector reads a deterministic catalogue built from the bank's own estate plus a few
 * hosts the bank has not registered. The job is recorded with mode "sandbox" and true counts.
 */
class AssetDiscoveryService
{
    public const CONNECTORS = [
        'ad' => ['label' => 'Active Directory', 'types' => ['hardware'], 'categories' => ['Infrastructure', 'Server']],
        'entra' => ['label' => 'Microsoft Entra ID', 'types' => ['cloud_service'], 'categories' => ['Infrastructure', 'Security']],
        'aws' => ['label' => 'AWS (EC2 / RDS / S3)', 'types' => ['cloud_service'], 'categories' => ['Cloud', 'Data']],
        'tenable' => ['label' => 'Tenable.io', 'types' => ['hardware', 'database'], 'categories' => ['Network', 'Server', 'Data']],
        'qualys' => ['label' => 'Qualys VMDR', 'types' => ['hardware', 'software'], 'categories' => ['Server', 'Security']],
    ];

    /** Hosts each connector reports that the seeded registers do not hold. */
    private const NEW_FINDS = [
        'ad' => [
            ['WIN-FS-LAG-03', 'File server — Lagos HQ (Finance share)', 'hardware', 'Server', '10.10.4.31', 'Microsoft', 'Windows Server 2016'],
            ['WIN-PRN-ABJ-01', 'Print server — Abuja branch cluster', 'hardware', 'Server', '10.30.2.12', 'Microsoft', 'Windows Server 2012 R2'],
        ],
        'entra' => [
            ['entra-app-9f2c', 'Entra app registration — Treasury Excel add-in', 'cloud_service', 'Security', null, 'Microsoft', null],
        ],
        'aws' => [
            ['i-0a7f3c19e2b4d5601', 'EC2 — statement-render-worker (eu-west-1)', 'cloud_service', 'Cloud', '172.31.14.207', 'Amazon Web Services', 't3.large'],
            ['arn:aws:s3:::kyc-scans-archive', 'S3 bucket — kyc-scans-archive', 'cloud_service', 'Data', null, 'Amazon Web Services', null],
            ['db-RPTREPLICA02', 'RDS — reporting replica (PostgreSQL 13)', 'database', 'Data', '172.31.40.18', 'Amazon Web Services', 'PostgreSQL 13'],
        ],
        'tenable' => [
            ['tnb-7c1e0a', 'Unregistered host 10.20.8.45 (OpenSSH 7.4, Apache 2.4.6)', 'hardware', 'Server', '10.20.8.45', null, 'CentOS 7'],
            ['tnb-91be44', 'Branch router — Kano Sabon Gari', 'hardware', 'Network', '10.60.1.1', 'Cisco', 'IOS 15.2'],
        ],
        'qualys' => [
            ['qid-host-55821', 'Jump host — vendor support (Windows 10)', 'hardware', 'Server', '10.10.9.77', 'Microsoft', 'Windows 10 21H2'],
        ],
    ];

    public const CSV_COLUMNS = ['name', 'asset_type', 'criticality', 'status', 'owner_email', 'department', 'location',
        'hostname', 'ip_address', 'vendor', 'version', 'data_classification', 'end_of_life', 'external_id', 'description'];

    public const MAX_CSV_ROWS = 2000;

    public function __construct(private AssetCriticality $criticality) {}

    /** Run one connector for the signed-in user's bank. */
    public function sync(string $source, User $user): AssetSyncJob
    {
        abort_unless(isset(self::CONNECTORS[$source]), 422, 'Unknown discovery source.');

        $started = now();
        $stats = $this->reconcile($this->catalogue($source, (int) $user->organization_id), $source, (int) $user->organization_id, 'under_review');

        return AssetSyncJob::create([
            'organization_id' => $user->organization_id,
            'source' => $source,
            'status' => 'completed',
            'mode' => 'sandbox',
            'triggered_by' => $user->id,
            'records_imported' => $stats['seen'],
            'records_created' => $stats['created'],
            'records_updated' => $stats['updated'],
            'records_skipped' => $stats['skipped'],
            'errors' => $stats['errors'] ?: null,
            'output_summary' => sprintf('%s (sandbox catalogue): %d seen — %d new for review, %d matched to the register.',
                self::CONNECTORS[$source]['label'], $stats['seen'], $stats['created'], $stats['updated']),
            'started_at' => $started,
            'finished_at' => now(),
        ]);
    }

    /** Import a CSV file in the template's format. Rows that fail validation are skipped and reported. */
    public function importCsv(UploadedFile $file, User $user): AssetSyncJob
    {
        $started = now();
        $orgId = (int) $user->organization_id;
        [$rows, $errors] = $this->parseCsv($file->getRealPath(), $orgId);

        $stats = $this->reconcile(collect($rows), 'csv', $orgId, 'active');
        $errors = array_merge($errors, $stats['errors']);

        return AssetSyncJob::create([
            'organization_id' => $orgId,
            'source' => 'csv',
            'status' => $errors && ! $stats['seen'] ? 'failed' : 'completed',
            'mode' => 'live',
            'triggered_by' => $user->id,
            'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'records_imported' => $stats['seen'],
            'records_created' => $stats['created'],
            'records_updated' => $stats['updated'],
            'records_skipped' => count($errors),
            'errors' => $errors ? array_slice($errors, 0, 50) : null,
            'output_summary' => sprintf('CSV import: %d rows accepted — %d created, %d updated; %d rejected.',
                $stats['seen'], $stats['created'], $stats['updated'], count($errors)),
            'started_at' => $started,
            'finished_at' => now(),
        ]);
    }

    /**
     * The sandbox catalogue: the bank's registered assets of the connector's types (so a run
     * re-confirms them) plus the connector's new finds. Deterministic, so a second run creates nothing.
     */
    public function catalogue(string $source, int $organizationId): Collection
    {
        $connector = self::CONNECTORS[$source];

        $known = Asset::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->where('status', '!=', 'decommissioned')
            ->whereIn('asset_type', $connector['types'])
            ->whereIn('category', $connector['categories'])
            ->orderBy('id')
            ->get(['id', 'name', 'asset_type', 'category', 'hostname', 'ip_address', 'vendor', 'version', 'source', 'external_id'])
            ->map(fn ($a) => [
                'external_id' => $a->source === $source && $a->external_id ? $a->external_id : $source.'-'.substr(md5($organizationId.'|'.$a->id), 0, 10),
                'name' => $a->name, 'asset_type' => $a->asset_type, 'category' => $a->category,
                'hostname' => $a->hostname, 'ip_address' => $a->ip_address, 'vendor' => $a->vendor, 'version' => $a->version,
            ]);

        $new = collect(self::NEW_FINDS[$source])->map(fn ($f) => [
            'external_id' => $f[0], 'name' => $f[1], 'asset_type' => $f[2], 'category' => $f[3],
            'hostname' => str_contains($f[0], ':') ? null : Str::lower($f[0]), 'ip_address' => $f[4], 'vendor' => $f[5], 'version' => $f[6],
        ]);

        // A find from an earlier run is now a register row of the connector's types: report it once.
        return $new->concat($known)->unique('external_id')->values();
    }

    /** @return array{seen:int, created:int, updated:int, skipped:int, errors:array} */
    private function reconcile(Collection $records, string $source, int $orgId, string $newStatus): array
    {
        $stats = ['seen' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $touched = [];
        $register = fn () => Asset::withoutGlobalScopes()->where('organization_id', $orgId);

        foreach ($records as $record) {
            $stats['seen']++;
            $externalId = $record['external_id'] ?? null;

            $asset = $externalId ? $register()->where('source', $source)->where('external_id', $externalId)->first() : null;
            $asset ??= ! empty($record['hostname']) ? $register()->whereRaw('lower(hostname) = ?', [Str::lower($record['hostname'])])->first() : null;
            $asset ??= ! empty($record['ip_address']) ? $register()->where('ip_address', $record['ip_address'])->first() : null;
            $asset ??= $source === 'csv' && empty($externalId) ? $register()->whereRaw('lower(name) = ?', [Str::lower($record['name'])])->first() : null;

            if ($asset) {
                $updates = array_filter([
                    'ip_address' => $record['ip_address'] ?? null,
                    'version' => $record['version'] ?? null,
                    'vendor' => $record['vendor'] ?? null,
                ]);
                if ($source === 'csv') {
                    $updates += array_filter(array_intersect_key($record, array_flip(['description', 'department', 'location', 'hostname', 'data_classification', 'end_of_life', 'owner_id', 'criticality', 'status'])), fn ($v) => $v !== null && $v !== '');
                }
                // Provenance: a manual row the connector now sees is adopted by that connector.
                if (($asset->source ?? 'manual') === 'manual' || $asset->source === $source) {
                    $updates['source'] = $source;
                    $updates['external_id'] = $externalId ?? $asset->external_id;
                }
                $updates['last_seen_at'] = now();
                $asset->fill($updates)->save();
                $stats['updated']++;
            } else {
                $asset = Asset::withoutGlobalScopes()->create([
                    'organization_id' => $orgId,
                    'asset_id_code' => Asset::generateNextCode($orgId),
                    'name' => Str::limit($record['name'], 250, ''),
                    'asset_type' => $record['asset_type'],
                    'category' => $record['category'] ?? null,
                    'criticality' => $record['criticality'] ?? 'medium',
                    'status' => $record['status'] ?? $newStatus,
                    'source' => $source,
                    'external_id' => $externalId,
                    'last_seen_at' => now(),
                    'hostname' => $record['hostname'] ?? null,
                    'ip_address' => $record['ip_address'] ?? null,
                    'vendor' => $record['vendor'] ?? null,
                    'version' => $record['version'] ?? null,
                    'owner_id' => $record['owner_id'] ?? null,
                    'department' => $record['department'] ?? null,
                    'location' => $record['location'] ?? null,
                    'data_classification' => $record['data_classification'] ?? null,
                    'end_of_life' => $record['end_of_life'] ?? null,
                    'description' => $record['description'] ?? "Found by {$source} discovery; awaiting review.",
                ]);
                $stats['created']++;
            }
            $touched[] = $asset->id;
        }

        if ($touched) {
            $this->criticality->recompute($touched);
        }

        return $stats;
    }

    /** @return array{0: array, 1: array} valid rows and "Row n: reason" errors */
    private function parseCsv(string $path, int $orgId): array
    {
        $handle = fopen($path, 'r');
        $header = $handle ? fgetcsv($handle) : false;
        if (! $header) {
            return [[], ['The file is empty or not a CSV.']];
        }

        $header = array_map(fn ($h) => Str::snake(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(['name', 'asset_type'], $header);
        if ($missing) {
            fclose($handle);

            return [[], ['Missing required column(s): '.implode(', ', $missing).'. Download the template for the expected layout.']];
        }

        $owners = User::where('organization_id', $orgId)->pluck('id', 'email')->mapWithKeys(fn ($id, $email) => [Str::lower($email) => $id]);
        $rows = [];
        $errors = [];
        $line = 1;

        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($rows) + count($errors) >= self::MAX_CSV_ROWS) {
                $errors[] = 'Only the first '.self::MAX_CSV_ROWS.' rows are imported per file.';
                break;
            }

            $row = [];
            foreach ($header as $i => $column) {
                if (in_array($column, self::CSV_COLUMNS, true)) {
                    $value = trim((string) ($cells[$i] ?? ''));
                    $row[$column] = $value === '' ? null : $value;
                }
            }

            $problem = $this->rowProblem($row);
            if (! $problem && ! empty($row['owner_email'])) {
                $row['owner_id'] = $owners[Str::lower($row['owner_email'])] ?? null;
                $problem = $row['owner_id'] ? null : "owner {$row['owner_email']} is not a user of this bank";
            }
            if ($problem) {
                $errors[] = "Row {$line}: {$problem}.";

                continue;
            }

            unset($row['owner_email']);
            foreach (['asset_type', 'criticality', 'status', 'data_classification'] as $enum) {
                if (isset($row[$enum])) {
                    $row[$enum] = Str::snake(Str::lower($row[$enum]));
                }
            }
            $rows[] = $row;
        }
        fclose($handle);

        return [$rows, $errors];
    }

    private function rowProblem(array $row): ?string
    {
        $enum = fn ($value, array $allowed) => $value === null || in_array(Str::snake(Str::lower($value)), $allowed, true);

        return match (true) {
            empty($row['name']) => 'name is required',
            mb_strlen($row['name']) > 255 => 'name is longer than 255 characters',
            empty($row['asset_type']) => 'asset_type is required',
            ! $enum($row['asset_type'], Asset::ASSET_TYPES) => "asset_type \"{$row['asset_type']}\" is not one of ".implode(', ', Asset::ASSET_TYPES),
            ! $enum($row['criticality'] ?? null, Asset::CRITICALITIES) => "criticality \"{$row['criticality']}\" is not one of ".implode(', ', Asset::CRITICALITIES),
            ! $enum($row['status'] ?? null, Asset::STATUSES) => "status \"{$row['status']}\" is not one of ".implode(', ', Asset::STATUSES),
            ! $enum($row['data_classification'] ?? null, Asset::DATA_CLASSIFICATIONS) => "data_classification \"{$row['data_classification']}\" is not one of ".implode(', ', Asset::DATA_CLASSIFICATIONS),
            ! empty($row['ip_address']) && ! filter_var($row['ip_address'], FILTER_VALIDATE_IP) => "ip_address \"{$row['ip_address']}\" is not a valid IP",
            ! empty($row['end_of_life']) && strtotime($row['end_of_life']) === false => "end_of_life \"{$row['end_of_life']}\" is not a date",
            default => null,
        };
    }
}
