<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A cost centre in the bank's hierarchy, with an accountable owner (ATH-FIN-001 FIN-01.02). */
class CostCentre extends Model
{
    use FinanceRecord, SoftDeletes;

    protected $table = 'fin_cost_centres';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    /** Ids of every descendant (children, grandchildren, ...) within the organisation. */
    public function descendantIds(): array
    {
        $byParent = static::query()->whereNotNull('parent_id')->get(['id', 'parent_id'])->groupBy('parent_id');

        $found = [];
        $queue = [$this->id];
        while ($queue) {
            $next = array_shift($queue);
            foreach ($byParent->get($next, collect()) as $child) {
                if (! in_array($child->id, $found, true)) {
                    $found[] = $child->id;
                    $queue[] = $child->id;
                }
            }
        }

        return $found;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }
}
