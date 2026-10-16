<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A currency the bank transacts in; exactly one is the base (reporting) currency. */
class Currency extends Model
{
    use FinanceRecord;

    protected $table = 'fin_currencies';

    protected $guarded = [];

    protected $casts = [
        'is_base' => 'boolean',
        'is_active' => 'boolean',
        'decimals' => 'integer',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeBase(Builder $q): Builder
    {
        return $q->where('is_base', true);
    }
}
