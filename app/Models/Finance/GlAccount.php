<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** General-ledger account the finance records map to (ATH-FIN-001 FIN-01.04). */
class GlAccount extends Model
{
    use FinanceRecord, SoftDeletes;

    public const TYPES = ['capex', 'opex', 'balance_sheet'];

    protected $table = 'fin_gl_accounts';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'default_gl_account_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
