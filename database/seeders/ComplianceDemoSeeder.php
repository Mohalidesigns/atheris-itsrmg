<?php

namespace Database\Seeders;

use App\Models\ComplianceAssessment;
use App\Models\ComplianceResult;
use App\Models\Control;
use App\Models\ControlFramework;
use App\Models\Evidence;
use App\Models\Gap;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Makes the Compliance module's demo data internally consistent for both banks.
 *
 * The bank seeders create assessments with hard-coded counts and scores but no requirement
 * results, and no control ↔ framework mappings or evidence. This seeder (runs after them):
 *  - maps each control to framework requirements by domain (a few controls stay unmapped);
 *  - gives every assessment one result per assessable requirement, consistent with its status,
 *    and recomputes the score from those results;
 *  - raises gaps from the latest assessment of each framework, at varied remediation stages;
 *  - attaches evidence (approved / pending / rejected / expired) to controls, gaps and results.
 *
 * Deterministic per organisation; each part is guarded per tenant so a re-run is a no-op.
 */
class ComplianceDemoSeeder extends Seeder
{
    /** Domain → requirement codes per framework slug. */
    private const DOMAIN_MAP = [
        'Cryptography' => [
            'iso-27001-2022' => ['A.8.24', 'A.8.11', 'A.5.33'], 'nist-csf-2' => ['PR.DS'],
            'cbn-cybersecurity' => ['CBN-3.5'], 'ndpa-2023' => ['NDPA-3.5'],
        ],
        'Data Protection' => [
            'iso-27001-2022' => ['A.5.34', 'A.8.12', 'A.8.10', 'A.5.12', 'A.8.13'], 'nist-csf-2' => ['PR.DS'],
            'cbn-cybersecurity' => ['CBN-3.4'], 'ndpa-2023' => ['NDPA-3.5', 'NDPA-3.1', 'NDPA-3.4', 'NDPA-2.2', 'NDPA-1.1', 'NDPA-3.2', 'NDPA-4.2'],
        ],
        'Governance' => [
            'iso-27001-2022' => ['A.5.1', 'A.5.2', 'A.5.4', 'A.5.31', 'A.5.35', 'A.5.36'], 'nist-csf-2' => ['GV.PO', 'GV.OV', 'GV.RR', 'GV.RM', 'GV.OC', 'GV.SC'],
            'cbn-cybersecurity' => ['CBN-1.1', 'CBN-1.2', 'CBN-1.3', 'CBN-1.4', 'CBN-2.1', 'CBN-2.2', 'CBN-2.3', 'CBN-2.4'], 'ndpa-2023' => ['NDPA-3.3'],
        ],
        'Identity & Access' => [
            'iso-27001-2022' => ['A.5.15', 'A.5.16', 'A.5.17', 'A.5.18', 'A.8.2', 'A.8.3', 'A.8.5'], 'nist-csf-2' => ['PR.AA'],
            'cbn-cybersecurity' => ['CBN-3.3'],
        ],
        'Logging & Monitoring' => [
            'iso-27001-2022' => ['A.8.15', 'A.8.16', 'A.8.17'], 'nist-csf-2' => ['DE.CM', 'DE.AE'],
            'cbn-cybersecurity' => ['CBN-4.1', 'CBN-4.2'],
        ],
        'Vulnerability Management' => [
            'iso-27001-2022' => ['A.8.8', 'A.8.9', 'A.8.29', 'A.8.7'], 'nist-csf-2' => ['ID.RA', 'PR.PS'],
            'cbn-cybersecurity' => ['CBN-4.3', 'CBN-4.4'],
        ],
    ];

    /** Base compliance rate per organisation (Tier-1 First Bank is more mature than Tier-2 Kano). */
    private const BASE_RATE = [2 => 0.58, 3 => 0.68];

    private const FINDINGS = [
        'non_compliant' => [
            'No documented procedure exists and testing found the control is not operating.',
            'Sample testing (25 items) found no evidence of the control being performed in the period.',
            'Requirement not addressed — no owner assigned and no implementation plan.',
        ],
        'partially_compliant' => [
            'Procedure documented but applied inconsistently — 4 of 25 samples lacked approval evidence.',
            'Control operates for core banking but not yet extended to digital channels (USSD, mobile).',
            'Implemented, but the last periodic review is overdue by more than 90 days.',
        ],
    ];

    public function run(): void
    {
        foreach ([2, 3] as $orgId) {
            if (! DB::table('organizations')->where('id', $orgId)->exists()) {
                continue;
            }
            mt_srand($orgId * 7919);

            $this->mapControls($orgId);
            $this->seedResults($orgId);
            $this->seedGaps($orgId);
            $this->seedEvidence($orgId);
        }
        mt_srand();
    }

    private function users(int $orgId): array
    {
        return DB::table('users')->where('organization_id', $orgId)->orderBy('id')->pluck('id')->all();
    }

    private function pick(array $items)
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** @param array<string,int> $weights */
    private function weighted(array $weights): string
    {
        $r = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $w) {
            if (($r -= $w) <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function mapControls(int $orgId): void
    {
        $controls = Control::withoutGlobalScopes()->where('organization_id', $orgId)->orderBy('id')->get(['id', 'domain']);
        if ($controls->isEmpty() || DB::table('control_framework_mappings')->whereIn('control_id', $controls->pluck('id'))->exists()) {
            return;
        }

        $reqIds = DB::table('framework_requirements as r')
            ->join('control_frameworks as f', 'f.id', '=', 'r.framework_id')
            ->get(['r.id', 'r.requirement_code', 'f.slug'])
            ->mapWithKeys(fn ($r) => [$r->slug.'|'.$r->requirement_code => $r->id]);

        $rows = [];
        $now = now();
        foreach ($controls as $i => $control) {
            // ~1 in 12 controls stays unmapped — the "orphan control" a mapping review should catch.
            if ($i % 12 === 11) {
                continue;
            }
            foreach (self::DOMAIN_MAP[$control->domain] ?? self::DOMAIN_MAP['Governance'] as $slug => $codes) {
                $code = $codes[$i % count($codes)];
                $reqId = $reqIds[$slug.'|'.$code] ?? null;
                if ($reqId) {
                    $rows[$control->id.'-'.$reqId] = [
                        'control_id' => $control->id, 'requirement_id' => $reqId,
                        'coverage' => $this->weighted(['full' => 70, 'partial' => 25, 'planned' => 5]),
                        'notes' => null, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('control_framework_mappings')->insert($chunk);
        }
    }

    private function seedResults(int $orgId): void
    {
        $users = $this->users($orgId);
        $frameworks = ControlFramework::pluck('short_name', 'id');
        $assessments = ComplianceAssessment::withoutGlobalScopes()->where('organization_id', $orgId)
            ->orderBy('start_date')->orderBy('id')->get();

        $controlFor = DB::table('control_framework_mappings as m')
            ->join('controls as c', 'c.id', '=', 'm.control_id')
            ->where('c.organization_id', $orgId)->whereNull('c.deleted_at')
            ->orderByRaw("case m.coverage when 'full' then 0 when 'partial' then 1 else 2 end")
            ->get(['m.requirement_id', 'c.id'])->groupBy('requirement_id')->map(fn ($g) => $g->first()->id);

        $cycle = [];
        foreach ($assessments as $a) {
            if (DB::table('compliance_results')->where('assessment_id', $a->id)->exists()) {
                continue;
            }

            $n = $cycle[$a->framework_id] = ($cycle[$a->framework_id] ?? 0) + 1;
            $short = $frameworks[$a->framework_id] ?? 'Framework';
            $start = Carbon::parse($a->start_date ?? now()->subMonths(3));
            $kind = $this->pick(['internal review', 'self-assessment', 'readiness assessment', 'internal audit']);

            [$assessedShare, $startDate, $endDate] = match ($a->status) {
                'planned' => [0.0, now()->addDays(mt_rand(7, 60)), null],
                'in_progress' => [0.35 + mt_rand(0, 40) / 100, $start, null],
                'cancelled' => [0.15, $start, $start->copy()->addDays(mt_rand(10, 30))],
                default => [1.0, $start, $start->copy()->addDays(mt_rand(30, 75))->min(now()->subDays(3))],
            };

            // Each successive cycle of a framework is a little better than the last.
            $rate = min(0.9, (self::BASE_RATE[$orgId] ?? 0.6) + 0.04 * ($n - 1));
            $rows = [];
            foreach (ComplianceAssessment::assessableRequirements($a->framework_id) as $req) {
                $assessed = $assessedShare >= 1.0 || mt_rand(1, 100) <= (int) round($assessedShare * 100);
                $status = ! $assessed ? 'not_assessed' : $this->weighted([
                    'compliant' => (int) round($rate * 100),
                    'partially_compliant' => (int) round((1 - $rate) * 55),
                    'non_compliant' => (int) round((1 - $rate) * 35),
                    'not_applicable' => 4,
                ]);
                $rows[] = [
                    'assessment_id' => $a->id,
                    'requirement_id' => $req->id,
                    'control_id' => $controlFor[$req->id] ?? null,
                    'status' => $status,
                    'findings' => isset(self::FINDINGS[$status]) ? $this->pick(self::FINDINGS[$status]) : ($status === 'compliant' && mt_rand(0, 3) === 0 ? 'Design and operating effectiveness confirmed by sample testing.' : null),
                    'recommendations' => isset(self::FINDINGS[$status]) ? 'Assign an owner, document the corrective action and re-test within 90 days.' : null,
                    'assessed_by' => $assessed ? ($a->lead_assessor_id ?? $this->pick($users)) : null,
                    'assessed_at' => $assessed ? $startDate->copy()->addDays(mt_rand(1, 25))->min($endDate ?? now())->toDateString() : null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
            DB::table('compliance_results')->insert($rows);

            $a->forceFill([
                'title' => "{$short} {$kind} — cycle {$n}",
                'start_date' => $startDate,
                'end_date' => $endDate,
                'summary' => $a->status === 'completed' ? "{$short} {$kind} completed. Findings raised as gaps for remediation tracking." : null,
            ])->saveQuietly();
            $a->recalculateScore();
        }
    }

    /**
     * Gaps from the most recent completed assessment of each framework and from each framework's
     * latest in-progress assessment (as the live result flow would have raised them). Older cycles
     * are superseded and raise nothing.
     */
    private function seedGaps(int $orgId): void
    {
        if (Gap::withoutGlobalScopes()->where('organization_id', $orgId)->whereNotNull('assessment_id')->exists()) {
            return;
        }
        $users = $this->users($orgId);

        $base = ComplianceAssessment::withoutGlobalScopes()->where('organization_id', $orgId);
        $latest = (clone $base)->where('status', 'completed')->orderByDesc('end_date')->get()->unique('framework_id')
            ->concat((clone $base)->where('status', 'in_progress')->orderByDesc('start_date')->get()->unique('framework_id'));

        foreach ($latest as $a) {
            $results = ComplianceResult::where('assessment_id', $a->id)
                ->whereIn('status', array_keys(ComplianceResult::GAP_SEVERITY))
                ->with('requirement:id,requirement_code,title')->orderBy('id')->get();

            $live = $a->status === 'in_progress';
            foreach ($results as $i => $r) {
                // Findings from an assessment still in flight have only just been raised.
                $status = $live ? 'identified' : $this->weighted(['identified' => 25, 'remediation_planned' => 25, 'in_progress' => 25, 'remediated' => 12, 'accepted' => 5, 'closed' => 8]);
                $severity = ComplianceResult::GAP_SEVERITY[$r->status];
                if ($severity === 'high' && $i % 5 === 0) {
                    $severity = 'critical';
                }
                $due = Carbon::parse($a->end_date ?? $r->assessed_at ?? now())->addDays(mt_rand(30, 150));

                Gap::withoutGlobalScopes()->create([
                    'organization_id' => $orgId,
                    'assessment_id' => $a->id,
                    'requirement_id' => $r->requirement_id,
                    'control_id' => $r->control_id,
                    'gap_code' => Gap::generateNextCode($orgId),
                    'title' => 'Gap: '.$r->requirement->requirement_code.' '.$r->requirement->title,
                    'description' => $r->findings,
                    'severity' => $severity,
                    'priority' => Gap::SEVERITY_PRIORITY[$severity],
                    'status' => $status,
                    'remediation_plan' => $status === 'identified' ? null : 'Document the procedure, assign the control owner and re-test operating effectiveness.',
                    'assigned_to' => $status === 'identified' && $i % 2 === 0 ? null : $this->pick($users),
                    'due_date' => $due,
                    'completed_at' => in_array($status, Gap::RESOLVED_STATUSES, true) ? $due->copy()->min(now())->subDays(mt_rand(1, 20)) : null,
                    'notes' => $status === 'accepted' ? 'Risk accepted by the CRO until the core-banking upgrade (compensating monitoring in place).' : null,
                ]);
            }
        }
    }

    private function seedEvidence(int $orgId): void
    {
        if (Evidence::withoutGlobalScopes()->where('organization_id', $orgId)->exists()) {
            return;
        }
        $users = $this->users($orgId);
        if (count($users) < 2) {
            return;
        }

        $templates = [
            ['document', 'Procedure document', 'Approved procedure and RACI for the control.'],
            ['screenshot', 'Configuration screenshot', 'System configuration showing the control setting enforced.'],
            ['log', 'System log extract', 'Log extract for the sampled period showing the control operating.'],
            ['attestation', 'Owner attestation', 'Quarterly attestation by the control owner.'],
            ['url', 'Ticket / dashboard link', 'Link to the ITSM change ticket or monitoring dashboard.'],
        ];

        $subjects = [];
        foreach (Control::withoutGlobalScopes()->where('organization_id', $orgId)->operational()->orderBy('id')->get(['id', 'control_code', 'title']) as $i => $c) {
            if ($i % 9 < 4) {
                $subjects[] = [$c, "{$c->control_code} {$c->title}"];
            }
        }
        foreach (Gap::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['in_progress', 'remediated'])->take(6)->get() as $g) {
            $subjects[] = [$g, "{$g->gap_code} remediation"];
        }
        $completed = ComplianceAssessment::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'completed')->pluck('id');
        foreach (ComplianceResult::whereIn('assessment_id', $completed)->where('status', 'compliant')->orderBy('id')->take(10)->get() as $r) {
            $subjects[] = [$r, 'assessment sample'];
        }

        foreach ($subjects as $i => [$subject, $label]) {
            [$type, $kind, $desc] = $templates[$i % count($templates)];
            $state = $this->weighted(['approved' => 60, 'pending' => 22, 'rejected' => 7, 'expired' => 11]);
            $uploader = $this->pick($users);
            $reviewer = collect($users)->reject(fn ($u) => $u === $uploader)->values()->all();
            $reviewer = $reviewer[mt_rand(0, count($reviewer) - 1)];
            $created = now()->subDays(mt_rand(10, 300));

            $record = [
                'organization_id' => $orgId,
                'evidenceable_type' => $subject::class,
                'evidenceable_id' => $subject->getKey(),
                'title' => "{$kind} — {$label}",
                'description' => $desc,
                'type' => $type,
                'status' => $state === 'expired' ? 'approved' : $state,
                'uploaded_by' => $uploader,
                'reviewed_by' => $state === 'pending' ? null : $reviewer,
                'reviewed_at' => $state === 'pending' ? null : $created->copy()->addDays(mt_rand(1, 7)),
                'review_notes' => $state === 'rejected' ? 'Screenshot does not show the date or the system name — resubmit with both visible.' : null,
                'valid_from' => $created->toDateString(),
                'valid_until' => $state === 'expired' ? now()->subDays(mt_rand(5, 60))->toDateString() : $created->copy()->addYear()->toDateString(),
                'created_at' => $created, 'updated_at' => $created,
            ];

            if ($type === 'url') {
                $record['url'] = 'https://itsm.example.ng/tickets/CHG-'.(41000 + $i);
            } else {
                $name = str($kind)->slug().'-'.($i + 1).'.txt';
                $path = "evidence/{$orgId}/demo/{$name}";
                $body = "{$record['title']}\n\n{$desc}\n\nDemo evidence generated for the Atheris compliance walkthrough.\n";
                Storage::disk('local')->put($path, $body);
                $record += ['file_path' => $path, 'file_name' => $name, 'file_size' => strlen($body), 'mime_type' => 'text/plain'];
            }

            DB::table('evidence')->insert($record + ['url' => null, 'file_path' => null, 'file_name' => null, 'file_size' => null, 'mime_type' => null]);
        }
    }
}
