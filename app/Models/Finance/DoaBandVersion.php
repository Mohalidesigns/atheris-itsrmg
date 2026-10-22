<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A version of the bank's delegation-of-authority bands with its maker-checker trail. */
class DoaBandVersion extends Model
{
    use FinanceRecord;

    public const STATUSES = ['pending', 'scheduled', 'active', 'superseded', 'rejected', 'withdrawn'];

    protected $table = 'fin_doa_band_versions';

    protected $guarded = [];

    protected $casts = [
        'bands' => 'array', 'effective_from' => 'date', 'proposed_at' => 'datetime', 'decided_at' => 'datetime',
        'activated_at' => 'datetime', 'superseded_at' => 'datetime',
    ];

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** @return array<int, string> */
    public function getKeysAttribute(): array
    {
        return array_column($this->bands ?? [], 'key');
    }
}
