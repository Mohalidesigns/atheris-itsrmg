<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A frozen, approved (or returned) issue of the Statement of Applicability. */
class SoaVersion extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['submitted', 'approved', 'returned'];

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'summary' => 'array', 'prepared_at' => 'datetime', 'decided_at' => 'datetime'];

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
