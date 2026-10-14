<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\DB;

/**
 * What each starter-pack CCM test measures, and its pass / warn thresholds (ATH-VAL-013).
 *
 * "platform" checks are measured from the bank's own records in Atheris (policies, attestations,
 * DR exercises, BIAs, vulnerabilities, incidents, changes, vendors, returns, circulars, drift and
 * access reviews), so a run reflects the platform's real state. "sandbox" checks need a tool the
 * bank has not connected (AD, AWS, Defender, Tenable, SIEM, DLP...): they run against a
 * deterministic per-bank catalogue, are recorded with mode "sandbox", and say so in the UI.
 *
 * `better` is the direction of a good value; `pass` and `warn` are the limits for each band.
 * A bank can override `pass` / `warn` per test (ccm_tenant_tests.overrides).
 */
class CcmChecks
{
    public const CHECKS = [
        'CCM-IAM-MFA-001' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 98, 'metric' => 'privileged AD accounts enforcing MFA'],
        'CCM-IAM-STALE-002' => ['unit' => 'accounts', 'better' => 'lower', 'pass' => 0, 'warn' => 5, 'metric' => 'enabled accounts with no logon in 90 days'],
        'CCM-IAM-PRIV-003' => ['platform' => 'privilegedRevocations', 'unit' => 'entitlements', 'better' => 'lower', 'pass' => 0, 'warn' => 2, 'metric' => 'privileged revocations from access reviews not confirmed within 7 days'],
        'CCM-CRY-TLS-004' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'external endpoints negotiating TLS 1.3'],
        'CCM-CRY-ENC-005' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 100, 'metric' => 'core databases encrypted at rest'],
        'CCM-NET-S3-006' => ['unit' => 'buckets', 'better' => 'lower', 'pass' => 0, 'warn' => 0, 'metric' => 'S3 buckets allowing public access'],
        'CCM-NET-FW-007' => ['unit' => 'days', 'better' => 'lower', 'pass' => 90, 'warn' => 120, 'metric' => 'days since the last firewall rule review'],
        'CCM-VUL-PATCH-008' => ['unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 90, 'metric' => 'Windows hosts patched within 30 days'],
        'CCM-VUL-PATCH-009' => ['unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 90, 'metric' => 'Linux hosts patched within 30 days'],
        'CCM-VUL-CVE-010' => ['platform' => 'criticalVulnerabilities', 'unit' => 'vulnerabilities', 'better' => 'lower', 'pass' => 0, 'warn' => 2, 'metric' => 'critical vulnerabilities open past their due date'],
        'CCM-LOG-RET-011' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'log sources retained for 7 years'],
        'CCM-LOG-CEN-012' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'critical systems forwarding logs to the SIEM'],
        'CCM-INC-MTTD-013' => ['unit' => 'hours', 'better' => 'lower', 'pass' => 24, 'warn' => 48, 'metric' => 'mean time to detect (SIEM)'],
        'CCM-INC-MTTR-014' => ['platform' => 'meanTimeToResolve', 'unit' => 'hours', 'better' => 'lower', 'pass' => 72, 'warn' => 120, 'metric' => 'mean hours from detection to resolution, incidents resolved in 180 days'],
        'CCM-BCP-RTO-015' => ['platform' => 'nipFailover', 'unit' => 'days', 'better' => 'lower', 'pass' => 92, 'warn' => 120, 'metric' => 'days since the NIP failover last recovered within its RTO'],
        'CCM-BCP-BACKUP-016' => ['unit' => 'days', 'better' => 'lower', 'pass' => 31, 'warn' => 45, 'metric' => 'days since the last successful restore test'],
        'CCM-TPR-DDQ-017' => ['platform' => 'vendorAssessments', 'unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 85, 'metric' => 'tier-1 vendors assessed in the last 12 months'],
        'CCM-TPR-RATE-018' => ['platform' => 'vendorRatings', 'unit' => 'vendors', 'better' => 'lower', 'pass' => 0, 'warn' => 1, 'metric' => 'tier-1/2 vendors rated below grade C'],
        'CCM-PRI-DSAR-019' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'data-subject requests answered within 30 days'],
        'CCM-PRI-DPO-020' => ['unit' => 'appointed', 'better' => 'higher', 'pass' => 1, 'warn' => 1, 'metric' => 'DPCO appointment filed with the NDPC'],
        'CCM-GOV-POL-021' => ['platform' => 'policyReviews', 'unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 90, 'metric' => 'active policies within their review date'],
        'CCM-GOV-ATT-022' => ['platform' => 'attestationCoverage', 'unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 85, 'metric' => 'policy attestations completed (waivers excluded)'],
        'CCM-SDL-SAST-023' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'merges with a SAST scan'],
        'CCM-SDL-SCA-024' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'releases with an SCA scan'],
        'CCM-OPS-CHG-025' => ['platform' => 'changeApprovals', 'unit' => '%', 'better' => 'higher', 'pass' => 90, 'warn' => 75, 'metric' => 'changes approved within 5 days of submission (180 days)'],
        'CCM-OPS-CFG-026' => ['platform' => 'openDrift', 'unit' => 'events', 'better' => 'lower', 'pass' => 0, 'warn' => 2, 'metric' => 'open, unauthorised high or critical configuration drift'],
        'CCM-DPR-DLP-027' => ['unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 85, 'metric' => 'mail flows covered by DLP rules'],
        'CCM-DPR-MASK-028' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'non-production copies with PII masked'],
        'CCM-END-AV-029' => ['unit' => '%', 'better' => 'higher', 'pass' => 98, 'warn' => 95, 'metric' => 'endpoints with a healthy AV agent'],
        'CCM-END-EDR-030' => ['unit' => '%', 'better' => 'higher', 'pass' => 98, 'warn' => 95, 'metric' => 'endpoints with a healthy EDR agent'],
        'CCM-AWR-TRAIN-031' => ['unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 85, 'metric' => 'staff who completed mandatory awareness training'],
        'CCM-AWR-PHISH-032' => ['unit' => '%', 'better' => 'lower', 'pass' => 5, 'warn' => 10, 'metric' => 'phishing-simulation click rate'],
        'CCM-PHY-ACC-033' => ['platform' => 'physicalAccessReview', 'unit' => 'days', 'better' => 'lower', 'pass' => 92, 'warn' => 120, 'metric' => 'days since a data-centre access review was completed'],
        'CCM-PHY-CCTV-034' => ['unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 95, 'metric' => 'critical rooms under recorded CCTV'],
        'CCM-REG-MAP-035' => ['unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 85, 'metric' => 'obligations mapped to controls'],
        'CCM-REG-CIR-036' => ['platform' => 'circularActions', 'unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 80, 'metric' => 'applicable CBN circulars actioned by their due date'],
        'CCM-REG-RET-037' => ['platform' => 'returnsOverdue', 'unit' => 'returns', 'better' => 'lower', 'pass' => 0, 'warn' => 0, 'metric' => 'regulatory returns past due and not signed'],
        'CCM-RES-DR-038' => ['platform' => 'drExercises', 'unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 75, 'metric' => 'DR runbooks exercised in the last quarter'],
        'CCM-RES-BCP-039' => ['platform' => 'biaRefresh', 'unit' => '%', 'better' => 'higher', 'pass' => 100, 'warn' => 80, 'metric' => 'BIAs approved in the last 12 months'],
        'CCM-RES-COMM-040' => ['unit' => 'days', 'better' => 'lower', 'pass' => 365, 'warn' => 400, 'metric' => 'days since the crisis-communications runbook was tested'],
    ];

    /** A test the catalogue does not know: measured as a sandbox percentage. */
    private const FALLBACK = ['unit' => '%', 'better' => 'higher', 'pass' => 95, 'warn' => 85, 'metric' => 'compliant items'];

    public static function definition(string $code, ?array $overrides = null): array
    {
        $def = (self::CHECKS[$code] ?? self::FALLBACK) + ['platform' => null];
        foreach (['pass', 'warn'] as $k) {
            if (isset($overrides[$k]) && is_numeric($overrides[$k])) {
                $def[$k] = (float) $overrides[$k];
            }
        }

        return $def;
    }

    /** pass / warn / fail for a value against a definition. */
    public static function grade(array $def, float $value): string
    {
        if ($def['better'] === 'higher') {
            return $value >= $def['pass'] ? 'pass' : ($value >= $def['warn'] ? 'warn' : 'fail');
        }

        return $value <= $def['pass'] ? 'pass' : ($value <= $def['warn'] ? 'warn' : 'fail');
    }

    public static function thresholdLabel(array $def): string
    {
        $op = $def['better'] === 'higher' ? '≥' : '≤';

        return "pass {$op} ".self::num($def['pass']).", warn {$op} ".self::num($def['warn']).' '.$def['unit'];
    }

    public static function num(float|int $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    }

    /**
     * Measure a check for a bank: ['value' => float, 'findings' => string[], 'mode' => platform|sandbox].
     * `$seedDay` varies sandbox readings for demo history; live runs use today.
     */
    public function measure(string $code, int $orgId, ?string $seedDay = null): array
    {
        $def = self::definition($code);
        if ($def['platform']) {
            [$value, $findings] = $this->{$def['platform']}($orgId);

            return ['value' => round((float) $value, 2), 'findings' => array_slice($findings, 0, 10), 'mode' => 'platform'];
        }

        return $this->sandbox($code, $def, $orgId, $seedDay);
    }

    /**
     * Deterministic sandbox reading: the band comes from the bank and the test (so a bank's console
     * is stable between runs), with a small day-to-day wobble inside or across the warn band.
     */
    private function sandbox(string $code, array $def, int $orgId, ?string $seedDay): array
    {
        $h = crc32("{$orgId}|{$code}") % 100;
        $wobble = crc32("{$orgId}|{$code}|".($seedDay ?? today()->toDateString())) % 100;
        $band = $h < 72 ? 'pass' : ($h < 93 ? 'warn' : 'fail');
        if ($band === 'pass' && $wobble < 6) {
            $band = 'warn';
        } elseif ($band === 'warn' && $wobble < 25) {
            $band = 'pass';
        }

        $pass = (float) $def['pass'];
        $warn = (float) $def['warn'];
        $unitStep = in_array($def['unit'], ['%', 'hours'], true) ? 0.1 : 1;
        $value = match (true) {
            $def['unit'] === 'appointed' => $band === 'pass' ? 1 : 0,
            $def['better'] === 'higher' && $band === 'pass' => $pass >= 100 ? 100 : $pass + ($wobble % 4) * $unitStep * 5,
            $def['better'] === 'higher' && $band === 'warn' => $warn + (($pass - $warn) * ($wobble % 80) / 100),
            $def['better'] === 'higher' => max(0, $warn - 2 - ($h % 12)),
            $band === 'pass' => max(0, $pass - ($wobble % 5) * max($unitStep, $pass / 20)),
            $band === 'warn' => $pass + max($unitStep, ($warn - $pass) * (($wobble % 80) + 10) / 100),
            default => $warn + max(1, ($warn - $pass) / 2 + $h % 7),
        };
        $value = min($def['unit'] === '%' ? 100 : PHP_INT_MAX, round($value, $unitStep < 1 ? 1 : 0));
        $grade = self::grade($def, $value);

        $findings = $grade === 'pass' ? [] : [match ($def['unit']) {
            '%' => self::num(100 - $value).'% of '.$def['metric'].' are out of policy',
            'appointed' => 'No DPCO appointment on file',
            default => self::num($value).' '.$def['unit'].' — '.$def['metric'],
        }];

        return ['value' => $value, 'findings' => $findings, 'mode' => 'sandbox'];
    }

    /* ----------------------------- platform measures ----------------------------- */

    private function policyReviews(int $orgId): array
    {
        $active = DB::table('policies')->where('organization_id', $orgId)->where('status', 'active')->whereNull('deleted_at')->get(['policy_code', 'title', 'review_date']);
        $late = $active->filter(fn ($p) => $p->review_date && $p->review_date < today()->toDateString());

        return [$active->count() ? 100 * ($active->count() - $late->count()) / $active->count() : 100,
            $late->map(fn ($p) => "{$p->policy_code} {$p->title}: review was due {$p->review_date}")->values()->all()];
    }

    private function attestationCoverage(int $orgId): array
    {
        $rows = DB::table('policy_attestations')->where('organization_id', $orgId)->whereIn('status', ['pending', 'completed'])->get(['status', 'due_date']);
        $overdue = $rows->where('status', 'pending')->filter(fn ($r) => $r->due_date && $r->due_date < today()->toDateString())->count();

        return [$rows->count() ? 100 * $rows->where('status', 'completed')->count() / $rows->count() : 100,
            $overdue ? ["{$overdue} attestations are past their due date"] : []];
    }

    private function drExercises(int $orgId): array
    {
        $runbooks = DB::table('dr_runbooks')->where('organization_id', $orgId)->get(['id', 'name']);
        $recent = DB::table('dr_exercises')->where('organization_id', $orgId)->whereNotNull('rto_achieved_min')
            ->where('started_at', '>=', now()->subDays(92))->pluck('runbook_id')->unique();
        $missing = $runbooks->whereNotIn('id', $recent->all());

        return [$runbooks->count() ? 100 * ($runbooks->count() - $missing->count()) / $runbooks->count() : 100,
            $missing->map(fn ($r) => "{$r->name}: no exercise in the last quarter")->values()->all()];
    }

    private function nipFailover(int $orgId): array
    {
        $ids = DB::table('dr_runbooks')->where('organization_id', $orgId)->where('name', 'like', '%NIP%')->pluck('id');
        $last = DB::table('dr_exercises')->whereIn('runbook_id', $ids)->where('result', 'pass')->max('started_at');
        if (! $last) {
            return [999, ['No NIP failover exercise has recovered within its RTO']];
        }
        $days = (int) floor(now()->parse($last)->diffInDays(now()));

        return [$days, $days > 92 ? ["Last NIP failover within RTO was {$days} days ago"] : []];
    }

    private function biaRefresh(int $orgId): array
    {
        $rows = DB::table('bia_records')->where('organization_id', $orgId)->get(['process_name', 'approved_at']);
        $stale = $rows->filter(fn ($r) => ! $r->approved_at || $r->approved_at < now()->subMonths(12)->toDateTimeString());

        return [$rows->count() ? 100 * ($rows->count() - $stale->count()) / $rows->count() : 100,
            $stale->map(fn ($r) => "{$r->process_name}: ".($r->approved_at ? 'approved over 12 months ago' : 'not approved'))->values()->all()];
    }

    private function criticalVulnerabilities(int $orgId): array
    {
        $rows = DB::table('vulnerabilities')->where('organization_id', $orgId)->whereNull('deleted_at')
            ->where('severity', 'critical')->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->get(['vuln_id_code', 'title', 'due_date']);

        return [$rows->count(), $rows->map(fn ($v) => "{$v->vuln_id_code} {$v->title} (due {$v->due_date})")->all()];
    }

    private function meanTimeToResolve(int $orgId): array
    {
        $rows = DB::table('incidents')->where('organization_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('detected_at')->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(180))->get(['incident_id_code', 'detected_at', 'resolved_at']);
        if ($rows->isEmpty()) {
            return [0, []];
        }
        $hours = $rows->map(fn ($i) => max(0, now()->parse($i->detected_at)->diffInMinutes(now()->parse($i->resolved_at)) / 60));
        $slow = $rows->zip($hours)->filter(fn ($p) => $p[1] > 72)->map(fn ($p) => "{$p[0]->incident_id_code}: ".self::num($p[1]).'h to resolve');

        return [$hours->avg(), $slow->values()->all()];
    }

    private function changeApprovals(int $orgId): array
    {
        $rows = DB::table('change_requests')->where('organization_id', $orgId)->whereNull('deleted_at')
            ->whereNotNull('submitted_at')->whereNotNull('approved_at')->where('submitted_at', '>=', now()->subDays(180))->get(['change_code', 'submitted_at', 'approved_at']);
        $slow = $rows->filter(fn ($c) => now()->parse($c->submitted_at)->diffInDays(now()->parse($c->approved_at)) > 5);

        return [$rows->count() ? 100 * ($rows->count() - $slow->count()) / $rows->count() : 100,
            $slow->map(fn ($c) => "{$c->change_code}: approved ".(int) now()->parse($c->submitted_at)->diffInDays(now()->parse($c->approved_at)).' days after submission')->values()->all()];
    }

    private function openDrift(int $orgId): array
    {
        $rows = DB::table('config_drift_events')->where('organization_id', $orgId)->whereIn('status', ['open', 'investigating'])
            ->whereIn('severity', ['critical', 'high'])->get(['drift_code', 'title']);

        return [$rows->count(), $rows->map(fn ($d) => "{$d->drift_code} {$d->title}")->all()];
    }

    private function vendorAssessments(int $orgId): array
    {
        $vendors = DB::table('vendors')->where('organization_id', $orgId)->where('tier', 1)->where('status', '!=', 'terminated')->get(['id', 'name']);
        $assessed = DB::table('vendor_assessments')->where('organization_id', $orgId)->where('status', 'completed')
            ->where(fn ($q) => $q->where('completed_at', '>=', now()->subMonths(12))->orWhere('assessment_date', '>=', now()->subMonths(12)->toDateString()))
            ->pluck('vendor_id')->unique();
        $missing = $vendors->whereNotIn('id', $assessed->all());

        return [$vendors->count() ? 100 * ($vendors->count() - $missing->count()) / $vendors->count() : 100,
            $missing->map(fn ($v) => "{$v->name}: no completed assessment in 12 months")->values()->all()];
    }

    private function vendorRatings(int $orgId): array
    {
        $floor = (int) config('tprm.grades.C', 70);
        $vendors = DB::table('vendors')->where('organization_id', $orgId)->whereIn('tier', [1, 2])->pluck('name', 'id');
        $low = DB::table('tprm_security_ratings')->where('organization_id', $orgId)->whereIn('vendor_id', $vendors->keys())
            ->orderByDesc('captured_at')->get(['vendor_id', 'rating_value'])->unique('vendor_id')
            ->filter(fn ($r) => $r->rating_value < $floor);

        return [$low->count(), $low->map(fn ($r) => ($vendors[$r->vendor_id] ?? 'Vendor')." rated {$r->rating_value}")->values()->all()];
    }

    private function circularActions(int $orgId): array
    {
        $rows = DB::table('circular_assessments')->where('organization_id', $orgId)->whereIn('status', ['assessed', 'actioned'])
            ->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->get(['circular_id', 'status', 'due_date', 'actioned_at']);
        $late = $rows->filter(fn ($r) => ! $r->actioned_at || substr($r->actioned_at, 0, 10) > $r->due_date);

        return [$rows->count() ? 100 * ($rows->count() - $late->count()) / $rows->count() : 100,
            $late->map(fn ($r) => "Circular #{$r->circular_id}: action due {$r->due_date}".($r->actioned_at ? ', actioned late' : ', not actioned'))->values()->all()];
    }

    private function returnsOverdue(int $orgId): array
    {
        $rows = DB::table('regulatory_returns')->where('organization_id', $orgId)->whereNull('signed_at')
            ->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->get(['name', 'period', 'due_date']);

        return [$rows->count(), $rows->map(fn ($r) => "{$r->name} {$r->period}: due {$r->due_date}, not signed")->all()];
    }

    private function physicalAccessReview(int $orgId): array
    {
        $last = DB::table('access_review_campaigns')->where('organization_id', $orgId)->where('category', 'physical')
            ->where('status', 'completed')->max('completed_at');
        if (! $last) {
            return [999, ['No data-centre access review has been completed']];
        }
        $days = (int) floor(now()->parse($last)->diffInDays(now()));

        return [$days, $days > 92 ? ["Last data-centre access review completed {$days} days ago"] : []];
    }

    private function privilegedRevocations(int $orgId): array
    {
        $rows = DB::table('access_review_items')->where('organization_id', $orgId)->where('privileged', true)
            ->whereIn('decision', ['revoke', 'modify'])->whereNull('revocation_confirmed_at')
            ->where('decided_at', '<', now()->subDays(7))->get(['account_name', 'entitlement']);

        return [$rows->count(), $rows->map(fn ($r) => "{$r->account_name}: {$r->entitlement} still to be removed")->all()];
    }
}
