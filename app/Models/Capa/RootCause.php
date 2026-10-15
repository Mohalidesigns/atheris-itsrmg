<?php

namespace App\Models\Capa;

use App\Models\Issue;
use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CAPA stage 3: the root cause analysis, one per issue. five_whys is an ordered list of answers;
 * fishbone groups causes under people / process / technology / governance.
 */
class RootCause extends Model
{
    use BelongsToOrganization;

    public const METHODS = ['five_whys', 'fishbone', 'both'];

    public const FISHBONE_CATEGORIES = ['people', 'process', 'technology', 'governance'];

    /** A 5-whys chain must drill down at least this far to count as an analysis. */
    public const MIN_WHYS = 3;

    protected $table = 'capa_root_causes';

    protected $guarded = [];

    protected $casts = ['five_whys' => 'array', 'fishbone' => 'array'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function whyCount(): int
    {
        return collect($this->five_whys ?? [])->filter(fn ($w) => filled(is_array($w) ? ($w['answer'] ?? null) : $w))->count();
    }

    public function fishboneCauseCount(): int
    {
        return collect(self::FISHBONE_CATEGORIES)
            ->sum(fn ($c) => collect($this->fishbone[$c] ?? [])->filter(fn ($v) => filled($v))->count());
    }

    /** Complete = a 5-whys chain of MIN_WHYS levels or at least one fishbone cause, plus a summary. */
    public function isComplete(): bool
    {
        return filled($this->root_cause_summary) && ($this->whyCount() >= self::MIN_WHYS || $this->fishboneCauseCount() >= 1);
    }
}
