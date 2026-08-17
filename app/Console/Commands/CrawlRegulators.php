<?php

namespace App\Console\Commands;

use App\Services\RegulatoryCrawler\RegulatoryCrawlerManager;
use Illuminate\Console\Command;

/**
 * php artisan atheris:crawl-regulators --since=7
 *
 * Runs every registered regulator crawler and ingests new circulars.
 */
class CrawlRegulators extends Command
{
    protected $signature = 'atheris:crawl-regulators {--since=7 : Fetch circulars since N days ago}';
    protected $description = 'Fetch circulars from every registered regulator crawler (CBN, NDPC, …)';

    public function handle(RegulatoryCrawlerManager $manager): int
    {
        $days = (int) $this->option('since');
        $since = new \DateTimeImmutable("-{$days} days");

        $this->info("Crawling regulators since {$since->format('Y-m-d')}…");
        $summary = $manager->runAll($since);

        $rows = [];
        foreach ($summary as $code => $r) {
            $rows[] = [$code, $r['fetched'], $r['new'], $r['error'] ? 'ERR: '.substr($r['error'], 0, 40) : 'ok'];
        }
        $this->table(['Regulator', 'Fetched', 'New', 'Status'], $rows);

        return self::SUCCESS;
    }
}
