<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\CoreBankingIntegration;
use App\Models\CoreBankingSnapshot;
use App\Models\User;
use App\Services\CoreBanking\CoreBankingConnector;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;

/**
 * Core Banking demo estate (ATH-VAL-017), driven through the real connector: each adapter is synced
 * 14 days ago and again today. One platform is patched between the two captures: at Kano the
 * Interswitch gateway (no approved change lists it: finding + issue); at First Bank Finacle
 * production (approved changes KHB/FBN-CHG-0013 and 0015 list it, so it is covered). First
 * Bank's T24 adapter is switched off by feature flag, so its sync is refused.
 */
class CoreBankingDemoSeeder extends Seeder
{
    /** org => [asset code prefix, integrations: [asset suffix, product, name, environment]] */
    private const BANKS = [
        2 => ['KHB-AST-', [
            ['CB-001', 'finacle', 'Finacle Core (Production)', 'production'],
            ['CB-002', 'finacle', 'Finacle Core (DR)', 'dr'],
            ['CB-003', 'flexcube', 'Flexcube Legacy Core', 'production'],
            ['TP-001', 'nibss', 'NIBSS NIP Gateway', 'production'],
            ['TP-002', 'interswitch', 'Interswitch Card Gateway', 'production'],
        ]],
        3 => ['FBN-AST-', [
            ['CB-001', 'finacle', 'Finacle Core (Production)', 'production'],
            ['CB-002', 'finacle', 'Finacle Core (DR)', 'dr'],
            ['CB-003', 'flexcube', 'Flexcube Treasury', 'production'],
            ['CB-006', 'bankone', 'Microfinance Core (BankOne)', 'production'],
            ['CB-005', 't24', 'Islamic Banking Core (T24)', 'production'],
            ['TP-001', 'nibss', 'NIBSS NIP Gateway', 'production'],
            ['TP-002', 'interswitch', 'Interswitch Card Gateway', 'production'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::BANKS as $orgId => [$prefix, $rows]) {
            $this->seedBank($orgId, $prefix, $rows, $orgId === 2 ? 'TP-002' : 'CB-001');
        }
        Carbon::setTestNow();
    }

    private function seedBank(int $orgId, string $prefix, array $rows, string $patched): void
    {
        $owner = User::where('organization_id', $orgId)->orderBy('id')->first();
        if (! $owner) {
            return;
        }
        CoreBankingSnapshot::withoutGlobalScopes()->where('organization_id', $orgId)->delete();
        CoreBankingIntegration::withoutGlobalScopes()->where('organization_id', $orgId)->delete();

        $connector = app(CoreBankingConnector::class);
        $integrations = [];
        foreach ($rows as [$suffix, $product, $name, $environment]) {
            $asset = Asset::withoutGlobalScopes()->where('organization_id', $orgId)->where('asset_id_code', $prefix.$suffix)->first();
            $integrations[$suffix] = CoreBankingIntegration::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'product' => $product, 'name' => $name, 'environment' => $environment,
                'mode' => 'sandbox', 'status' => 'connected', 'asset_id' => $asset?->id, 'owner_id' => $owner->id,
                'config' => ['endpoint' => "https://{$product}.internal/api"], 'created_by' => $owner->id,
            ]);
        }

        $this->syncAll($integrations, now()->subDays(14), $owner);

        // A platform is patched after the first capture.
        if ($prod = $integrations[$patched] ?? null) {
            $prod->update(['config' => ($prod->config ?? []) + ['sandbox' => ['platform' => ['patch_level' => 'SP5']]]]);
        }
        $this->syncAll($integrations, now(), $owner);
    }

    private function syncAll(array $integrations, Carbon $at, User $owner): void
    {
        Carbon::setTestNow($at);
        foreach ($integrations as $integration) {
            try {
                app(CoreBankingConnector::class)->sync($integration->fresh(), $owner);
            } catch (ValidationException) {
                // refused (adapter switched off): recorded on the integration
            }
        }
        Carbon::setTestNow();
    }
}
