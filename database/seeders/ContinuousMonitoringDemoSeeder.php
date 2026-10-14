<?php

namespace Database\Seeders;

use App\Models\AccessReviewCampaign;
use App\Models\CcmTenantTest;
use App\Models\CcmTest;
use App\Models\ConfigDriftEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Monitoring\AccessReviewService;
use App\Services\Monitoring\CcmRunner;
use App\Services\Monitoring\DriftService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Continuous Monitoring demo data (ATH-VAL-013). Runs after BusinessContinuityDemoSeeder.
 *
 * Per bank, through the real services so the history matches what the UI produces:
 * - access-review campaigns: two completed last quarter (privileged Atheris access, data-centre
 *   badges), one in progress on the AD groups with a privileged revocation not yet confirmed, and
 *   one vendor-access campaign past its due date;
 * - a month of drift detections, reconciled against the bank's approved changes; older drift is
 *   remediated or accepted, recent drift is open or under investigation;
 * - every starter-pack CCM test enabled for the bank, most linked to a control in the matching
 *   domain (a few left unlinked), with 14 days of daily runs and today's run raising issues for
 *   failures. Kano's previous CCM rows had random statuses; they are replaced.
 */
class ContinuousMonitoringDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'prefix' => 'KHB', 'system' => 'Finacle Core Banking',
            'owner' => 'compliance', 'reviewer' => 'cro', 'approver' => 'admin', 'it' => 'itops', 'security' => 'soc.analyst'],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'prefix' => 'FBN', 'system' => 'Finacle 10 (FirstCore)',
            'owner' => 'compliance', 'reviewer' => 'cro', 'approver' => 'ciso', 'it' => 'itops', 'security' => 'soc'],
    ];

    /** CCM test-code area => control domain it evidences. */
    private const DOMAINS = [
        'IAM' => 'Identity & Access', 'CRY' => 'Cryptography', 'DPR' => 'Data Protection', 'PRI' => 'Data Protection',
        'LOG' => 'Logging & Monitoring', 'INC' => 'Logging & Monitoring', 'VUL' => 'Vulnerability Management', 'END' => 'Vulnerability Management',
        'NET' => 'Vulnerability Management', 'SDL' => 'Vulnerability Management',
    ];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || ConfigDriftEvent::withoutGlobalScopes()->where('organization_id', $org->id)->exists()) {
                continue;
            }
            $person = fn (string $local) => User::where('email', "{$local}@{$bank['domain']}")->first();

            $this->accessReviews($org->id, $bank, $person);
            $this->drift($org->id, $bank, $person);
            $this->ccm($org->id, $bank, $person);
        }
    }

    private function accessReviews(int $orgId, array $bank, callable $person): void
    {
        $service = app(AccessReviewService::class);
        $owner = $person($bank['owner']);
        $reviewer = $person($bank['reviewer']);
        $approver = $person($bank['approver']);
        $it = $person($bank['it']);
        if (! $owner || ! $reviewer || ! $approver || ! $it) {
            return;
        }
        $code = fn (int $n) => sprintf('%s-UAR-%03d', $bank['prefix'], $n);
        $decideAll = function (AccessReviewCampaign $c, $at, array $revoke = []) use ($service, $reviewer, $approver) {
            foreach ($c->items()->get() as $item) {
                $who = (int) $item->user_id === (int) $reviewer->id ? $approver : $reviewer;
                $remove = in_array($item->account_name, $revoke, true) || ($item->last_login_at && $item->last_login_at->lt(now()->subDays(120)));
                $service->decide($item, $remove ? 'revoke' : 'keep', $remove ? 'No business need: account inactive or role changed.' : null, $who);
            }
            DB::table('access_review_items')->where('campaign_id', $c->id)->update(['decided_at' => $at]);
        };

        // 1. Last quarter's privileged-access review of Atheris itself: complete, removal confirmed.
        $c1 = $service->launch(['campaign_code' => $code(1), 'name' => 'Q3 privileged access — Atheris', 'system' => 'Atheris ITSRM&G', 'category' => 'privileged',
            'cadence' => 'quarterly', 'source' => 'atheris', 'reviewer_id' => $reviewer->id, 'due_at' => now()->subDays(45)->toDateString()], $owner);
        $sysadmin = $c1->items()->where('account_name', "sysadmin@{$bank['domain']}")->value('account_name');
        $decideAll($c1, now()->subDays(50), array_filter([$sysadmin]));
        $c1->items()->whereIn('decision', ['revoke', 'modify'])->get()->each(fn ($i) => $service->confirm($i, $it));
        DB::table('access_review_items')->where('campaign_id', $c1->id)->whereNotNull('revocation_confirmed_at')->update(['revocation_confirmed_at' => now()->subDays(47)]);
        $service->complete($c1, $approver);
        DB::table('access_review_campaigns')->where('id', $c1->id)->update(['started_at' => now()->subDays(75), 'completed_at' => now()->subDays(46), 'created_at' => now()->subDays(75)]);

        // 2. Data-centre badge review (manual list): completed 70 days ago, so the physical-access CCM test warns.
        $c2 = $service->launch(['campaign_code' => $code(2), 'name' => 'Data centre physical access — Q3', 'system' => 'Primary data centre badge system', 'category' => 'physical',
            'cadence' => 'quarterly', 'source' => 'manual', 'reviewer_id' => $reviewer->id, 'due_at' => now()->subDays(72)->toDateString()], $owner);
        foreach ([['badge-0114', 'DC hall A — 24x7', false], ['badge-0131', 'DC hall A — 24x7', false], ['badge-0207', 'DC cage 3 (core switching)', true],
            ['badge-0233', 'DC hall B — business hours', false], ['badge-0310', 'DC cage 3 (core switching)', true], ['badge-0412', 'Vendor escort — MainOne', false]] as [$acct, $ent, $priv]) {
            $c2->items()->create(['organization_id' => $orgId, 'account_name' => $acct, 'entitlement' => $ent, 'privileged' => $priv, 'reviewer_id' => $reviewer->id]);
        }
        $decideAll($c2, now()->subDays(71), ['badge-0412']);
        $c2->items()->where('decision', 'revoke')->get()->each(fn ($i) => $service->confirm($i, $it));
        $service->complete($c2, $approver);
        DB::table('access_review_campaigns')->where('id', $c2->id)->update(['started_at' => now()->subDays(85), 'completed_at' => now()->subDays(70), 'created_at' => now()->subDays(85)]);

        // 3. AD groups on core banking: in progress, a privileged service account revoked 10 days ago and not yet removed.
        $c3 = $service->launch(['campaign_code' => $code(3), 'name' => "Q4 AD groups — {$bank['system']}", 'system' => $bank['system'], 'category' => 'application',
            'cadence' => 'quarterly', 'source' => 'ad', 'reviewer_id' => $reviewer->id, 'due_at' => now()->addDays(12)->toDateString()], $owner);
        $items = $c3->items()->orderBy('id')->get();
        foreach ($items as $i => $item) {
            if ($item->account_name === 'svc_backup') {
                $service->decide($item, 'revoke', 'Backup runs under the new gMSA; the legacy service account is no longer used.', $reviewer);
                DB::table('access_review_items')->where('id', $item->id)->update(['decided_at' => now()->subDays(10)]);
            } elseif ($i % 3 !== 0 && (int) $item->user_id !== (int) $reviewer->id) {
                $service->decide($item, 'keep', null, $reviewer);
            }
        }
        DB::table('access_review_campaigns')->where('id', $c3->id)->update(['started_at' => now()->subDays(9), 'created_at' => now()->subDays(9)]);

        // 4. Vendor support accounts: past its due date with decisions outstanding.
        $c4 = $service->launch(['campaign_code' => $code(4), 'name' => 'Vendor support accounts — Q3', 'system' => 'Privileged access gateway (CyberArk)', 'category' => 'vendor',
            'cadence' => 'quarterly', 'source' => 'manual', 'reviewer_id' => $reviewer->id, 'due_at' => now()->subDays(6)->toDateString()], $owner);
        foreach ([['infosys.l2.support', 'Finacle app servers — RDP', true, 18], ['interswitch.noc', 'Card switch HSM console', true, 41],
            ['nibss.nip.ops', 'NIP gateway — read only', false, 3], ['mainone.dc.tech', 'DR site BMC consoles', true, 97], ['oracle.dba.contract', 'Flexcube DB — DBA', true, 160]] as [$acct, $ent, $priv, $days]) {
            $c4->items()->create(['organization_id' => $orgId, 'account_name' => $acct, 'entitlement' => $ent, 'privileged' => $priv,
                'reviewer_id' => $reviewer->id, 'last_login_at' => now()->subDays($days)]);
        }
        $c4->items()->where('account_name', 'nibss.nip.ops')->get()->each(fn ($i) => $service->decide($i, 'keep', null, $reviewer));
        DB::table('access_review_campaigns')->where('id', $c4->id)->update(['started_at' => now()->subDays(40), 'created_at' => now()->subDays(40)]);
    }

    private function drift(int $orgId, array $bank, callable $person): void
    {
        $service = app(DriftService::class);
        $approver = $person($bank['approver']);
        $it = $person($bank['it']);
        $first = true;
        $next = function () use (&$first, $bank) {
            if ($first) {
                $first = false;

                return "{$bank['prefix']}-DRF-001";
            }

            return null;
        };

        // Changes that touched assets: a deviation during the change window is authorised.
        $changes = DB::table('change_requests')->where('organization_id', $orgId)->whereIn('status', ['closed', 'review'])
            ->whereNotNull('actual_start_at')->whereNotNull('affected_asset_ids')->orderBy('actual_start_at')->limit(4)->get();
        foreach ($changes as $c) {
            $assetId = (int) (json_decode($c->affected_asset_ids, true)[0] ?? 0);
            $asset = DB::table('assets')->where('id', $assetId)->where('organization_id', $orgId)->first(['id', 'name', 'owner_id']);
            if ($asset) {
                $service->record($orgId, ['asset' => $asset, 'source' => 'wazuh', 'title' => 'Configuration files changed: application release',
                    'severity' => 'medium', 'baseline' => 'Release baseline (previous build)', 'observed' => 'New build hashes',
                    'detected_at' => now()->parse($c->actual_start_at)->addMinutes(40), 'code' => $next()], null);
            }
        }

        foreach ([29, 22, 15, 9, 4, 1] as $daysAgo) {
            $day = now()->subDays($daysAgo)->setTime(3, 15);
            foreach (array_keys(DriftService::CATALOGUE) as $k => $source) {
                foreach ($service->detect($source, $orgId, $day->toDateString()) as $f) {
                    $service->record($orgId, $f + ['source' => $source, 'detected_at' => $day->copy()->addMinutes($k * 7), 'code' => $next()], null);
                }
            }
            // Drift more than three days old by then has been worked: most remediated, some accepted.
            ConfigDriftEvent::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['open', 'investigating'])
                ->where('detected_at', '<', $day->copy()->subDays(3))->get()
                ->each(function (ConfigDriftEvent $e) use ($day, $approver, $it) {
                    $accept = $e->id % 4 === 0 && $approver && (int) $approver->id !== (int) $e->owner_id;
                    $e->update($accept
                        ? ['status' => 'accepted', 'resolved_by' => $approver->id, 'resolved_at' => $day, 'accepted_until' => $day->copy()->addMonths(3)->toDateString(),
                            'resolution_note' => 'Legacy host scheduled for decommissioning in the next change window; compensating control: access restricted to the jump host.']
                        : ['status' => 'remediated', 'resolved_by' => $it?->id, 'resolved_at' => $day->copy()->subDays(1),
                            'resolution_note' => "Restored to baseline and re-scanned clean ({$e->observed_value} → {$e->baseline_value})."]);
                });
        }
        // Recent drift: some taken into investigation.
        ConfigDriftEvent::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open')
            ->where('detected_at', '<', now()->subDays(2))->get()
            ->filter(fn ($e) => $e->id % 2 === 0)
            ->each(fn ($e) => $e->update(['status' => 'investigating', 'owner_id' => $e->owner_id ?? $it?->id]));
    }

    private function ccm(int $orgId, array $bank, callable $person): void
    {
        $runner = app(CcmRunner::class);
        $security = $person($bank['security']);
        $owner = $person($bank['owner']);

        // Replace the random-status rows the platform seeder wrote.
        $old = DB::table('ccm_tenant_tests')->where('organization_id', $orgId)->pluck('id');
        DB::table('ccm_test_runs')->whereIn('tenant_test_id', $old)->whereNull('measured_value')->delete();

        $controls = DB::table('controls')->where('organization_id', $orgId)->whereNull('deleted_at')->orderBy('control_code')->get(['id', 'domain'])->groupBy('domain');
        $used = [];
        foreach (CcmTest::where('active', true)->orderBy('code')->get() as $n => $test) {
            $area = explode('-', $test->code)[1] ?? '';
            $domain = self::DOMAINS[$area] ?? 'Governance';
            $pool = $controls->get($domain) ?? $controls->get('Governance') ?? collect();
            $control = null;
            if ($pool->isNotEmpty() && $n % 5 !== 4) {
                $used[$domain] = ($used[$domain] ?? -1) + 1;
                $control = $pool[$used[$domain] % $pool->count()];
            }
            $tt = CcmTenantTest::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $orgId, 'ccm_test_id' => $test->id],
                ['status' => 'enabled', 'control_id' => $control?->id,
                    'owner_id' => in_array($area, ['IAM', 'LOG', 'INC', 'VUL', 'END', 'NET'], true) ? $security?->id : $owner?->id],
            );
            foreach (range(14, 1) as $daysAgo) {
                $runner->run($tt, null, now()->subDays($daysAgo)->setTime(2, 0), false);
            }
            $runner->run($tt->fresh(), null, now()->subMinutes(20 + $n));
        }
    }
}
