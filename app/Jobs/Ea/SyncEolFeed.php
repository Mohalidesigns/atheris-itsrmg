<?php

namespace App\Jobs\Ea;

use App\Services\Ea\EolFeedClient;
use App\Services\Ea\TechObsolescenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncEolFeed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(EolFeedClient $client, TechObsolescenceService $obsolescence): void
    {
        $client->sync();
        $obsolescence->recompute();
    }
}
