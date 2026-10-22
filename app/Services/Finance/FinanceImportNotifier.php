<?php

namespace App\Services\Finance;

use App\Models\Finance\ImportBatch;
use App\Models\User;
use App\Notifications\Finance\ImportCompletedNotification;

/** Tells the importer a cloud import finished (counts only, no amounts). Transaction imports use the ImportBatchCompleted event instead. */
class FinanceImportNotifier
{
    public function completed(ImportBatch $b): void
    {
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
