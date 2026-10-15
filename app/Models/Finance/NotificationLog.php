<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Idempotency log of scheduled finance notifications (ATH-FIN-001 §4.7). */
class NotificationLog extends Model
{
    use FinanceRecord;

    protected $table = 'fin_notification_logs';

    protected $guarded = [];

    protected $casts = ['sent_at' => 'datetime'];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_to_user_id');
    }
}
