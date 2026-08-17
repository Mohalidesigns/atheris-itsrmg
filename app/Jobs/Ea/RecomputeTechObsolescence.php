<?php

namespace App\Jobs\Ea;

use App\Services\Ea\TechObsolescenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecomputeTechObsolescence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(TechObsolescenceService $svc): void
    {
        $svc->recompute();
    }
}
