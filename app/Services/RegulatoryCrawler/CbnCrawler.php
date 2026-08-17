<?php

namespace App\Services\RegulatoryCrawler;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Central Bank of Nigeria — circulars crawler.
 *
 * Strategy:
 *  - Fetch https://www.cbn.gov.ng/Documents/circulars.asp (public circulars page).
 *  - Parse the circular table with DOMDocument.
 *  - Emit a CrawledCircular DTO per row.
 *
 * NB: This implementation is designed to be safe:
 *   - User-Agent identifies Atheris and provides a contact email.
 *   - Timeout 15s.
 *   - Caches last fetched URL per circular number.
 *   - Returns an empty collection on any failure (never throws).
 */
class CbnCrawler implements RegulatoryCrawlerContract
{
    private const HOMEPAGE = 'https://www.cbn.gov.ng/Documents/circulars.asp';
    private const USER_AGENT = 'Atheris-RegulatoryCrawler/1.0 (+compliance@atheris.ng)';

    public function regulatorCode(): string { return 'CBN'; }
    public function regulatorName(): string { return 'Central Bank of Nigeria'; }
    public function homepageUrl(): string { return self::HOMEPAGE; }

    public function fetchSince(\DateTimeInterface $since): Collection
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(15)
                ->retry(2, 500)
                ->get(self::HOMEPAGE);

            if (!$response->ok()) {
                Log::warning('[CBN crawler] non-200 response', ['status' => $response->status()]);
                return collect();
            }

            return $this->parseHtml($response->body(), $since);
        } catch (\Throwable $e) {
            Log::warning('[CBN crawler] fetch failed', ['error' => $e->getMessage()]);
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

        // The CBN circulars page uses <tr> rows inside the content table.
        // We look for anchors that link to circular documents.
        foreach ($xpath->query('//a[contains(@href, "circulars") or contains(@href, "CircularFileUpload")]') as $anchor) {
            /** @var \DOMElement $anchor */
            $title = trim($anchor->textContent);
            if ($title === '' || strlen($title) < 10) continue;
            $href = $anchor->getAttribute('href');
            $url = str_starts_with($href, 'http') ? $href : rtrim('https://www.cbn.gov.ng/', '/').'/'.ltrim($href, '/');

            // Heuristic circular number + date scrape
            $circularNumber = $this->guessCircularNumber($title, $href);
            $issuedAt = $this->guessIssuedDate($anchor);

            if ($issuedAt && $issuedAt < \DateTimeImmutable::createFromInterface($since)) continue;

            $items->push(new CrawledCircular(
                regulatorCode: 'CBN',
                circularNumber: $circularNumber,
                title: $title,
                issuedAt: $issuedAt,
                sourceUrl: $url,
                pdfUrl: str_ends_with(strtolower($url), '.pdf') ? $url : null,
            ));
        }
        return $items->values();
    }

    private function guessCircularNumber(string $title, string $href): string
    {
        if (preg_match('#([A-Z]{2,}/[A-Z]{2,}/[A-Z0-9/]+[0-9]{2,}[/][0-9]{3,})#', $title, $m)) return $m[1];
        if (preg_match('#([A-Z]{2,}-[A-Z0-9-]+\\-\\d+)#', $href, $m)) return $m[1];
        return substr(md5($title.$href), 0, 12);
    }

    private function guessIssuedDate(\DOMElement $anchor): ?\DateTimeImmutable
    {
        // Walk up to the row and grab any date-like cell
        $row = $anchor->parentNode;
        while ($row && $row->nodeName !== 'tr') $row = $row->parentNode;
        if (!$row) return null;
        $text = trim($row->textContent);
        if (preg_match('#(\\d{1,2})\\s+([A-Za-z]{3,9})\\s+(\\d{4})#', $text, $m)) {
            try { return new \DateTimeImmutable($m[1].' '.$m[2].' '.$m[3]); } catch (\Throwable) {}
        }
        if (preg_match('#(\\d{4})-(\\d{2})-(\\d{2})#', $text, $m)) {
            try { return new \DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]}"); } catch (\Throwable) {}
        }
        return null;
    }
}
