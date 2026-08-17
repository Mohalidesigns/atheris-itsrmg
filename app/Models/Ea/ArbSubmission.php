<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class ArbSubmission extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_arb_submissions';

    protected $guarded = [];

    protected $casts = [
        'impact_blast_radius' => 'array',
        'impacted_principles' => 'array',
        'impacted_standards' => 'array',
        'voters' => 'array',
        'votes' => 'array',
        'decided_at' => 'datetime',
        'meeting_date' => 'date',
    ];
}
