<?php

namespace App\Events\Finance;

use Illuminate\Foundation\Events\Dispatchable;

/** A variance note was created in breach, or escalated to a higher peak severity (plan P4-4). WP-C's listener sends once per (note, severity). */
class VarianceBreached
{
    use Dispatchable;

    public function __construct(public int $noteId, public string $severity) {}
}
