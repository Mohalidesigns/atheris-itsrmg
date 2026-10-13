<?php

namespace App\Models\Change;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A seat on a CAB / ECAB; reached only through its (tenant-scoped) board. */
class ChangeCabMember extends Model
{
    protected $guarded = [];

    protected $casts = ['is_voting' => 'boolean'];

    public const ROLES = ['chair', 'member', 'advisor'];

    public const REPRESENTS = ['IT Operations', 'Information Security', 'Business', 'Risk', 'Compliance', 'Internal Audit', 'Applications', 'Infrastructure', 'Service Desk'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(ChangeCabBoard::class, 'board_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
