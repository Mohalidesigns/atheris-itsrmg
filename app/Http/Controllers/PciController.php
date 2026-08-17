<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Control;
use App\Models\Evidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * PCI-DSS v4.0.1 compliance module.
 * Uses Kano Heritage data: card-bearing assets, tenant-adopted controls,
 * evidence vault items, and a hand-curated SAQ-D structure.
 */
class PciController extends Controller
{
    protected function orgId(): int
    {
        return (int) auth()->user()?->organization_id;
    }

    /** 12 PCI-DSS v4.0.1 Requirements — canonical list */
    public static function requirements(): array
    {
        return [
            ['R1', 'Install and Maintain Network Security Controls', 'Firewalls, segmentation, ACL reviews.'],
            ['R2', 'Apply Secure Configurations to All System Components', 'Harden OS, containers, DBs.'],
            ['R3', 'Protect Stored Account Data', 'Truncation, hashing, tokenisation, strong crypto.'],
            ['R4', 'Protect Cardholder Data with Strong Cryptography During Transmission', 'TLS 1.2+, no weak ciphers.'],
            ['R5', 'Protect All Systems and Networks from Malicious Software', 'EDR, AV coverage, patching.'],
            ['R6', 'Develop and Maintain Secure Systems and Software', 'SDLC, SAST, SCA, change control.'],
            ['R7', 'Restrict Access to System Components and Cardholder Data by Business Need to Know', 'RBAC, least-privilege.'],
            ['R8', 'Identify Users and Authenticate Access to System Components', 'MFA, unique IDs, password policy.'],
            ['R9', 'Restrict Physical Access to Cardholder Data', 'Badge access, CCTV, visitor logs.'],
            ['R10', 'Log and Monitor All Access to System Components and Cardholder Data', 'SIEM, audit-log retention.'],
            ['R11', 'Test Security of Systems and Networks Regularly', 'ASV scans, pen-tests, WAF.'],
            ['R12', 'Support Information Security with Organizational Policies and Programs', 'Policy, training, IR plan.'],
        ];
    }

    protected function maturity(): array
    {
        return [
            'R1' => 85, 'R2' => 78, 'R3' => 92, 'R4' => 95, 'R5' => 88, 'R6' => 72,
            'R7' => 81, 'R8' => 87, 'R9' => 90, 'R10' => 76, 'R11' => 68, 'R12' => 82,
        ];
    }

    public function dashboard()
    {
        $orgId = $this->orgId();
        $reqs = static::requirements();
        $maturity = $this->maturity();
        $overall = (int) round(array_sum($maturity) / count($maturity));

        $cdeAssets = Asset::where('organization_id', $orgId)
            ->whereIn('category', ['Channel', 'Payments', 'Third-Party'])
            ->count();
        $policies = DB::table('policies')->where('organization_id', $orgId)
            ->where(function ($q) {
                $q->where('title', 'like', '%PCI%')->orWhere('category', 'security');
            })->count();

        return Inertia::render('PCI/Dashboard', [
            'requirements' => array_map(fn ($r) => [
                'code' => $r[0], 'title' => $r[1], 'description' => $r[2],
                'maturity' => $maturity[$r[0]] ?? 0,
            ], $reqs),
            'overall' => $overall,
            'lastAssessedAt' => now()->subDays(15)->toDateString(),
            'nextAssessmentAt' => now()->addDays(180)->toDateString(),
            'saq' => 'SAQ-D (Service Provider)',
            'assetsInCde' => $cdeAssets,
            'controlsMappedPci' => DB::table('controls')->where('organization_id', $orgId)->count(),
            'relevantPolicies' => $policies,
            'evidenceArtefacts' => (int) DB::table('evidence_vault')->count(),
        ]);
    }

    public function cde()
    {
        $orgId = $this->orgId();
        $cde = Asset::where('organization_id', $orgId)
            ->whereIn('category', ['Channel', 'Payments', 'Third-Party', 'Data', 'Security'])
            ->with('owner:id,name')
            ->orderBy('criticality')
            ->get();

        $zones = [
            'Card Issuance' => $cde->filter(fn ($a) => str_contains($a->name, 'Card') || str_contains($a->name, 'HSM')),
            'Payment Processing' => $cde->filter(fn ($a) => str_contains($a->name, 'NIBSS') || str_contains($a->name, 'Interswitch') || str_contains($a->name, 'PoS')),
            'Channels (Customer-facing)' => $cde->where('category', 'Channel'),
            'Cryptographic Key Management' => $cde->filter(fn ($a) => str_contains($a->name, 'HSM') || str_contains($a->name, 'SWIFT')),
            'Storage & Data' => $cde->where('category', 'Data'),
        ];

        return Inertia::render('PCI/Cde', [
            'assets' => $cde->values(),
            'zones' => collect($zones)->map(fn ($items, $k) => ['name' => $k, 'items' => $items->values()])->values(),
        ]);
    }

    public function controls()
    {
        $orgId = $this->orgId();
        $reqs = static::requirements();
        $controls = Control::where('organization_id', $orgId)->orderBy('control_code')->get();

        $byReq = [];
        foreach ($reqs as [$code, $title]) {
            $byReq[$code] = $controls->isNotEmpty() ? $controls->random(min(4, $controls->count()))->values() : collect();
        }

        return Inertia::render('PCI/Controls', [
            'requirements' => array_map(fn ($r) => ['code' => $r[0], 'title' => $r[1]], $reqs),
            'byReq' => $byReq,
        ]);
    }

    public function saq()
    {
        $sections = [];
        $seededResponses = ['yes', 'yes', 'yes', 'yes_cc', 'no', 'na'];
        foreach (static::requirements() as [$code, $title]) {
            $qs = [];
            for ($q = 1; $q <= 6; $q++) {
                $qs[] = [
                    'code' => "{$code}.{$q}",
                    'question' => $this->saqQuestion($code, $q),
                    'response' => $seededResponses[($code[1] + $q) % 6],
                ];
            }
            $sections[] = ['code' => $code, 'title' => $title, 'questions' => $qs];
        }
        $flat = collect($sections)->flatMap(fn ($s) => $s['questions']);
        $summary = [
            'total' => $flat->count(),
            'yes' => $flat->where('response', 'yes')->count(),
            'yes_cc' => $flat->where('response', 'yes_cc')->count(),
            'no' => $flat->where('response', 'no')->count(),
            'na' => $flat->where('response', 'na')->count(),
        ];
        return Inertia::render('PCI/Saq', compact('sections', 'summary'));
    }

    private function saqQuestion(string $req, int $q): string
    {
        $map = [
            'R1' => 'Have firewall / ACL rules for the %s.%d path been reviewed in the last 6 months?',
            'R2' => 'Is configuration baseline %s.%d enforced via automated tooling?',
            'R3' => 'Is stored cardholder data protected per %s.%d using strong cryptography?',
            'R4' => 'Are %s.%d transmission paths encrypted with TLS 1.2+ / no weak ciphers?',
            'R5' => 'Is endpoint protection deployed on all %s.%d system components?',
            'R6' => 'Does the SDLC for %s.%d include SAST, SCA, and secure-code review?',
            'R7' => 'Is access to cardholder data for %s.%d governed by RBAC + least-privilege?',
            'R8' => 'Are all users authenticated uniquely for %s.%d with MFA where applicable?',
            'R9' => 'Are physical access controls for %s.%d facilities logged and reviewed?',
            'R10' => 'Are all %s.%d access events logged to the SIEM with ≥ 12-month retention?',
            'R11' => 'Are ASV scans + penetration tests executed on %s.%d to schedule?',
            'R12' => 'Is policy %s.%d published, attested annually, and linked to training?',
        ];
        return sprintf($map[$req] ?? 'Is %s.%d implemented?', $req, $q);
    }

    public function matrix()
    {
        $reqs = static::requirements();
        $maturity = $this->maturity();
        $cellFor = fn ($level) => $level >= 90 ? 'implemented' : ($level >= 75 ? 'partial' : ($level >= 50 ? 'planned' : 'gap'));
        $grid = [];
        foreach ($reqs as [$code, $title]) {
            for ($sub = 1; $sub <= 6; $sub++) {
                $level = max(0, ($maturity[$code] ?? 0) + rand(-15, 10));
                $grid[] = [
                    'req' => $code,
                    'sub' => $code.'.'.$sub,
                    'level' => $level,
                    'status' => $cellFor($level),
                ];
            }
        }
        return Inertia::render('PCI/Matrix', [
            'requirements' => array_map(fn ($r) => ['code' => $r[0], 'title' => $r[1]], $reqs),
            'grid' => $grid,
        ]);
    }

    public function evidence()
    {
        $vaultItems = DB::table('evidence_vault')->where('organization_id', 1)
            ->orderByDesc('created_at')->take(80)->get();
        $reqs = static::requirements();
        $byReq = [];
        foreach ($reqs as [$code, $title]) {
            $byReq[$code] = $vaultItems->isNotEmpty() ? $vaultItems->random(min(3, $vaultItems->count()))->values() : collect();
        }
        return Inertia::render('PCI/Evidence', [
            'vaultItems' => $vaultItems,
            'byReq' => $byReq,
            'summary' => [
                'total' => $vaultItems->count(),
                'size' => (int) $vaultItems->sum('bytes'),
            ],
        ]);
    }
}
