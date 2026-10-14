<?php

namespace App\Services\Monitoring;

use App\Models\CcmTenantTest;
use App\Models\CcmTestRun;
use App\Models\Issue;
use App\Models\User;
use App\Services\Issues\IssueWorkflow;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;

/**
 * Runs a bank's CCM tests (ATH-VAL-013).
 *
 * A run measures the check (CcmChecks), grades it against the bank's thresholds, records the run
 * with its value, threshold, findings and mode, and schedules the next run from the test's cron.
 * When the test is linked to a control the control's last-tested date moves to the run date.
 *
 * A failure raises one issue per test (source "ccm_test") unless an open one already exists; a
 * later pass notes on that issue that the test passes again, but does not close it: closing is
 * the issue owner's remediation and a second person's verification (module 6).
 */
class CcmRunner
{
    private const ISSUE_SEVERITY = ['critical' => 'critical', 'high' => 'high', 'moderate' => 'moderate', 'low' => 'low'];

    public function __construct(private CcmChecks $checks, private IssueWorkflow $issues) {}

    public function run(CcmTenantTest $tt, ?User $actor = null, $at = null, bool $raiseIssues = true): CcmTestRun
    {
        $tt->loadMissing('test', 'control');
        $at = $at ? now()->parse($at) : now();
        $orgId = (int) $tt->organization_id;
        $def = CcmChecks::definition($tt->test->code, $tt->overrides);
        $started = microtime(true);
        $m = $this->checks->measure($tt->test->code, $orgId, $at->toDateString());
        $status = CcmChecks::grade($def, (float) $m['value']);

        $run = CcmTestRun::withoutGlobalScopes()->create([
            'organization_id' => $orgId,
            'tenant_test_id' => $tt->id,
            'status' => $status,
            'mode' => $m['mode'],
            'measured_value' => $m['value'],
            'threshold' => CcmChecks::thresholdLabel($def),
            'findings' => $m['findings'],
            'output_summary' => ucfirst($def['metric']).': '.CcmChecks::num($m['value']).' '.$def['unit'].' ('.$status.').',
            'latency_ms' => (int) round((microtime(true) - $started) * 1000) + ($m['mode'] === 'sandbox' ? 400 : 0),
            'ran_at' => $at,
            'triggered_by' => $actor?->id,
        ]);

        DB::table('ccm_tenant_tests')->where('id', $tt->id)->update([
            'last_status' => $status,
            'last_value' => CcmChecks::num($m['value']).' '.$def['unit'],
            'last_run_at' => $at,
            'next_run_at' => $this->nextRun($tt->test->cadence_cron, $at),
            'updated_at' => now(),
        ]);
        if ($tt->control_id) {
            DB::table('controls')->where('id', $tt->control_id)->where(fn ($q) => $q->whereNull('last_tested')->orWhere('last_tested', '<', $at->toDateString()))
                ->update(['last_tested' => $at->toDateString()]);
        }

        if ($raiseIssues) {
            $this->followUp($tt->fresh(['test', 'control']), $run, $actor);
        }

        return $run;
    }

    /** Run every enabled test that is due, across banks (the scheduler). */
    public function runDue(): int
    {
        $due = CcmTenantTest::withoutGlobalScopes()->with('test')->where('status', 'enabled')
            ->whereNotNull('organization_id')
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))->get();
        $due->each(fn ($tt) => $this->run($tt));

        return $due->count();
    }

    public function nextRun(?string $cron, $from)
    {
        try {
            return now()->instance((new CronExpression($cron ?: '0 2 * * *'))->getNextRunDate(now()->parse($from)->toDateTime()));
        } catch (\Throwable) {
            return now()->parse($from)->addDay();
        }
    }

    private function followUp(CcmTenantTest $tt, CcmTestRun $run, ?User $actor): void
    {
        if (! config('ccm.auto_issue', true)) {
            return;
        }
        $open = $tt->issue_id ? Issue::withoutGlobalScopes()->find($tt->issue_id) : null;
        $open = $open && $open->isOpen() ? $open : null;

        if ($run->status === 'fail' && ! $open) {
            $issue = $this->issues->open([
                'organization_id' => $tt->organization_id,
                'title' => "CCM failure: {$tt->test->title}",
                'description' => $run->output_summary.' Threshold: '.$run->threshold.'.'
                    .($run->findings ? "\n\nFindings:\n- ".implode("\n- ", $run->findings) : '')
                    .($run->mode === 'sandbox' ? "\n\nMeasured by the sandbox adapter ({$tt->test->adapter_key}); confirm against the live tool." : ''),
                'severity' => self::ISSUE_SEVERITY[$tt->test->default_severity] ?? 'moderate',
                'source_type' => 'ccm_test',
                'source_id' => $tt->id,
                'source_reference' => $tt->test->code,
                'owner_id' => $tt->owner_id ?? $tt->control?->owner_id,
            ], $actor);
            DB::table('ccm_tenant_tests')->where('id', $tt->id)->update(['issue_id' => $issue->id]);
        } elseif ($run->status === 'pass' && $open) {
            $this->issues->log($open, 'comment', "{$tt->test->code} passes again on ".$run->ran_at->format('d M Y').' ('.$tt->fresh()->last_value.'). Ready for remediation sign-off.', $actor);
        }
    }
}
