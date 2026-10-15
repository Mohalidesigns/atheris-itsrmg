<?php

namespace App\Services\CoreBanking;

use App\Models\CoreBankingIntegration;
use App\Models\CoreBankingSnapshot;
use App\Models\FeatureFlag;
use App\Models\Issue;
use App\Models\User;
use App\Services\Issues\IssueWorkflow;
use App\Services\Monitoring\DriftService;
use Illuminate\Validation\ValidationException;

/**
 * Read-only technical-metadata connector for the bank's core and switch platforms (ATH-VAL-017).
 *
 * No bank has configured credentials, so a sync runs in sandbox mode against a deterministic
 * per-product catalogue (varied by integration and day, never random). Whatever the source
 * returns passes through an allowlist: only technical artefacts survive, any other key (customer
 * data in particular) is dropped and its name recorded as embargoed. Findings are assessed from
 * the artefacts; an unapproved platform change and any critical/high finding raise an issue.
 */
class CoreBankingConnector
{
    /** Top-level artefact keys that may be stored. */
    public const ALLOWED = [
        'platform', 'modules', 'branch_count', 'channels', 'interfaces', 'eod', 'access', 'database',
        'message_success_rate', 'timeout_rate', 'certificate_expiry', 'tls',
    ];

    /** Artefacts compared between snapshots. */
    private const TRACKED = ['platform', 'modules', 'branch_count', 'channels', 'interfaces', 'database', 'tls', 'certificate_expiry'];

    /** Fixed reference date for the sandbox catalogue, so its dates age into findings as time passes. */
    private const SANDBOX_ANCHOR = '2026-10-01';

    private const MODULES = ['Core Accounts', 'Loans', 'Deposits', 'Trade Finance', 'Treasury', 'General Ledger'];

    private const CHANNELS = ['Branch', 'Internet Banking', 'Mobile', 'USSD', 'ATM', 'Agent Banking'];

    /** Product => profile: version, patch level, patch age (days), support end (days ahead), database, interfaces, access, eod. */
    private const PROFILES = [
        'finacle' => ['version' => '11.2.4', 'patch' => 'SP4', 'age' => 38, 'support' => 420, 'db' => 'Oracle 19c RAC', 'branches' => 312,
            'interfaces' => [['CBS API', 'https', '1.3'], ['SWIFT Alliance', 'mq', '1.2'], ['NIP connector', 'https', '1.2']],
            'access' => [14, 0, 0], 'failed' => 0, 'eod' => 'success'],
        'flexcube' => ['version' => '12.4.0', 'patch' => 'PSU-2', 'age' => 96, 'support' => 120, 'db' => 'Oracle 12c', 'branches' => 48,
            'interfaces' => [['Treasury feed', 'jdbc', '1.0'], ['GL export', 'sftp', '1.2']],
            'access' => [9, 2, 1], 'failed' => 1, 'eod' => 'success'],
        't24' => ['version' => 'R23', 'patch' => 'AMR-3', 'age' => 55, 'support' => 700, 'db' => 'Oracle 19c', 'branches' => 120,
            'interfaces' => [['Transact API', 'https', '1.3']], 'access' => [7, 0, 0], 'failed' => 0, 'eod' => 'success'],
        'bankone' => ['version' => '5.9', 'patch' => 'HF-17', 'age' => 71, 'support' => 300, 'db' => 'SQL Server 2019', 'branches' => 36,
            'interfaces' => [['Agent gateway', 'https', '1.2'], ['NIP connector', 'https', '1.2']],
            'access' => [5, 1, 0], 'failed' => 0, 'eod' => 'success'],
        'nibss' => ['version' => 'NIP 3.4', 'patch' => 'r3', 'age' => 30, 'support' => null, 'db' => null, 'branches' => null,
            'interfaces' => [['NIP inbound', 'https', '1.2'], ['NIP outbound', 'https', '1.2']],
            'access' => [3, 0, 0], 'failed' => 0, 'eod' => 'success', 'cert' => 24],
        'interswitch' => ['version' => 'Paydirect 8.1', 'patch' => 'p9', 'age' => 52, 'support' => null, 'db' => null, 'branches' => null,
            'interfaces' => [['Card switch', 'iso8583', '1.2'], ['Webpay', 'https', '1.3']],
            'access' => [4, 0, 0], 'failed' => 0, 'eod' => 'success', 'cert' => 190],
    ];

    public function __construct(private IssueWorkflow $issues, private DriftService $drift) {}

    public function sync(CoreBankingIntegration $integration, ?User $actor = null): CoreBankingSnapshot
    {
        $this->assertEnabled($integration);

        $raw = $this->fetch($integration);
        $artefacts = array_intersect_key($raw, array_flip(self::ALLOWED));
        $embargoed = array_values(array_diff(array_keys($raw), self::ALLOWED));

        $previous = $integration->snapshots()->latest('captured_at')->latest('id')->first();
        $changes = $this->diff($previous?->artefacts ?? [], $artefacts);
        $findings = $this->assess($artefacts);
        if ($previous && collect($changes)->contains(fn ($c) => in_array($c['key'], ['platform.version', 'platform.patch_level'], true))
            && ! $this->approvedChange($integration, $previous)) {
            $findings[] = ['severity' => 'high', 'title' => 'Unapproved platform change',
                'detail' => 'The platform version or patch level changed since the last capture and no approved change request lists the asset.'];
        }

        $snapshot = CoreBankingSnapshot::create([
            'organization_id' => $integration->organization_id,
            'integration_id' => $integration->id,
            'product' => $integration->product,
            'mode' => $integration->mode ?: 'sandbox',
            'status' => $findings ? 'findings' : 'ok',
            'captured_at' => now(),
            'artefacts' => $artefacts,
            'findings' => $findings,
            'changes' => $changes,
            'embargoed_keys' => $embargoed,
            'triggered_by' => $actor?->id,
        ]);

        $integration->update([
            'last_sync_at' => now(), 'last_status' => $findings ? 'findings' : 'ok', 'last_error' => null,
            'findings_count' => count($findings),
        ]);
        $this->raiseIssue($integration, $findings, $actor);

        return $snapshot;
    }

    private function assertEnabled(CoreBankingIntegration $integration): void
    {
        $error = null;
        if ($integration->status === 'disabled') {
            $error = 'The integration is disabled.';
        } elseif (FeatureFlag::enabledFor('core_banking.'.$integration->product, (int) $integration->organization_id) === false) {
            $error = 'The '.(CoreBankingIntegration::PRODUCTS[$integration->product]['label'] ?? $integration->product).' adapter is switched off by feature flag core_banking.'.$integration->product.'.';
        }
        if ($error) {
            $integration->update(['last_status' => 'failed', 'last_error' => $error]);
            throw ValidationException::withMessages(['sync' => $error]);
        }
    }

    /** The sandbox payload for an integration and day, with any configured overrides applied. */
    private function fetch(CoreBankingIntegration $integration): array
    {
        $p = self::PROFILES[$integration->product] ?? self::PROFILES['finacle'];
        $h = crc32($integration->id.'|'.today()->toDateString());
        $anchor = now()->parse(self::SANDBOX_ANCHOR);
        $isSwitch = (CoreBankingIntegration::PRODUCTS[$integration->product]['kind'] ?? 'core') === 'switch';

        $raw = [
            'platform' => [
                'version' => $p['version'], 'patch_level' => $p['patch'],
                'last_patch_applied' => $anchor->copy()->subDays($p['age'] + $integration->id % 5)->toDateString(),
                'vendor_support_ends' => $p['support'] ? $anchor->copy()->addDays($p['support'])->toDateString() : null,
            ],
            'interfaces' => array_map(fn ($i) => ['name' => $i[0], 'protocol' => $i[1], 'tls' => $i[2]], $p['interfaces']),
            'eod' => ['last_run_minutes' => 120 + $h % 40, 'failed_jobs_7d' => $p['failed'] + ((($h >> 4) % 5) === 0 ? 1 : 0), 'last_run_status' => $p['eod']],
            'access' => ['privileged_accounts' => $p['access'][0] + $h % 3, 'generic_accounts' => $p['access'][1], 'dormant_privileged' => $p['access'][2]],
        ];
        if ($isSwitch) {
            $raw += [
                'message_success_rate' => round(99.85 - ($h % 45) / 100, 2),
                'timeout_rate' => round(0.08 + ($h % 14) / 100, 2),
                'certificate_expiry' => $anchor->copy()->addDays($p['cert'])->toDateString(),
                'tls' => '1.2',
            ];
        } else {
            $raw += [
                'modules' => array_slice(self::MODULES, 0, 4 + $integration->id % 3),
                'branch_count' => $p['branches'],
                'channels' => array_slice(self::CHANNELS, 0, 4 + $integration->id % 3),
                'database' => $p['db'],
            ];
        }
        if ($integration->product === 'bankone') {
            // A real adapter can return this; the embargo must drop it.
            $raw['customer_records'] = [['bvn' => '22000000000', 'name' => 'sandbox sample']];
        }

        $override = (array) ($integration->config['sandbox'] ?? []);
        if (isset($override['platform']['patch_level']) && ! isset($override['platform']['last_patch_applied'])) {
            $override['platform']['last_patch_applied'] = today()->toDateString();
        }

        return array_replace_recursive($raw, $override);
    }

    /** @return list<array{severity:string,title:string,detail:string}> */
    public function assess(array $a): array
    {
        $f = [];
        $add = function (string $severity, string $title, string $detail) use (&$f) {
            $f[] = compact('severity', 'title', 'detail');
        };
        $platform = $a['platform'] ?? [];

        if ($end = $platform['vendor_support_ends'] ?? null) {
            $days = (int) today()->diffInDays($end, false);
            if ($days < 0) {
                $add('critical', 'Vendor support has ended', "Vendor support ended on {$end}.");
            } elseif ($days <= 180) {
                $add('high', 'Vendor support ends within 180 days', "Vendor support ends on {$end} ({$days} days).");
            }
        }
        if (($last = $platform['last_patch_applied'] ?? null) && now()->parse($last)->diffInDays(today()) > 90) {
            $add('high', 'Platform patch older than 90 days', "Last patch applied {$last}.");
        }
        $eod = $a['eod'] ?? [];
        if (($eod['last_run_status'] ?? 'success') === 'failed') {
            $add('high', 'End-of-day run failed', 'The last end-of-day batch did not complete.');
        }
        if (($eod['failed_jobs_7d'] ?? 0) > 0) {
            $add('moderate', 'End-of-day jobs failing', "{$eod['failed_jobs_7d']} failed job(s) in the last 7 days.");
        }
        $access = $a['access'] ?? [];
        if (($access['dormant_privileged'] ?? 0) > 0) {
            $add('high', 'Dormant privileged accounts', "{$access['dormant_privileged']} privileged account(s) are dormant.");
        }
        if (($access['generic_accounts'] ?? 0) > 0) {
            $add('moderate', 'Generic accounts in use', "{$access['generic_accounts']} shared/generic account(s) on the platform.");
        }
        foreach ($a['interfaces'] ?? [] as $i) {
            if ((float) ($i['tls'] ?? 0) < 1.2) {
                $add('high', "Interface {$i['name']} uses TLS below 1.2", "{$i['name']} ({$i['protocol']}) negotiates TLS {$i['tls']}.");
            }
        }
        if ($cert = $a['certificate_expiry'] ?? null) {
            $days = (int) today()->diffInDays($cert, false);
            if ($days < 0) {
                $add('critical', 'Switch certificate expired', "Certificate expired on {$cert}.");
            } elseif ($days <= 30) {
                $add('high', 'Switch certificate expires within 30 days', "Certificate expires on {$cert} ({$days} days).");
            }
        }
        if (isset($a['message_success_rate']) && $a['message_success_rate'] < 99.5) {
            $add('moderate', 'Message success rate below 99.5%', "Success rate {$a['message_success_rate']}%.");
        }

        return $f;
    }

    /** Differences in tracked artefacts: list of [key, from, to]. */
    private function diff(array $old, array $new): array
    {
        $flat = function (array $a): array {
            $out = [];
            foreach (self::TRACKED as $k) {
                if (! array_key_exists($k, $a)) {
                    continue;
                }
                if ($k === 'platform') {
                    foreach ($a[$k] as $pk => $pv) {
                        $out["platform.{$pk}"] = $pv;
                    }
                } elseif ($k === 'interfaces') {
                    $out[$k] = implode(', ', array_map(fn ($i) => "{$i['name']} (TLS {$i['tls']})", $a[$k]));
                } else {
                    $out[$k] = is_array($a[$k]) ? implode(', ', $a[$k]) : $a[$k];
                }
            }

            return $out;
        };
        $o = $flat($old);
        $changes = [];
        foreach ($flat($new) as $key => $to) {
            if (array_key_exists($key, $o) && $o[$key] !== $to && $key !== 'platform.last_patch_applied') {
                $changes[] = ['key' => $key, 'from' => $o[$key], 'to' => $to];
            }
        }

        return $changes;
    }

    /** An authorising change request listing the asset, starting since the previous capture. */
    private function approvedChange(CoreBankingIntegration $integration, CoreBankingSnapshot $previous): bool
    {
        if (! $integration->asset_id) {
            return false;
        }
        $since = $previous->captured_at->copy()->subDay();

        return $this->drift->changesFor($integration->organization_id, $integration->asset_id)
            ->contains(fn ($c) => ($start = $c->actual_start_at ?? $c->scheduled_start_at) && $start >= $since && $start <= now()->addDay());
    }

    private function raiseIssue(CoreBankingIntegration $integration, array $findings, ?User $actor): void
    {
        $serious = collect($findings)->whereIn('severity', ['critical', 'high']);
        if ($serious->isEmpty()) {
            return;
        }
        $open = $integration->issue_id ? Issue::withoutGlobalScopes()->find($integration->issue_id) : null;
        if ($open && $open->isOpen()) {
            return;
        }
        $issue = $this->issues->open([
            'organization_id' => $integration->organization_id,
            'title' => "Core banking findings: {$integration->name}",
            'description' => $serious->map(fn ($f) => "[{$f['severity']}] {$f['title']} - {$f['detail']}")->implode("\n"),
            'severity' => $serious->contains('severity', 'critical') ? 'critical' : 'high',
            'source_type' => 'core_banking',
            'source_id' => $integration->id,
            'source_reference' => $integration->name,
            'owner_id' => $integration->owner_id,
        ], $actor);
        $integration->update(['issue_id' => $issue->id]);
    }
}
