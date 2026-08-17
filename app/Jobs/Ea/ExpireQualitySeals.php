<?php

namespace App\Jobs\Ea;

use App\Models\Ea\QualitySeal;
use App\Notifications\Ea\QualitySealExpiringNotification;
use App\Services\Ea\OwnershipService;
use App\Services\Ea\QualitySealService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExpireQualitySeals — the scheduled half of B3.
 *
 * Runs daily. Two responsibilities:
 *   1. Warn owners at 14 and 7 days before expiry.
 *   2. Break seals whose renewal interval has elapsed, "regardless of whether
 *      anything changed" (§3.4). This is what stops a repository going quietly
 *      stale — §2.4: "in a pilot the repository will be populated once by
 *      consultants and will be stale within a quarter."
 */
class ExpireQualitySeals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Days before expiry at which owners are warned. */
    private const WARN_AT = [14, 7];

    public function handle(QualitySealService $seals, OwnershipService $ownership): void
    {
        $this->warnUpcoming($ownership);

        $expired = $seals->expireDueSeals();

        if ($expired->isNotEmpty()) {
            logger()->info('[EA] Quality seals expired on schedule', ['count' => $expired->count()]);
        }
    }

    private function warnUpcoming(OwnershipService $ownership): void
    {
        foreach (self::WARN_AT as $days) {
            $due = QualitySeal::where('state', QualitySeal::APPROVED)
                ->whereNotNull('expires_at')
                ->whereBetween('expires_at', [
                    now()->addDays($days)->startOfDay(),
                    now()->addDays($days)->endOfDay(),
                ])
                ->get();

            foreach ($due as $seal) {
                $recipients = $ownership->recipientsFor(
                    $seal->entity_type,
                    $seal->entity_id,
                    \App\Models\Ea\Subscription::APPROVER_ROLES,
                );

                foreach ($recipients as $subscription) {
                    try {
                        $subscription->user->notify(new QualitySealExpiringNotification($seal, $days));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        }
    }
}
