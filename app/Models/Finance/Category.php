<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** TBM taxonomy node: a cost pool, an IT tower or a sub-category (ATH-FIN-001 FIN-01.03). */
class Category extends Model
{
    use FinanceRecord, SoftDeletes;

    public const KINDS = ['cost_pool', 'tower', 'sub_category'];

    protected $table = 'fin_categories';

    protected $guarded = [];

    protected $casts = [
        'default_capex' => 'boolean',
        'is_system' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function defaultGlAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'default_gl_account_id');
    }

    public function scopeCostPools(Builder $q): Builder
    {
        return $q->where('kind', 'cost_pool');
    }

    public function scopeTowers(Builder $q): Builder
    {
        return $q->where('kind', 'tower');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
