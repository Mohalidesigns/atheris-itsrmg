<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * FeedRun — one execution of an inbound EA data feed.
 *
 * ATH-EAR-002 §10 makes this a release gate: "Every feed-sourced field
 * displays source and fetch timestamp. Fixture fallbacks must be visibly
 * labelled." R7 rates an unlabelled fixture presented as live data as a
 * high-impact credibility risk in an evaluation.
 */
class FeedRun extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_feed_runs';

    protected $guarded = [];

    protected $casts = ['ran_at' => 'datetime'];

    public const FEEDS = ['assets', 'eol', 'cve'];

    /** Human label for the provenance state, used directly in the UI. */
    public function provenanceLabel(): string
    {
        return match ($this->provenance) {
            'live' => 'Live feed',
            'fixture' => 'Bundled fixture',
            'mixed' => 'Partially live',
            default => 'Failed',
        };
    }

    /** Most recent run per feed, keyed by feed name. */
    public static function latestByFeed(): Collection
    {
        return collect(self::FEEDS)->mapWithKeys(fn ($feed) => [
            $feed => self::where('feed', $feed)->orderByDesc('ran_at')->first(),
        ]);
    }

    /** Most recent run that actually reached the live upstream. */
    public static function lastSuccessful(string $feed): ?self
    {
        return self::where('feed', $feed)
            ->whereIn('provenance', ['live', 'mixed'])
            ->orderByDesc('ran_at')
            ->first();
    }
}
