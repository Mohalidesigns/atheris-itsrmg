<?php

namespace App\Listeners\Finance;

use App\Events\Finance\ImportBatchCompleted;
use App\Models\User;
use App\Notifications\Finance\ImportCompletedNotification;

/** Auto-discovered: tells the importer their batch finished (FIN-03.15). */
class SendImportCompletedNotification
{
    public function handle(ImportBatchCompleted $e): void
    {
        $b = $e->batch;
        $user = $b->imported_by_id ? User::withoutGlobalScopes()->where('organization_id', $b->organization_id)->find($b->imported_by_id) : null;
        if (! $user) {
            return;
        }
        $user->notify(new ImportCompletedNotification(
            (string) $b->type, (string) ($b->file_name ?? 'file'),
            (int) $b->accepted_count, (int) $b->rejected_count, (int) $b->unmatched_count,
            route('finance.imports.show', $b->id)));
    }
}
