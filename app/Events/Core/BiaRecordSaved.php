<?php

namespace App\Events\Core;

use App\Models\BiaRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * §7.5: Core\Events\BiaRecordSaved → Ea\Listeners\UpdateProcessCriticality
 *
 * Contract I-6. §7.3 records the gap: "EA process RTO/RPO columns are seeded,
 * not read from `bia_records`" — so the blast radius computes recovery exposure
 * from numbers nobody agreed to.
 */
class BiaRecordSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(public BiaRecord $record)
    {
    }
}
