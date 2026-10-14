<?php

namespace Database\Seeders;

use App\Models\BcpPlan;
use App\Models\BcpTest;
use App\Models\BiaRecord;
use App\Models\DrExercise;
use App\Models\DrRunbook;
use App\Models\Ea\BusinessService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Business Continuity demo data (ATH-VAL-012). Runs after PciDemoSeeder.
 *
 * Per bank, deterministically:
 * - links each BIA to the business service it assesses; most are approved by a second person and
 *   their RTO/RPO written onto the service, two are left with the service still out of step;
 * - links plans to the services they recover;
 * - gives First Bank the DR runbooks and BCP test history it lacked (Kano had both);
 * - measures every DR exercise against its runbook's RTO, a few over target;
 * - leaves two active plans without a test in the last 12 months (deliberate gaps).
 */
class BusinessContinuityDemoSeeder extends Seeder
{
    private const BANKS = [
        'kano-heritage-bank' => ['domain' => 'kanoheritage.ng', 'approver' => 'cro', 'tester' => 'itops', 'bia' => [
            'Internet Banking' => 'Internet Banking', 'Mobile Banking' => 'Mobile Banking', 'USSD' => 'USSD Banking', 'NIBSS NIP' => 'NIBSS NIP',
            'Interswitch' => 'Interswitch Switch', 'Treasury Trading' => 'FX Operations', 'Card Operations' => 'Card Issuance', 'Loan Origination' => 'Loan Origination',
        ], 'unapplied' => ['Treasury Trading', 'Loan Origination']],
        'first-bank-nigeria' => ['domain' => 'firstbanknigeria.ng', 'approver' => 'cro', 'tester' => 'bcp', 'bia' => [
            'FirstOnline' => 'Internet Banking (FirstOnline)', 'FirstMobile' => 'Mobile Banking (FirstMobile)', 'USSD' => 'USSD Banking', 'NIBSS NIP' => 'Instant Payments (NIBSS NIP)',
            'Interswitch' => 'Card Switching', 'SWIFT' => 'SWIFT Cross-border Payments', 'Treasury Trading' => 'Treasury Dealing',
            'Card Operations' => 'ATM & PoS Acquiring', 'FirstMonie' => 'Agent Banking (FirstMonie)',
        ], 'unapplied' => ['Treasury Trading', 'SWIFT']],
    ];

    /** Plan-title fragment => service-name fragments it recovers. */
    private const PLAN_SERVICES = [
        'Channels' => ['Internet Banking', 'Mobile Banking', 'USSD', 'Agent Banking', 'WhatsApp'],
        'Payments' => ['NIBSS', 'Interswitch', 'Card', 'Instant Payments', 'ATM'],
        'Core Banking' => ['Finacle', 'Flexcube', 'Core Deposits'],
        'Treasury' => ['FX Operations', 'Treasury Dealing'],
        'SWIFT' => ['SWIFT'],
        'Enterprise BCP' => ['Internet Banking', 'Mobile Banking', 'NIBSS', 'Instant Payments', 'Finacle', 'Core Deposits'],
        'Cyber-Incident' => ['Finacle', 'Core Deposits', 'Internet Banking'],
    ];

    /** Achieved minutes as a share of the RTO target, per exercise, in order: most within target. */
    private const EXERCISE_RATIOS = [0.8, 0.9, 1.25, 0.7, 0.95, 1.6, 0.85, 0.75, 1.1, 0.9, 0.6, 0.95];

    public function run(): void
    {
        foreach (self::BANKS as $slug => $bank) {
            $org = Organization::where('slug', $slug)->first();
            if (! $org || BiaRecord::withoutGlobalScopes()->where('organization_id', $org->id)->whereNotNull('business_service_id')->exists()) {
                continue;
            }
            $person = fn (string $local) => User::where('email', "{$local}@{$bank['domain']}")->value('id');
            $services = BusinessService::withoutGlobalScopes()->where('organization_id', $org->id)->get(['id', 'name']);
            $service = fn (string $fragment) => $services->first(fn ($s) => str_contains($s->name, $fragment));

            $this->bia($org->id, $bank, $person($bank['approver']), $service);
            $this->plans($org->id, $services);
            $runbooks = DrRunbook::withoutGlobalScopes()->where('organization_id', $org->id)->get();
            if ($runbooks->isEmpty()) {
                $runbooks = $this->copyRunbooks($org->id);
            }
            $this->exercises($org->id, $runbooks, $person($bank['tester']));
            $this->tests($org->id, $person($bank['tester']));
        }
    }

    private function bia(int $orgId, array $bank, ?int $approver, callable $service): void
    {
        foreach (BiaRecord::withoutGlobalScopes()->where('organization_id', $orgId)->get() as $record) {
            $match = collect($bank['bia'])->first(fn ($svc, $fragment) => str_contains($record->process_name, $fragment));
            $svc = $match ? $service($match) : null;
            if (! $svc) {
                continue;
            }
            $unapplied = collect($bank['unapplied'])->contains(fn ($f) => str_contains($record->process_name, $f));
            $approvedBy = $approver && $approver !== $record->owner_id ? $approver : null;

            DB::table('bia_records')->where('id', $record->id)->update([
                'business_service_id' => $svc->id,
                'approved_by' => $unapplied ? null : $approvedBy,
                'approved_at' => $unapplied ? null : now()->subMonths(3),
                'applied_at' => $unapplied ? null : now()->subMonths(3),
            ]);
            if (! $unapplied && $record->rto_hours !== null) {
                DB::table('ea_business_services')->where('id', $svc->id)->update([
                    'rto_minutes' => $record->rto_hours * 60,
                    'rpo_minutes' => ($record->rpo_hours ?? 0) * 60,
                ]);
            }
        }
    }

    private function plans(int $orgId, $services): void
    {
        foreach (BcpPlan::withoutGlobalScopes()->where('organization_id', $orgId)->get(['id', 'title']) as $plan) {
            $fragments = collect(self::PLAN_SERVICES)->first(fn ($f, $key) => str_contains($plan->title, $key)) ?? [];
            foreach ($services->filter(fn ($s) => collect($fragments)->contains(fn ($f) => str_contains($s->name, $f))) as $svc) {
                DB::table('bcp_plan_service')->insertOrIgnore([
                    'organization_id' => $orgId, 'plan_id' => $plan->id, 'business_service_id' => $svc->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** First Bank had no runbooks: give it the same Nigerian recovery set as its own records. */
    private function copyRunbooks(int $orgId)
    {
        return DrRunbook::withoutGlobalScopes()->whereNotNull('organization_id')->where('organization_id', '!=', $orgId)->get()
            ->unique('name')
            ->map(fn ($r) => DrRunbook::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'name' => str_replace('Data Centre', 'Lagos Marina Data Centre', $r->name), 'steps' => $r->steps,
                'estimated_duration_min' => $r->estimated_duration_min, 'owner_role' => $r->owner_role,
                'category' => $r->category, 'description' => $r->description,
            ]))->values();
    }

    private function exercises(int $orgId, $runbooks, ?int $tester): void
    {
        $existing = DrExercise::withoutGlobalScopes()->whereIn('runbook_id', $runbooks->pluck('id'))->orderBy('id')->get();
        if ($existing->isEmpty()) {
            foreach ($runbooks->values() as $i => $runbook) {
                foreach ([10, 4] as $k => $monthsAgo) {
                    $existing->push(DrExercise::withoutGlobalScopes()->create([
                        'organization_id' => $orgId, 'runbook_id' => $runbook->id, 'status' => 'completed',
                        'scheduled_at' => now()->subMonths($monthsAgo)->subDays($i * 3)->setTime(9, 0),
                        'participants' => ['Head of IT Operations', 'BCP Coordinator', 'Vendor support'],
                        'outcome_notes' => "Planned failover of {$runbook->name}.",
                    ]));
                }
            }
        }

        foreach ($existing->values() as $i => $exercise) {
            $runbook = $runbooks->firstWhere('id', $exercise->runbook_id);
            $target = $runbook?->estimated_duration_min;
            if (! $target) {
                continue;
            }
            $achieved = (int) round($target * self::EXERCISE_RATIOS[$i % count(self::EXERCISE_RATIOS)]);
            $start = $exercise->started_at ?? $exercise->scheduled_at ?? now()->subMonths(2);
            DB::table('dr_exercises')->where('id', $exercise->id)->update([
                'organization_id' => $orgId, 'started_at' => $start, 'finished_at' => $start->copy()->addMinutes($achieved),
                'rto_target_min' => $target, 'rto_achieved_min' => $achieved,
                'result' => $achieved <= $target ? 'pass' : ($achieved <= $target * 1.5 ? 'partial' : 'fail'),
                'lessons' => $achieved > $target ? 'Failover was delayed waiting for the vendor to confirm replication state; add the vendor bridge to step 1.' : null,
            ]);
        }
    }

    /** First Bank had no test history: one test per active plan in the last year, two plans left untested. */
    private function tests(int $orgId, ?int $tester): void
    {
        if (BcpTest::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return;
        }
        $plans = BcpPlan::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', '!=', 'draft')->orderBy('plan_code')->get();
        foreach ($plans->values() as $i => $plan) {
            if ($i >= $plans->count() - 2) {
                DB::table('bcp_plans')->where('id', $plan->id)->update(['last_tested' => now()->subMonths(15)->toDateString()]);

                continue;
            }
            $date = now()->subMonths(2 + $i % 9)->toDateString();
            $outcome = ['pass', 'pass', 'partial', 'pass', 'fail', 'pass', 'pass', 'partial'][$i % 8];
            BcpTest::withoutGlobalScopes()->create([
                'organization_id' => $orgId, 'plan_id' => $plan->id, 'title' => "{$plan->title}: annual exercise",
                'test_type' => ['tabletop', 'simulation', 'walkthrough', 'full'][$i % 4], 'status' => 'completed',
                'scheduled_date' => $date, 'completed_date' => $date, 'conducted_by' => $tester,
                'results' => 'Recovery steps executed against the plan; timings recorded by the BCP coordinator.',
                'findings' => $outcome === 'pass' ? null : 'Call tree out of date for two teams; alternate site access cards not issued.',
                'recommendations' => $outcome === 'pass' ? null : 'Refresh the call tree quarterly; issue access cards before the next exercise.',
                'pass_fail' => $outcome,
            ]);
            DB::table('bcp_plans')->where('id', $plan->id)->update(['last_tested' => $date, 'next_test_date' => now()->parse($date)->addYear()->toDateString()]);
        }
    }
}
