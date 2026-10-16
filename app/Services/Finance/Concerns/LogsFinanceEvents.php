<?php

namespace App\Services\Finance\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Shared helpers of the finance services: a named audit event on the `it-finance` log, and refusals. */
trait LogsFinanceEvents
{
    protected function audit(Model $subject, ?User $by, string $event, string $description, array $properties = []): void
    {
        $logger = activity('it-finance')->performedOn($subject)->event($event)->withProperties($properties);
        if ($by) {
            $logger->causedBy($by);
        }
        $logger->log($description);
    }

    protected function fail(string $message, string $key = 'workflow'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
