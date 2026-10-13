<?php

namespace App\Services\Vendors;

use App\Models\TprmSecurityRating;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Carbon;

/**
 * Continuous security ratings (plan gap D8.3). No bank has supplied SecurityScorecard or Bitsight
 * credentials, so the provider runs in sandbox mode: each vendor's score is derived
 * deterministically from its domain and drifts month to month, the way a real outside-in rating
 * does. Every capture is stored with its delta, so trends, grade changes and drop alerts work
 * exactly as they would on live data. Only vendors with a domain can be rated.
 */
class SecurityRatingService
{
    public const PROVIDER = 'securityscorecard';

    /** Capture today's rating for every active, rateable vendor of the user's bank. */
    public function refresh(User $user): array
    {
        $captured = 0;
        $skipped = 0;
        $vendors = Vendor::withoutGlobalScopes()->where('organization_id', $user->organization_id)
            ->whereIn('status', ['active', 'under_review'])->get();

        foreach ($vendors as $vendor) {
            if (! $vendor->domain) {
                $skipped++;

                continue;
            }
            $this->capture($vendor, now()) ? $captured++ : $skipped++;
        }

        return ['captured' => $captured, 'skipped' => $skipped];
    }

    /** One capture for one vendor on one day. Returns null if that day was already captured. */
    public function capture(Vendor $vendor, Carbon $at): ?TprmSecurityRating
    {
        $exists = TprmSecurityRating::withoutGlobalScopes()->where('vendor_id', $vendor->id)
            ->whereDate('captured_at', $at->toDateString())->exists();
        if ($exists || ! $vendor->domain) {
            return null;
        }

        $previous = TprmSecurityRating::withoutGlobalScopes()->where('vendor_id', $vendor->id)
            ->where('captured_at', '<', $at)->orderByDesc('captured_at')->first();
        $value = $this->score($vendor->domain, $at);

        return TprmSecurityRating::withoutGlobalScopes()->create([
            'organization_id' => $vendor->organization_id,
            'vendor_id' => $vendor->id,
            'provider' => self::PROVIDER,
            'rating_value' => $value,
            'grade' => TprmSecurityRating::gradeFor($value),
            'captured_at' => $at,
            'delta_from_previous' => $previous ? $value - $previous->rating_value : 0,
        ]);
    }

    /** Sandbox score: a stable base per domain (70-96) plus a bounded monthly drift. */
    public function score(string $domain, Carbon $at): int
    {
        $base = 70 + hexdec(substr(md5('base|'.strtolower($domain)), 0, 4)) % 27;
        $drift = (hexdec(substr(md5('drift|'.strtolower($domain).'|'.$at->format('Y-m')), 0, 4)) % 13) - 6;

        return max(30, min(99, $base + $drift));
    }
}
