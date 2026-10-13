<?php

namespace App\Services\Vendors;

use App\Models\Vendor;
use App\Models\VendorAssessment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Vendor tiering and review cadence (plan gap D8, "tiering").
 *
 * The tier is the bank's inherent exposure to the vendor, worked out from facts on the record, not
 * typed in: CBN material outsourcing, how much of the bank's data the vendor can reach, whether it
 * processes personal data (NDPA), the criticality of the EA applications it supplies, the bank's
 * own risk rating and how hard the vendor is to replace. The tier sets how often the vendor must
 * be reassessed (config tprm.review_months).
 */
class VendorTiering
{
    /** @return array{points:int, tier:int, reasons:array<int,string>} */
    public function evaluate(Vendor $vendor): array
    {
        $reasons = [];
        $points = 0;
        $add = function (int $n, string $why) use (&$points, &$reasons) {
            $points += $n;
            $reasons[] = "+{$n} {$why}";
        };

        if ($vendor->material_outsourcing) {
            $add(3, 'material outsourcing (CBN outsourcing guidelines)');
        }
        match ($vendor->data_access_level) {
            'full' => $add(2, 'full access to bank data'),
            'limited' => $add(1, 'limited access to bank data'),
            default => null,
        };
        if ($vendor->processes_personal_data) {
            $add(2, 'processes personal data (NDPA 2023)');
        }

        $apps = $vendor->id ? DB::table('ea_applications_ext')->where('vendor_id', $vendor->id)
            ->where('organization_id', $vendor->organization_id)->pluck('criticality') : collect();
        if ($apps->contains('critical')) {
            $add(2, 'supplies a critical application');
        } elseif ($apps->contains('high')) {
            $add(1, 'supplies a high-criticality application');
        }

        match ($vendor->risk_level) {
            'critical' => $add(2, 'rated critical by the bank'),
            'high' => $add(1, 'rated high by the bank'),
            default => null,
        };
        if ($vendor->substitutability_score !== null && $vendor->substitutability_score <= 2) {
            $add(1, 'few in-market alternatives');
        }

        $t = config('tprm.tier_thresholds');
        $tier = $points >= $t['tier_1'] ? 1 : ($points >= $t['tier_2'] ? 2 : 3);

        return ['points' => $points, 'tier' => $tier, 'reasons' => $reasons];
    }

    /** Store the computed tier; set a first review date for a vendor that has none. */
    public function apply(Vendor $vendor): Vendor
    {
        $tier = $this->evaluate($vendor)['tier'];
        $updates = ['tier' => $tier];
        if (! $vendor->next_review_date && $vendor->status !== 'terminated') {
            // Never assessed: onboarding due diligence is due now, not a full cycle from today.
            $updates['next_review_date'] = $vendor->last_assessed
                ? $this->nextReview($vendor->last_assessed, $tier)
                : now()->addDays(config('tprm.onboarding_days'))->startOfDay();
        }
        $vendor->forceFill($updates)->saveQuietly();

        return $vendor;
    }

    public function nextReview($from, int $tier): Carbon
    {
        return Carbon::parse($from)->addMonths(config('tprm.review_months')[$tier] ?? 24)->startOfDay();
    }

    /**
     * A completed assessment resets the vendor's review clock: last assessed on the assessment
     * date, next review one cadence later. The assessment records the residual rating its score implies.
     */
    public function recordCompletion(VendorAssessment $assessment): void
    {
        $vendor = $assessment->vendor()->withoutGlobalScopes()->first();
        $date = $assessment->assessment_date ?? now();

        $assessment->forceFill([
            'completed_at' => $assessment->completed_at ?? now(),
            'residual_rating' => VendorAssessment::residualFor($assessment->overall_score !== null ? (float) $assessment->overall_score : null),
            'next_review_date' => $this->nextReview($date, $vendor->tier ?? 2),
        ])->saveQuietly();

        // Only move the vendor's clock forward: an older assessment completed late does not rewind it.
        if (! $vendor->last_assessed || Carbon::parse($date)->gte($vendor->last_assessed)) {
            $vendor->forceFill([
                'last_assessed' => $date,
                'next_review_date' => $this->nextReview($date, $vendor->tier ?? 2),
            ])->save();
        }
    }
}
