<?php

namespace App\Models\Finance\Concerns;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Shared behaviour of every IT Financial Management record (ATH-FIN-001 §4.7): strict tenancy,
 * a created_by stamp and an activity-log trail under the `it-finance` log name.
 */
trait FinanceRecord
{
    use BelongsToOrganization, LogsActivity;

    public static function bootFinanceRecord(): void
    {
        static::creating(function ($model) {
            if (empty($model->created_by_id) && auth()->check()) {
                $model->created_by_id = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(['created_at', 'updated_at', 'deleted_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('it-finance');
    }
}
