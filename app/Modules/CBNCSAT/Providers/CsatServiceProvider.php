<?php

namespace App\Modules\CBNCSAT\Providers;

use App\Modules\CBNCSAT\Services\InherentRiskScoringService;
use App\Modules\CBNCSAT\Services\MaturityScoringService;
use Illuminate\Support\ServiceProvider;

class CsatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InherentRiskScoringService::class);
        $this->app->singleton(MaturityScoringService::class);
    }

    public function boot(): void
    {
        //
    }
}
