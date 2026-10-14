<?php

namespace App\Http\Controllers\Ea;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\MonitoringMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Security Architecture & Assurance (ATH-VAL-015): for each trust zone and the applications in it,
 * the security controls mapped to the architecture, whether those controls are rated effective,
 * what continuous monitoring says about them, threat-model coverage and open configuration drift on
 * the underlying assets. Everything is the bank's own: controls resolve only within the bank.
 */
class SecurityAssuranceController extends Controller
{
    public function index(Request $request, MonitoringMetrics $monitoring)
    {
        $org = (int) $request->user()->organization_id;
        $signals = $monitoring->controlSignals($org);
        $apps = DB::table('ea_applications_ext')->where('organization_id', $org)->orderBy('name')->get(['id', 'code', 'name', 'criticality', 'asset_id']);
        $zones = DB::table('ea_zones')->where('organization_id', $org)->orderByDesc('trust_level')->orderBy('code')->get(['id', 'code', 'name', 'trust_level']);
        $assigned = DB::table('ea_zone_assignments')->where('organization_id', $org)->pluck('zone_id', 'application_id');
        $mappings = DB::table('ea_control_mappings as m')->leftJoin('controls as c', fn ($j) => $j->on('c.id', '=', 'm.control_id')->where('c.organization_id', $org))
            ->where('m.organization_id', $org)->get(['m.component_type', 'm.component_id', 'm.coverage', 'c.id as control_id', 'c.effectiveness']);
        $threatened = DB::table('ea_threat_models')->where('organization_id', $org)->where('subject_type', 'like', '%EaApplication')->pluck('subject_id')->flip();
        $drift = DB::table('config_drift_events')->where('organization_id', $org)->whereIn('status', ['open', 'investigating'])
            ->selectRaw('asset_id, count(*) as n')->groupBy('asset_id')->pluck('n', 'asset_id');

        $rows = $apps->map(function ($a) use ($mappings, $signals, $threatened, $drift, $assigned) {
            $m = $mappings->filter(fn ($x) => ($x->component_type === 'application' && (int) $x->component_id === $a->id)
                || ($x->component_type === 'zone' && (int) $x->component_id === (int) ($assigned[$a->id] ?? 0)));
            $controls = $m->whereNotNull('control_id')->unique('control_id');

            return [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'criticality' => $a->criticality, 'zone_id' => $assigned[$a->id] ?? null,
                'controls' => $controls->count(),
                'unresolved' => $m->whereNull('control_id')->count(),
                'covered' => $m->whereIn('coverage', ['full', 'partial'])->count(),
                'gaps' => $m->where('coverage', 'gap')->count(),
                'effective' => $controls->where('effectiveness', 'effective')->count(),
                'ccm_fail' => $controls->filter(fn ($c) => ($signals[$c->control_id] ?? null) === 'fail')->count(),
                'ccm_monitored' => $controls->filter(fn ($c) => isset($signals[$c->control_id]))->count(),
                'threat_model' => isset($threatened[$a->id]),
                'drift' => $a->asset_id ? (int) ($drift[$a->asset_id] ?? 0) : 0,
            ];
        });
        $assurance = fn ($r) => match (true) {
            $r['controls'] === 0 => 'none',
            $r['ccm_fail'] > 0 || $r['drift'] > 0 || $r['effective'] / max(1, $r['controls']) < 0.5 => 'weak',
            $r['effective'] === $r['controls'] && $r['ccm_monitored'] > 0 => 'assured',
            default => 'partial',
        };
        $rows = $rows->map(fn ($r) => $r + ['assurance' => $assurance($r)]);

        return Inertia::render('Ea/SecurityAssurance', [
            'zones' => $zones->map(fn ($z) => (array) $z + ['apps' => $rows->where('zone_id', $z->id)->values()])->values(),
            'unzoned' => $rows->whereNull('zone_id')->values(),
            'summary' => [
                'applications' => $rows->count(),
                'zoned' => $rows->whereNotNull('zone_id')->count(),
                'with_controls' => $rows->where('controls', '>', 0)->count(),
                'assured' => $rows->where('assurance', 'assured')->count(),
                'weak' => $rows->where('assurance', 'weak')->count(),
                'critical_without_threat_model' => $rows->whereIn('criticality', ['critical', 'high'])->where('threat_model', false)->count(),
            ],
        ]);
    }
}
