<?php

namespace App\Services\Change;

use App\Models\Change\ChangeFreezeWindow;
use App\Models\Change\ChangeRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Freeze windows (ATH-CHG-001 §3.4): the first active window overlapping the change's
 * implementation window whose scope covers its category. Emergency changes pass when the window
 * allows them; everything else needs a freeze_override_reason and then always ends with the
 * Change Manager.
 */
class ChangeFreezeChecker
{
    /**
     * @param  ChangeRequest|array{scheduled_start_at:mixed, scheduled_end_at?:mixed, category?:string|null}  $window
     */
    public function blocking(ChangeRequest|array $window, int $orgId): ?ChangeFreezeWindow
    {
        [$start, $end, $category] = $window instanceof ChangeRequest
            ? [$window->scheduled_start_at, $window->scheduled_end_at, $window->category]
            : [$window['scheduled_start_at'] ?? null, $window['scheduled_end_at'] ?? null, $window['category'] ?? null];

        if (! $start) {
            return null;
        }
        $start = $start instanceof CarbonInterface ? $start : Carbon::parse($start);
        $end = $end ? ($end instanceof CarbonInterface ? $end : Carbon::parse($end)) : $start->copy()->addHour();

        return ChangeFreezeWindow::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('starts_at', '<=', $end)->where('ends_at', '>=', $start)
            ->orderBy('starts_at')->get()
            ->first(fn (ChangeFreezeWindow $w) => $w->covers($category));
    }

    /** True when this change may be scheduled inside $window without an override. */
    public function passes(ChangeRequest $change, ChangeFreezeWindow $window): bool
    {
        return $change->change_type === 'emergency' && $window->allow_emergency;
    }
}
