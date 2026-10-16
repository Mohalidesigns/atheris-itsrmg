<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's subscribable .ics feed; bumping `version` invalidates every previously issued URL (plan C55). */
class CalendarFeed extends Model
{
    use FinanceRecord;

    protected $table = 'fin_calendar_feeds';

    protected $guarded = [];

    protected $casts = ['last_used_at' => 'datetime', 'version' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function for(User $user): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['user_id' => $user->id],
            ['organization_id' => $user->organization_id, 'version' => 1],
        );
    }
}
