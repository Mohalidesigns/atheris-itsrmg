<?php

namespace App\Events\Finance;

use App\Models\Finance\ImportBatch;
use Illuminate\Foundation\Events\Dispatchable;

/** A GL, expense or invoice CSV import finished (FIN-03.15). */
class ImportBatchCompleted
{
    use Dispatchable;

    public function __construct(public ImportBatch $batch) {}
}
