<?php

namespace App\Services\Vendors;

use App\Models\TprmBreachEvent;
use App\Models\TprmSecurityRating;
use App\Models\Vendor;
use App\Models\VendorAssessment;

/**
 * One set of Vendor Management numbers, read by the register, Security Ratings and the executive
 * dashboard. Every query goes through tenant-scoped models.
 */
class VendorMetrics
{
    public function summary(): array
    {
        $today = now()->startOfDay();
        $vendors = Vendor::query()->where('status', '!=', 'terminated')
            ->with('latestRating')
            ->get(['id', 'tier', 'risk_level', 'status', 'contract_end', 'next_review_date', 'last_assessed', 'processes_personal_data', 'dpa_signed_at', 'material_outsourcing', 'regulator_notified_at', 'domain', 'owner_id']);

        $recentWindow = now()->subDays(config('tprm.drop_window_days'));
        $drops = TprmSecurityRating::query()->where('captured_at', '>=', $recentWindow)
            ->where('delta_from_previous', '<=', -config('tprm.rating_drop_alert'))->distinct()->pluck('vendor_id');

        return [
            'total' => $vendors->count(),
            'by_tier' => collect([1, 2, 3])->mapWithKeys(fn ($t) => [$t => $vendors->where('tier', $t)->count()])->all(),
            'untiered' => $vendors->whereNull('tier')->count(),
            'unowned' => $vendors->whereNull('owner_id')->count(),
            'review_overdue' => $vendors->filter(fn ($v) => $v->next_review_date && $v->next_review_date->lt($today))->count(),
            'tier1_review_overdue' => $vendors->filter(fn ($v) => $v->tier === 1 && $v->next_review_date && $v->next_review_date->lt($today))->count(),
            'contracts_expiring' => $vendors->filter(fn ($v) => $v->contract_end && $v->contract_end->gte($today)
                && $v->contract_end->lte($today->copy()->addDays(config('tprm.contract_warning_days'))))->count(),
            'contracts_expired' => $vendors->filter(fn ($v) => $v->contract_end && $v->contract_end->lt($today) && $v->status === 'active')->count(),
            'processors' => $vendors->where('processes_personal_data', true)->count(),
            'missing_dpa' => $vendors->filter(fn ($v) => $v->processes_personal_data && ! $v->dpa_signed_at)->count(),
            'material_outsourcing' => $vendors->where('material_outsourcing', true)->count(),
            'material_not_notified' => $vendors->filter(fn ($v) => $v->material_outsourcing && ! $v->regulator_notified_at)->count(),
            'rated' => $vendors->filter(fn ($v) => $v->latestRating)->count(),
            'poor_rating' => $vendors->filter(fn ($v) => $v->latestRating && in_array($v->latestRating->grade, ['D', 'F'], true))->count(),
            'rating_drops' => $drops->count(),
            'open_breach_events' => TprmBreachEvent::query()->where('status', 'new')->count(),
            'assessments_open' => VendorAssessment::query()->whereIn('status', ['pending', 'in_progress'])->count(),
        ];
    }

    /** Tier 1 and 2 vendors with their latest rating, worst first (dashboard scoreboard). */
    public function scoreboard(int $limit = 10): array
    {
        return Vendor::query()->where('status', '!=', 'terminated')->whereIn('tier', [1, 2])
            ->with('latestRating')->get(['id', 'name', 'tier', 'risk_level'])
            ->sortBy(fn ($v) => [$v->latestRating ? 0 : 1, $v->latestRating?->rating_value ?? 999])
            ->take($limit)
            ->map(fn ($v) => [
                'id' => $v->id, 'name' => $v->name, 'tier' => $v->tier, 'risk_level' => $v->risk_level,
                'grade' => $v->latestRating?->grade ?? '—',
                'rating_value' => $v->latestRating?->rating_value,
                'delta' => $v->latestRating?->delta_from_previous ?? 0,
            ])->values()->all();
    }
}
