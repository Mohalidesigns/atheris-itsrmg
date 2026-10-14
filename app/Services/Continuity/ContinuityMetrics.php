<?php

namespace App\Services\Continuity;

use App\Models\BcpPlan;
use App\Models\BcpTest;
use App\Models\BiaRecord;
use App\Models\DrExercise;
use App\Models\Ea\BusinessService;
use Illuminate\Support\Facades\DB;

/**
 * One set of Business Continuity numbers for the plan register, BIA, tests, DR pages and the
 * executive dashboard (ATH-VAL-012). Every query goes through tenant-scoped models.
 */
class ContinuityMetrics
{
    /** A plan untested for longer than this is overdue a test (CBN BCM guidance: at least annually). */
    public const TEST_MONTHS = 12;

    public function summary(): array
    {
        $plans = BcpPlan::query()->where('status', '!=', 'expired')->get(['id', 'status', 'last_tested', 'next_review_date', 'approved_by', 'plan_type']);
        $services = BusinessService::query()->where('organization_id', auth()->user()?->organization_id)->get(['id', 'criticality', 'rto_minutes']);
        $bias = BiaRecord::query()->whereNotNull('business_service_id')->get(['business_service_id', 'rto_hours', 'applied_at']);
        $critical = $services->whereIn('criticality', ['critical', 'high']);
        $planned = DB::table('bcp_plan_service')->whereIn('plan_id', $plans->pluck('id'))->distinct()->pluck('business_service_id')->flip();

        $exercises = DrExercise::query()->whereNotNull('result')->where('finished_at', '>=', now()->subYear())->get(['result']);
        $tests = BcpTest::query()->where('status', 'completed')->where('completed_date', '>=', now()->subYear())->get(['pass_fail']);

        return [
            'plans' => $plans->count(),
            'active' => $plans->where('status', 'active')->count(),
            'draft' => $plans->where('status', 'draft')->count(),
            'test_overdue' => $plans->filter(fn ($p) => ! $p->last_tested || $p->last_tested->lt(now()->subMonths(self::TEST_MONTHS)))->count(),
            'review_overdue' => $plans->filter(fn ($p) => $p->next_review_date && $p->next_review_date->lt(today()))->count(),
            'critical_services' => $critical->count(),
            'critical_without_bia' => $critical->reject(fn ($s) => $bias->contains('business_service_id', $s->id))->count(),
            'critical_without_plan' => $critical->reject(fn ($s) => isset($planned[$s->id]))->count(),
            'bia_not_applied' => $bias->filter(function ($b) use ($services) {
                $service = $services->firstWhere('id', $b->business_service_id);

                return $service && $b->rto_hours !== null && (int) round($b->rto_hours * 60) !== (int) $service->rto_minutes;
            })->count(),
            'tests_12m' => $tests->count(),
            'tests_failed_12m' => $tests->where('pass_fail', 'fail')->count(),
            'exercises_12m' => $exercises->count(),
            'exercises_within_rto' => $exercises->where('result', 'pass')->count(),
        ];
    }
}
