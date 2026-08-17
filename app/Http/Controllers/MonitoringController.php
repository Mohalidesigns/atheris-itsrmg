<?php

namespace App\Http\Controllers;

use App\Models\CcmTenantTest;
use App\Models\CcmTestRun;
use App\Models\Control;
use App\Models\Kri;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Continuous Monitoring module — pulls from CCM engine, control
 * effectiveness, KRIs, and synthetic drift + access-review datasets.
 */
class MonitoringController extends Controller
{
    protected function orgId(): int
    {
        return (int) auth()->user()?->organization_id;
    }

    public function dashboard()
    {
        $tests = CcmTenantTest::with('test')->get();
        $runs = CcmTestRun::orderByDesc('ran_at')->take(200)->get();
        $byStatus = $runs->groupBy('status')->map->count();

        $trend = [];
        for ($d = 13; $d >= 0; $d--) {
            $day = now()->subDays($d)->format('Y-m-d');
            $trend[] = [
                'day' => $day,
                'pass' => $runs->where('status', 'pass')->filter(fn ($r) => $r->ran_at?->format('Y-m-d') === $day)->count(),
                'warn' => $runs->where('status', 'warn')->filter(fn ($r) => $r->ran_at?->format('Y-m-d') === $day)->count(),
                'fail' => $runs->where('status', 'fail')->filter(fn ($r) => $r->ran_at?->format('Y-m-d') === $day)->count(),
            ];
        }

        $kpis = [
            'tests_total' => $tests->count(),
            'tests_pass' => $tests->where('last_status', 'pass')->count(),
            'tests_fail' => $tests->where('last_status', 'fail')->count(),
            'tests_warn' => $tests->where('last_status', 'warn')->count(),
            'runs_24h' => $runs->filter(fn ($r) => $r->ran_at?->gt(now()->subDay()))->count(),
            'kris_red' => Kri::with(['readings' => fn ($q) => $q->latest('recorded_at')->limit(1)])->get()
                ->filter(fn ($k) => $k->readings->first()?->status === 'red')->count(),
        ];

        return Inertia::render('Monitoring/Dashboard', [
            'kpis' => $kpis,
            'byStatus' => $byStatus,
            'trend' => $trend,
            'recent' => $runs->take(20)->load('tenantTest.test')->values(),
        ]);
    }

    public function controls()
    {
        $orgId = $this->orgId();
        $controls = Control::where('organization_id', $orgId)
            ->with('owner:id,name')
            ->orderBy('domain')->orderBy('control_code')
            ->get();
        $buckets = [
            'effective' => $controls->where('effectiveness', 'effective')->count(),
            'partially_effective' => $controls->where('effectiveness', 'partially_effective')->count(),
            'ineffective' => $controls->where('effectiveness', 'ineffective')->count(),
            'not_assessed' => $controls->where('effectiveness', 'not_assessed')->count(),
        ];
        return Inertia::render('Monitoring/Controls', compact('controls', 'buckets'));
    }

    public function drift()
    {
        // Synthetic configuration-drift events. In production these come from
        // AWS Config, Azure Defender for Cloud, GCP SCC.
        $systems = [
            'Finacle Core Banking (Prod)', 'Active Directory — Primary DC',
            'Microsoft Entra ID', 'Palo Alto Perimeter Firewall (HQ)',
            'AWS Production Account', 'Microsoft 365 Tenant',
            'Kano HQ Data Centre', 'Lagos DR Data Centre (MainOne)',
            'NIBSS NIP Gateway', 'Interswitch Card Gateway',
        ];
        $driftTypes = [
            'Open SSH port exposed to 0.0.0.0/0',
            'S3 bucket public-access block removed',
            'MFA bypassed for admin account',
            'Firewall rule appended outside change window',
            'Audit log forwarding disabled',
            'AD GPO baseline drift detected',
            'Encryption-at-rest disabled on DB',
            'TLS 1.0 enabled on listener',
            'Root account accessed without break-glass approval',
            'EDR agent removed from endpoint',
        ];
        $drift = [];
        for ($i = 0; $i < 30; $i++) {
            $drift[] = [
                'id' => $i + 1,
                'detected_at' => now()->subHours(rand(1, 240))->toDateTimeString(),
                'system' => $systems[array_rand($systems)],
                'type' => $driftTypes[array_rand($driftTypes)],
                'severity' => ['critical', 'high', 'medium', 'low'][rand(0, 3)],
                'source' => ['AWS Config', 'Defender for Cloud', 'Wazuh FIM', 'Manual audit', 'Tenable SC'][rand(0, 4)],
                'status' => ['open', 'investigating', 'remediated', 'accepted'][rand(0, 3)],
                'owner' => ['Segun Akinola', 'Ibrahim Danjuma', 'Obinna Eze', 'Chiamaka Nnamdi'][rand(0, 3)],
            ];
        }
        $buckets = collect($drift)->groupBy('status')->map->count();
        return Inertia::render('Monitoring/Drift', compact('drift', 'buckets'));
    }

    public function accessReviews()
    {
        $orgId = $this->orgId();
        $users = User::where('organization_id', $orgId)->get();

        $reviewCycles = [
            ['Privileged AD Admins', 'quarterly', 'in_progress', 12, 8, now()->addDays(14)],
            ['Finacle Core Banking Users', 'bi-annual', 'in_progress', 148, 92, now()->addDays(21)],
            ['SWIFT Alliance Gateway Operators', 'quarterly', 'completed', 8, 8, now()->addDays(-30)],
            ['Treasury Trading Desk Access', 'monthly', 'completed', 22, 22, now()->addDays(-7)],
            ['AWS Production IAM Roles', 'quarterly', 'in_progress', 45, 28, now()->addDays(9)],
            ['HSM Administrators', 'quarterly', 'overdue', 4, 0, now()->subDays(5)],
            ['SOC Analysts — SIEM access', 'quarterly', 'completed', 16, 16, now()->addDays(-14)],
            ['Vendor NIBSS NIP operators', 'bi-annual', 'in_progress', 12, 5, now()->addDays(30)],
            ['Vendor Interswitch operators', 'bi-annual', 'not_started', 18, 0, now()->addDays(45)],
            ['Card Issuance System operators', 'quarterly', 'completed', 24, 24, now()->addDays(-3)],
        ];
        $reviews = array_map(function ($r, $i) {
            [$scope, $cadence, $status, $total, $reviewed, $due] = $r;
            return [
                'id' => $i + 1,
                'scope' => $scope,
                'cadence' => $cadence,
                'status' => $status,
                'total_entitlements' => $total,
                'reviewed_entitlements' => $reviewed,
                'coverage_percent' => $total ? (int) round($reviewed / $total * 100) : 0,
                'due_at' => $due->toDateString(),
                'reviewer' => ['Adaeze Kunle-Usman', 'Chidi Okonkwo', 'Fatima Bello', 'Segun Akinola'][rand(0, 3)],
            ];
        }, $reviewCycles, array_keys($reviewCycles));

        $summary = [
            'cycles' => count($reviews),
            'in_progress' => collect($reviews)->where('status', 'in_progress')->count(),
            'completed' => collect($reviews)->where('status', 'completed')->count(),
            'overdue' => collect($reviews)->where('status', 'overdue')->count(),
        ];
        return Inertia::render('Monitoring/AccessReviews', compact('reviews', 'summary'));
    }
}
