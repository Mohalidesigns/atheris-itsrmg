<?php

namespace App\Services\CoreBanking;

use Illuminate\Support\Facades\DB;

/** Core Banking figures for one bank (ATH-VAL-017), read by the executive dashboard. */
class CoreBankingMetrics
{
    public function summary(int $orgId): array
    {
        $rows = DB::table('core_banking_integrations')->where('organization_id', $orgId)->get(['status', 'last_status', 'last_sync_at', 'findings_count']);
        $high = 0;
        foreach (DB::table('core_banking_integrations')->where('organization_id', $orgId)->pluck('id') as $id) {
            $f = json_decode((string) DB::table('core_banking_snapshots')->where('integration_id', $id)->orderByDesc('captured_at')->orderByDesc('id')->value('findings'), true) ?: [];
            $high += collect($f)->whereIn('severity', ['critical', 'high'])->count();
        }

        return [
            'integrations' => $rows->count(),
            'connected' => $rows->where('status', 'connected')->count(),
            'failing' => $rows->where('last_status', 'failed')->count(),
            'findings_open' => $high,
            'last_sync' => $rows->max('last_sync_at'),
        ];
    }
}
