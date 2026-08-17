<?php

namespace App\Services\Ea;

use App\Events\Ea\CveMatchedToComponent;
use App\Models\Ea\TechComponent;
use App\Models\Ea\TechVulnerability;
use Illuminate\Support\Facades\Http;

/**
 * CveFeedClient — queries the NVD 2.0 API for the given CPE and persists
 * matching CVEs into `ea_tech_vulnerabilities`.
 *
 * The client deliberately falls back to a small bundled fixture when the
 * outbound request fails (test environments, air-gapped customers), so a
 * partial CVE picture is always available for the dashboard.
 */
class CveFeedClient
{
    public const ENDPOINT = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    private const FIXTURE = [
        // (cpe-prefix, cve_id, severity, cvss, summary)
        'log4j' => ['CVE-2021-44228', 'critical', 10.0, 'Log4Shell — JNDI injection allows remote code execution.'],
        'openssl' => ['CVE-2022-0778', 'high', 7.5, 'Infinite loop in BN_mod_sqrt() reachable via crafted certificates.'],
        'spring' => ['CVE-2022-22965', 'critical', 9.8, 'Spring4Shell — RCE via data binding in spring-beans.'],
        'kubernetes' => ['CVE-2024-21626', 'critical', 8.6, 'runc container escape via /proc fd reuse.'],
        'oracle' => ['CVE-2023-22045', 'high', 7.5, 'Oracle JDK serialization vulnerability — DoS / data tampering.'],
        'mysql' => ['CVE-2024-20984', 'high', 7.5, 'MySQL server DoS via Optimizer.'],
        'postgresql' => ['CVE-2024-7348', 'high', 8.8, 'pg_dump TOCTOU privilege escalation.'],
    ];

    /**
     * Source of the most recent fetch() call: 'live' | 'fixture' | 'none'.
     * ATH-EAR-002 §2.4: an evaluator pressing "Sync CVE" on an air-gapped box
     * received seven fixture CVEs presented as live data. Provenance is now
     * tracked per call and surfaced on the Data Sources screen.
     */
    private string $lastSource = 'none';

    public function sync(): array
    {
        $touched = 0; $created = 0; $live = 0; $fixture = 0;
        foreach (TechComponent::whereNotNull('cpe')->get() as $t) {
            $touched++;
            $rows = $this->fetch($t->cpe ?: $t->name);
            if ($this->lastSource === 'live') $live++;
            if ($this->lastSource === 'fixture') $fixture++;
            foreach ($rows as $r) {
                $existing = TechVulnerability::where('component_id', $t->id)->where('cve_id', $r['cve_id'])->first();
                if (!$existing) {
                    $createdVulnerability = TechVulnerability::create([
                        'component_id' => $t->id,
                        'cve_id' => $r['cve_id'],
                        'cpe' => $r['cpe'] ?? $t->cpe,
                        'severity' => $r['severity'] ?? 'medium',
                        'cvss' => $r['cvss'] ?? null,
                        'summary' => $r['summary'] ?? null,
                        'source' => 'nvd',
                        'published_at' => $r['published_at'] ?? now()->toDateString(),
                        'status' => 'open',
                    ]);
                    $created++;

                    // Contract I-2 (§7.3) — the vulnerability register and the
                    // quarterly RBCF App. II §1.2 assessment need this finding.
                    CveMatchedToComponent::dispatch($t, $createdVulnerability);
                }
            }
        }
        return [
            'touched' => $touched,
            'created' => $created,
            'live_hits' => $live,
            'fixture_hits' => $fixture,
            'provenance' => EolFeedClient::provenanceFor($live, $fixture),
            'endpoint' => self::ENDPOINT,
        ];
    }

    public function lastSource(): string
    {
        return $this->lastSource;
    }

    public function fetch(string $cpe): array
    {
        try {
            $resp = Http::timeout(5)->get(self::ENDPOINT, ['cpeName' => $cpe, 'resultsPerPage' => 5]);
            if ($resp->successful()) {
                $data = $resp->json();
                $rows = [];
                foreach (($data['vulnerabilities'] ?? []) as $entry) {
                    $cve = $entry['cve'] ?? [];
                    $metrics = $cve['metrics']['cvssMetricV31'][0]['cvssData'] ?? null;
                    $rows[] = [
                        'cve_id' => $cve['id'] ?? null,
                        'cvss' => $metrics['baseScore'] ?? null,
                        'severity' => strtolower($metrics['baseSeverity'] ?? 'medium'),
                        'summary' => collect($cve['descriptions'] ?? [])->firstWhere('lang', 'en')['value'] ?? '',
                        'published_at' => $cve['published'] ?? null,
                    ];
                }
                if (!empty($rows)) {
                    $this->lastSource = 'live';
                    return $rows;
                }
            }
        } catch (\Throwable $e) {
            // fall back to fixture
        }
        $lower = strtolower($cpe);
        foreach (self::FIXTURE as $prefix => $fix) {
            if (str_contains($lower, $prefix)) {
                [$id, $sev, $cvss, $summary] = $fix;
                $this->lastSource = 'fixture';
                return [[
                    'cve_id' => $id,
                    'cvss' => $cvss,
                    'severity' => $sev,
                    'summary' => $summary,
                    'published_at' => null,
                ]];
            }
        }
        $this->lastSource = 'none';
        return [];
    }
}
