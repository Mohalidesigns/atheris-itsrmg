<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Change Advisory Board (cab) or Emergency CAB (ecab) — ATH-CHG-001 §2.4. */
class ChangeCabBoard extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['quorum' => 'integer', 'is_active' => 'boolean'];

    public const TYPES = ['cab', 'ecab'];

    public function chair(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chair_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChangeCabMember::class, 'board_id')->orderByRaw("case role when 'chair' then 0 when 'member' then 1 else 2 end")->orderBy('id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(ChangeCabMeeting::class, 'board_id');
    }

    /** Ids of members whose vote counts towards quorum. */
    public function votingMemberIds(): array
    {
        return $this->members()->where('is_voting', true)->pluck('user_id')->all();
    }

    public function hasMember(int $userId): bool
    {
        return $this->members()->where('user_id', $userId)->exists();
    }
}
