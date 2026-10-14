<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * Continuous Monitoring figures for one bank (ATH-VAL-013): the monitoring dashboard, the CCM
 * console, the drift and access-review pages and the executive dashboard all read these.
 */
class MonitoringMetrics
{
    public function summary(int $orgId): array
    {
        $tests = DB::table('ccm_tenant_tests')->where('organization_id', $orgId)->where('status', 'enabled')->get(['id', 'last_status', 'control_id']);
        $drift = DB::table('config_drift_events')->where('organization_id', $orgId)->get(['status', 'severity', 'detected_at']);
        $openDrift = $drift->whereIn('status', ['open', 'investigating']);
        $campaigns = DB::table('access_review_campaigns')->where('organization_id', $orgId)->get(['status', 'due_at']);

        return [
            'tests' => $tests->count(),
            'pass' => $tests->where('last_status', 'pass')->count(),
            'warn' => $tests->where('last_status', 'warn')->count(),
            'fail' => $tests->where('last_status', 'fail')->count(),
            'error' => $tests->where('last_status', 'error')->count(),
            'unlinked' => $tests->whereNull('control_id')->count(),
            'runs_24h' => DB::table('ccm_test_runs')->where('organization_id', $orgId)->where('ran_at', '>=', now()->subDay())->count(),
            'controls_disagree' => count($this->disagreements($orgId)),
            'drift_open' => $openDrift->count(),
            'drift_critical' => $openDrift->whereIn('severity', ['critical', 'high'])->count(),
            'drift_30d' => $drift->where('detected_at', '>=', now()->subDays(30)->toDateTimeString())->count(),
            'drift_authorised_30d' => $drift->where('detected_at', '>=', now()->subDays(30)->toDateTimeString())->where('status', 'authorised')->count(),
            'reviews_in_progress' => $campaigns->where('status', 'in_progress')->count(),
            'reviews_overdue' => $campaigns->where('status', 'in_progress')->filter(fn ($c) => $c->due_at < today()->toDateString())->count(),
            'revocations_pending' => DB::table('access_review_items')->where('organization_id', $orgId)
                ->whereIn('decision', ['revoke', 'modify'])->whereNull('revocation_confirmed_at')->count(),
        ];
    }

    /** Pass / warn / fail counts per day for the last `$days` days. */
    public function trend(int $orgId, int $days = 14): array
    {
        $rows = DB::table('ccm_test_runs')->where('organization_id', $orgId)->where('ran_at', '>=', today()->subDays($days - 1))
            ->get(['status', 'ran_at'])->groupBy(fn ($r) => substr($r->ran_at, 0, 10));

        return collect(range($days - 1, 0))->map(function ($d) use ($rows) {
            $day = today()->subDays($d)->toDateString();
            $r = $rows->get($day, collect());

            return ['day' => $day, 'pass' => $r->where('status', 'pass')->count(), 'warn' => $r->where('status', 'warn')->count(), 'fail' => $r->where('status', 'fail')->count()];
        })->all();
    }

    /**
     * Controls rated effective whose linked CCM test is failing, or rated ineffective while every
     * linked test passes: the assessed rating and the monitoring signal disagree.
     */
    public function disagreements(int $orgId): array
    {
        $signals = $this->controlSignals($orgId);
        if (! $signals) {
            return [];
        }

        return DB::table('controls')->where('organization_id', $orgId)->whereIn('id', array_keys($signals))->whereNull('deleted_at')
            ->get(['id', 'control_code', 'title', 'effectiveness'])
            ->filter(fn ($c) => ($c->effectiveness === 'effective' && $signals[$c->id] === 'fail')
                || ($c->effectiveness === 'ineffective' && $signals[$c->id] === 'pass'))
            ->map(fn ($c) => ['id' => $c->id, 'control_code' => $c->control_code, 'title' => $c->title, 'effectiveness' => $c->effectiveness, 'signal' => $signals[$c->id]])
            ->values()->all();
    }

    /** Control id => worst latest status of its enabled CCM tests. */
    public function controlSignals(int $orgId): array
    {
        $rank = ['pass' => 0, 'warn' => 1, 'error' => 2, 'fail' => 3];

        return DB::table('ccm_tenant_tests')->where('organization_id', $orgId)->where('status', 'enabled')
            ->whereNotNull('control_id')->whereNotNull('last_status')->get(['control_id', 'last_status'])
            ->groupBy('control_id')
            ->map(fn ($g) => $g->sortByDesc(fn ($t) => $rank[$t->last_status] ?? 0)->first()->last_status)
            ->all();
    }
}
