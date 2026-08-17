<?php

namespace App\Services\Ea;

use App\Models\Ea\TechComponent;
use Illuminate\Support\Facades\Http;

/**
 * EolFeedClient — talks to endoflife.date (MIT-licensed, no API key) to refresh
 * the `eol_date` / `eos_date` of tech components. The mapping uses the
 * component's `code` (or `name`, lowercased) as the product identifier; rows
 * with no public-feed match are left untouched.
 *
 * The client is resilient: if the network is unavailable (e.g. air-gapped
 * test environments) it falls back to a small built-in fixture for the most
 * common Nigerian-bank tech-stack components so the sync never crashes.
 */
class EolFeedClient
{
    public const BASE_URL = 'https://endoflife.date/api';

    private const LOCAL_FIXTURE = [
        'java' => ['eol' => '2031-09-30'],
        'php' => ['eol' => '2027-12-31'],
        'python' => ['eol' => '2028-10-31'],
        'nodejs' => ['eol' => '2027-04-30'],
        'postgresql' => ['eol' => '2030-11-09'],
        'mysql' => ['eol' => '2027-04-30'],
        'oracle' => ['eol' => '2031-04-30'],
        'mongodb' => ['eol' => '2028-07-19'],
        'redis' => ['eol' => '2028-05-01'],
        'rabbitmq' => ['eol' => '2027-12-31'],
        'kafka' => ['eol' => '2027-09-30'],
        'kubernetes' => ['eol' => '2026-12-28'],
        'docker' => ['eol' => '2027-06-30'],
        'windows-server' => ['eol' => '2027-01-12'],
        'ubuntu' => ['eol' => '2029-04-25'],
        'centos' => ['eol' => '2024-06-30'],
        'rhel' => ['eol' => '2032-05-31'],
    ];

    /**
     * Source of the most recent fetch() call: 'live' | 'fixture' | 'none'.
     * ATH-EAR-002 §2.4 / §10 — the fixture fallback must never be presented as
     * live data, so the client tracks and reports which one answered.
     */
    private string $lastSource = 'none';

    public function sync(): array
    {
        $touched = 0; $updated = 0; $live = 0; $fixture = 0;
        foreach (TechComponent::all() as $t) {
            $touched++;
            $key = $this->normalise($t);
            $data = $this->fetch($key);
            if ($this->lastSource === 'live') $live++;
            if ($this->lastSource === 'fixture') $fixture++;
            if ($data && !empty($data['eol'])) {
                $t->eol_date = $data['eol'];
                if (!empty($data['eos'])) $t->eos_date = $data['eos'];
                $t->save();
                $updated++;
            }
        }
        return [
            'touched' => $touched,
            'updated' => $updated,
            'live_hits' => $live,
            'fixture_hits' => $fixture,
            'provenance' => self::provenanceFor($live, $fixture),
            'endpoint' => self::BASE_URL,
        ];
    }

    /**
     * Collapse per-record sources into one label for the Data Sources screen.
     */
    public static function provenanceFor(int $live, int $fixture): string
    {
        if ($live > 0 && $fixture > 0) return 'mixed';
        if ($live > 0) return 'live';
        if ($fixture > 0) return 'fixture';
        return 'failed';
    }

    public function lastSource(): string
    {
        return $this->lastSource;
    }

    public function fetch(string $product): ?array
    {
        try {
            $resp = Http::timeout(5)->get(self::BASE_URL.'/'.urlencode($product).'.json');
            if ($resp->successful()) {
                $rows = $resp->json();
                if (is_array($rows) && !empty($rows)) {
                    $latest = $rows[0];
                    $this->lastSource = 'live';
                    return [
                        'eol' => $latest['eol'] ?? null,
                        'eos' => $latest['support'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // fall through to fixture
        }
        $fixture = self::LOCAL_FIXTURE[$product] ?? null;
        $this->lastSource = $fixture ? 'fixture' : 'none';
        return $fixture;
    }

    private function normalise(TechComponent $t): string
    {
        $key = strtolower(trim($t->code ?: $t->name ?: ''));
        return preg_replace('/[^a-z0-9]+/', '-', $key);
    }
}
