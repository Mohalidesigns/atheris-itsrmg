<?php

namespace App\Services\RegulatoryCrawler;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Nigeria Data Protection Commission — circulars crawler.
 *
 * Strategy:
 *  - Fetch https://ndpc.gov.ng/ (public notices page).
 *  - Parse the decisions/notices list with DOMDocument.
 *  - Emit a CrawledCircular DTO per entry.
 */
class NdpcCrawler implements RegulatoryCrawlerContract
{
    private const HOMEPAGE = 'https://ndpc.gov.ng/';
    private const USER_AGENT = 'Atheris-RegulatoryCrawler/1.0 (+compliance@atheris.ng)';

    public function regulatorCode(): string { return 'NDPC'; }
    public function regulatorName(): string { return 'Nigeria Data Protection Commission'; }
    public function homepageUrl(): string { return self::HOMEPAGE; }

    public function fetchSince(\DateTimeInterface $since): Collection
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(15)
                ->retry(2, 500)
                ->get(self::HOMEPAGE);

            if (!$response->ok()) {
                Log::warning('[NDPC crawler] non-200 response', ['status' => $response->status()]);
                return collect();
            }

            return $this->parseHtml($response->body(), $since);
        } catch (\Throwable $e) {
            Log::warning('[NDPC crawler] fetch failed', ['error' => $e->getMessage()]);
            return collect();
        }
    }

    private function parseHtml(string $html, \DateTimeInterface $since): Collection
    {
        $items = collect();
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        // NDPC posts notices under article headings on the homepage.
        foreach ($xpath->query('//article//h2/a | //h2/a[contains(., "NDPC") or contains(., "NDPA")]') as $anchor) {
            /** @var \DOMElement $anchor */
            $title = trim($anchor->textContent);
            if ($title === '') continue;
            $url = $anchor->getAttribute('href');
            if (!str_starts_with($url, 'http')) $url = rtrim(self::HOMEPAGE, '/').'/'.ltrim($url, '/');

            $items->push(new CrawledCircular(
                regulatorCode: 'NDPC',
                circularNumber: 'NDPC-'.substr(md5($url), 0, 8),
                title: $title,
                issuedAt: null,
                sourceUrl: $url,
            ));
        }

        return $items->values();
    }
}
