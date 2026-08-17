<?php

namespace App\Jobs\Ea;

use App\Services\Ea\VendorConcentrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RecomputeVendorConcentration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(VendorConcentrationService $svc): void
    {
        $rows = $svc->compute(50);
        DB::table('ea_vendor_concentration')->truncate();
        foreach ($rows as $r) {
            DB::table('ea_vendor_concentration')->insert([
                'vendor_id' => $r['vendor_id'],
                'application_count' => $r['apps'],
                'critical_application_count' => $r['critical_apps'],
                'annual_spend_ngn' => $r['annual_spend_ngn'],
                'computed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
