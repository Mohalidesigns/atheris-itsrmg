<?php

namespace App\Services\RegulatoryCrawler;

use App\Models\RegulatoryCircular;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class RegulatoryCrawlerManager
{
    /** @var array<string, RegulatoryCrawlerContract> */
    private array $registered = [];

    public function __construct()
    {
        $this->register(new CbnCrawler());
        $this->register(new NdpcCrawler());
    }

    public function register(RegulatoryCrawlerContract $crawler): self
    {
        $this->registered[$crawler->regulatorCode()] = $crawler;
        return $this;
    }

    public function available(): Collection
    {
        return collect($this->registered);
    }

    /**
     * Run every registered crawler and persist new circulars.
     *
     * Returns a summary array: ['cbn' => ['fetched' => 3, 'new' => 2, 'error' => null], ...]
     */
    public function runAll(\DateTimeInterface $since = null): array
    {
        $since ??= new \DateTimeImmutable('-7 days');
        $summary = [];
        foreach ($this->registered as $code => $crawler) {
            $summary[$code] = $this->runOne($crawler, $since);
        }
        return $summary;
    }

    public function runOne(RegulatoryCrawlerContract $crawler, \DateTimeInterface $since): array
    {
        try {
            $items = $crawler->fetchSince($since);
            $new = 0;
            foreach ($items as $item) {
                /** @var CrawledCircular $item */
                $exists = RegulatoryCircular::where('regulator_code', $item->regulatorCode)
                    ->where('circular_number', $item->circularNumber)
                    ->exists();
                if ($exists) continue;
                RegulatoryCircular::create([
                    ...$item->toArray(),
                    'ingested_at' => now(),
                    'status' => 'draft',
                ]);
                $new++;
            }
            return ['fetched' => $items->count(), 'new' => $new, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('[RegulatoryCrawlerManager] '.$crawler->regulatorCode().' failed', ['error' => $e->getMessage()]);
            return ['fetched' => 0, 'new' => 0, 'error' => $e->getMessage()];
        }
    }
}
