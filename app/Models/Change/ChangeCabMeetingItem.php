<?php

namespace App\Models\Change;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An agenda line: one change on one meeting, with the decision minuted. */
class ChangeCabMeetingItem extends Model
{
    protected $guarded = [];

    protected $casts = ['sequence' => 'integer'];

    public const DECISIONS = ['pending', 'approved', 'rejected', 'deferred', 'more_info'];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ChangeCabMeeting::class, 'meeting_id');
    }

    public function change(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }
}
