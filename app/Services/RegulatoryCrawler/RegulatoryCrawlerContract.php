<?php

namespace App\Services\RegulatoryCrawler;

use Illuminate\Support\Collection;

/**
 * Regulatory crawler contract.
 *
 * Implementations must produce a normalised collection of CrawledCircular
 * instances. Production implementations must respect robots.txt, rate-limit,
 * and cache ETag / Last-Modified where supported.
 *
 * All crawlers must be safe to run hourly.
 */
interface RegulatoryCrawlerContract
{
    /** Short code e.g. CBN, NDPC, SEC, NCC, NAICOM, PENCOM, NDIC, BoG, CBK */
    public function regulatorCode(): string;

    /** Human-readable name. */
    public function regulatorName(): string;

    /** Homepage / base URL (read-only). */
    public function homepageUrl(): string;

    /**
     * Fetch circulars published since the given date. Returns a collection
     * of CrawledCircular DTOs. Implementations should paginate internally.
     */
    public function fetchSince(\DateTimeInterface $since): Collection;
}
