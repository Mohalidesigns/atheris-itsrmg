<?php

namespace App\Jobs\Ea;

use App\Services\Ea\SurveyEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * RunSurveyCampaigns — the scheduled half of B2.
 *
 * Ardoq Broadcasts are scheduled one-time or recurring weekly / monthly /
 * quarterly / annually, with automated reminders to non-respondents. This job
 * is the clock behind that: it launches recurring surveys that have come due,
 * fires reminders, and closes campaigns past their window.
 *
 * Order matters — close first so an expired campaign is not reminded, then
 * launch, then remind.
 */
class RunSurveyCampaigns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(SurveyEngine $engine): void
    {
        $closed = $engine->closeExpiredCampaigns();
        $launched = $engine->launchDueRecurring();
        $reminded = $engine->sendDueReminders();

        if ($closed || $launched || $reminded) {
            logger()->info('[EA] Survey campaign tick', compact('closed', 'launched', 'reminded'));
        }
    }
}
