<?php

namespace App\Http\Controllers;

use App\Models\AccessReviewCampaign;
use App\Models\CcmTestRun;
use App\Models\ConfigDriftEvent;
use App\Models\Control;
use App\Services\Monitoring\MonitoringMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Continuous Monitoring dashboard and controls view (ATH-VAL-013). Every figure comes from the
 * bank's own CCM runs, drift register and access reviews (MonitoringMetrics); the previous version
 * counted every bank's CCM runs and generated drift and access reviews at random.
 */
class MonitoringController extends Controller
{
    public function dashboard(Request $request, MonitoringMetrics $metrics)
    {
        $orgId = (int) $request->user()->organization_id;

        return Inertia::render('Monitoring/Dashboard', [
            'kpis' => $metrics->summary($orgId),
            'trend' => $metrics->trend($orgId),
            'recent' => CcmTestRun::query()->with('tenantTest.test:id,code,title')->orderByDesc('ran_at')->limit(15)->get()
                ->map(fn ($r) => ['id' => $r->id, 'tenant_test_id' => $r->tenant_test_id, 'code' => $r->tenantTest?->test?->code, 'title' => $r->tenantTest?->test?->title,
                    'status' => $r->status, 'mode' => $r->mode, 'summary' => $r->output_summary, 'ran_at' => $r->ran_at]),
            'drift' => ConfigDriftEvent::query()->with('asset:id,name')->whereIn('status', ConfigDriftEvent::OPEN_STATUSES)
                ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->orderByDesc('detected_at')->limit(6)->get(),
            'reviews' => AccessReviewCampaign::query()->where('status', 'in_progress')->withCount(['items', 'items as decided_count' => fn ($q) => $q->whereNotNull('decision')])
                ->orderBy('due_at')->limit(6)->get(),
            'disagreements' => $metrics->disagreements($orgId),
        ]);
    }

    /** Controls with their assessed effectiveness beside the CCM signal from linked tests. */
    public function controls(Request $request, MonitoringMetrics $metrics)
    {
        $orgId = (int) $request->user()->organization_id;
        $signals = $metrics->controlSignals($orgId);
        $controls = Control::query()->with(['owner:id,name'])
            ->withCount(['ccmTests as ccm_tests_count'])
            ->orderBy('domain')->orderBy('control_code')->get(['id', 'control_code', 'title', 'domain', 'type', 'frequency', 'effectiveness', 'owner_id', 'last_tested', 'is_key_control'])
            ->map(fn ($c) => $c->toArray() + ['signal' => $signals[$c->id] ?? null]);

        $filter = $request->input('filter');
        $rows = $controls->filter(fn ($c) => match ($filter) {
            'monitored' => $c['signal'] !== null,
            'failing' => $c['signal'] === 'fail',
            'disagree' => ($c['effectiveness'] === 'effective' && $c['signal'] === 'fail') || ($c['effectiveness'] === 'ineffective' && $c['signal'] === 'pass'),
            default => true,
        })->values();

        return Inertia::render('Monitoring/Controls', [
            'controls' => $rows,
            'buckets' => [
                'effective' => $controls->where('effectiveness', 'effective')->count(),
                'partially_effective' => $controls->where('effectiveness', 'partially_effective')->count(),
                'ineffective' => $controls->where('effectiveness', 'ineffective')->count(),
                'not_assessed' => $controls->filter(fn ($c) => in_array($c['effectiveness'], [null, 'not_assessed'], true))->count(),
                'monitored' => count($signals),
                'disagree' => count($metrics->disagreements($orgId)),
            ],
            'filter' => $filter,
        ]);
    }
}
