<?php

namespace App\Services\Change;

use App\Models\Change\ChangeRequest;

/**
 * Forward-schedule conflicts (ATH-CHG-001 §3.5): other approved / scheduled / implementing
 * changes in the same bank whose window overlaps and that touch the same asset, the same heavy
 * category (core banking, network, infrastructure) or the same named service.
 */
class ChangeConflictDetector
{
    private const SHARED_CATEGORIES = ['core_banking', 'network', 'infrastructure'];

    /** @return array<int, array{id:int, change_code:string, title:string, status:string, scheduled_start_at:?string, scheduled_end_at:?string, reason:string}> */
    public function conflicts(ChangeRequest $change): array
    {
        if (! $change->scheduled_start_at) {
            return [];
        }
        $start = $change->scheduled_start_at;
        $end = $change->scheduled_end_at ?? $start->copy()->addHour();
        $assets = array_map('intval', (array) $change->affected_asset_ids);
        $services = array_map(fn ($s) => mb_strtolower(trim((string) $s)), (array) $change->affected_services);

        $others = ChangeRequest::withoutGlobalScopes()->where('organization_id', $change->organization_id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $change->id ?? 0)
            ->whereIn('status', ChangeRequest::ACTIVE_WINDOW_STATUSES)
            ->whereNotNull('scheduled_start_at')
            ->where('scheduled_start_at', '<=', $end)
            ->where(fn ($q) => $q->where('scheduled_end_at', '>=', $start)->orWhere(fn ($w) => $w->whereNull('scheduled_end_at')->where('scheduled_start_at', '>=', $start)))
            ->orderBy('scheduled_start_at')->get();

        $out = [];
        foreach ($others as $other) {
            $reason = null;
            $shared = array_intersect($assets, array_map('intval', (array) $other->affected_asset_ids));
            if ($shared) {
                $reason = 'Shares affected asset'.(count($shared) > 1 ? 's' : '').' #'.implode(', #', $shared).'.';
            } elseif ($change->category === $other->category && in_array($change->category, self::SHARED_CATEGORIES, true)) {
                $reason = 'Same '.str_replace('_', ' ', $change->category).' estate in an overlapping window.';
            } else {
                $otherServices = array_map(fn ($s) => mb_strtolower(trim((string) $s)), (array) $other->affected_services);
                $match = array_values(array_intersect($services, $otherServices));
                if ($match) {
                    $reason = 'Both touch '.$match[0].'.';
                }
            }
            if ($reason) {
                $out[] = [
                    'id' => $other->id,
                    'change_code' => $other->change_code,
                    'title' => $other->title,
                    'status' => $other->status,
                    'scheduled_start_at' => $other->scheduled_start_at?->toIso8601String(),
                    'scheduled_end_at' => $other->scheduled_end_at?->toIso8601String(),
                    'reason' => $reason,
                ];
            }
        }

        return $out;
    }
}
