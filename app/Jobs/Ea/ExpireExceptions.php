<?php

namespace App\Jobs\Ea;

use App\Models\Ea\Exception as EaException;
use App\Services\Ea\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExpireExceptions — daily job that flips active exceptions to "expired" once
 * their expires_at has passed. Reminder windows (30 / 60 / 90 days) are
 * surfaced via the EA Exceptions screen rather than via outbound emails so the
 * notifications backbone is not a hard dependency.
 */
class ExpireExceptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): array
    {
        $expired = 0;
        EaException::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get()
            ->each(function ($e) use (&$expired) {
                $e->update(['status' => 'expired']);
                AuditLogger::logBare('exception.expired', \App\Models\Ea\Exception::class, $e->id, ['expires_at' => $e->expires_at]);
                $expired++;
            });
        return compact('expired');
    }
}
