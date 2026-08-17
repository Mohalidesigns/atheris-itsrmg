<?php

namespace App\Jobs\Ea;

use App\Services\Ea\AssetApplicationClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAssetCatalogue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AssetApplicationClient $client): void
    {
        $client->syncIntoEa();
    }
}
