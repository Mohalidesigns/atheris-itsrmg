<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A CAB / ECAB sitting with an agenda of changes and minutes. */
class ChangeCabMeeting extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['scheduled_at' => 'datetime'];

    public const STATUSES = ['scheduled', 'held', 'cancelled'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(ChangeCabBoard::class, 'board_id');
    }

    public function chairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chaired_by_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChangeCabMeetingItem::class, 'meeting_id')->orderBy('sequence')->orderBy('id');
    }
}
