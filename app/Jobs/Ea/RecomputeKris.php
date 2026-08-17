<?php

namespace App\Jobs\Ea;

use App\Services\Ea\KriCalculator;
use App\Services\Ea\KriPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeKris implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(KriCalculator $svc, KriPublisher $publisher): void
    {
        $svc->compute();

        // Contract I-8 (§7.3) — publish into the core Kri/KriReading register
        // so EA metrics reach board packs and executive dashboards instead of
        // living in a parallel model nobody presents from.
        $published = $publisher->publish();

        if ($published['readings'] > 0) {
            logger()->info('[EA] KRIs published to the platform register', $published);
        }
    }
}
