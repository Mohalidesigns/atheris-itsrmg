<?php

namespace App\Services\Ea;

use App\Models\Ea\EaApplication;
use Illuminate\Support\Facades\DB;

/**
 * VendorConcentrationService — replaces the seeded random `vendor_concentration`
 * report with a real query joining `vendors`, `ea_applications_ext` (via the
 * new `vendor_id` column) and the optional asset master.
 *
 * Falls back gracefully when a tenant has no vendor data, returning the same
 * shape that the UI expects.
 */
class VendorConcentrationService
{
    public function compute(int $limit = 20): array
    {
        $hasVendorsTable = \Schema::hasTable('vendors');
        if (! $hasVendorsTable) {
            return $this->fallback($limit);
        }

        $rows = DB::table('vendors as v')
            ->leftJoin('ea_applications_ext as a', 'a.vendor_id', '=', 'v.id')
            ->select(
                'v.id as vendor_id',
                'v.name as vendor',
                DB::raw("COALESCE(MAX(v.category), 'Vendor') as category"),
                DB::raw('COUNT(a.id) as apps'),
                DB::raw("SUM(CASE WHEN a.criticality='critical' THEN 1 ELSE 0 END) as critical_apps"),
                DB::raw('COALESCE(SUM(a.annual_cost_ngn), 0) as annual_spend_ngn')
            )
            ->groupBy('v.id', 'v.name')
            ->orderByDesc('annual_spend_ngn')
            ->orderByDesc('apps')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'vendor_id' => $r->vendor_id,
            'vendor' => $r->vendor,
            'category' => $r->category,
            'apps' => (int) $r->apps,
            'critical_apps' => (int) $r->critical_apps,
            'annual_spend_ngn' => (float) $r->annual_spend_ngn,
        ])->all();
    }

    private function fallback(int $limit): array
    {
        return EaApplication::query()
            ->selectRaw("COALESCE(owner_role, 'Unknown') as vendor, COUNT(*) as apps, SUM(CASE WHEN criticality='critical' THEN 1 ELSE 0 END) as critical_apps, COALESCE(SUM(annual_cost_ngn), 0) as annual_spend_ngn")
            ->groupBy('vendor')
            ->orderByDesc('annual_spend_ngn')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'vendor_id' => null,
                'vendor' => $r->vendor,
                'category' => 'Owner-role proxy',
                'apps' => (int) $r->apps,
                'critical_apps' => (int) $r->critical_apps,
                'annual_spend_ngn' => (float) $r->annual_spend_ngn,
            ])
            ->all();
    }
}
