<?php

use App\Jobs\Ea\ExpireExceptions;
use App\Jobs\Ea\ExpireQualitySeals;
use App\Jobs\Ea\RecomputeKris;
use App\Jobs\Ea\RecomputeTechObsolescence;
use App\Jobs\Ea\RecomputeVendorConcentration;
use App\Jobs\Ea\RunAnomalyRules;
use App\Jobs\Ea\RunSurveyCampaigns;
use App\Jobs\Ea\SyncAssetCatalogue;
use App\Jobs\Ea\SyncCveFeed;
use App\Jobs\Ea\SyncEolFeed;
use App\Models\CoreBankingIntegration;
use App\Models\PolicyException;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Services\Change\ChangeWorkflow;
use App\Services\CoreBanking\CoreBankingConnector;
use App\Services\Ea\ArchiMateExchange;
use App\Services\Finance\ContractSweeper;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceApprovalSweeper;
use App\Services\Finance\FinanceDigest;
use App\Services\Finance\InvoiceSweeper;
use App\Services\Finance\PeriodCloseReminderSweeper;
use App\Services\Finance\RecurringExpenseService;
use App\Services\Finance\ValueReviewSweeper;
use App\Services\Finance\VarianceService;
use App\Services\Issues\IssueWorkflow;
use App\Services\Kri\KriService;
use App\Services\Monitoring\CcmRunner;
use App\Services\Reports\ReportFigures;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/* =====================  EA Studio scheduled jobs ===================== */
Schedule::job(new RecomputeTechObsolescence)->dailyAt('01:30')->name('ea.recompute-obsolescence');
Schedule::job(new ExpireExceptions)->dailyAt('02:00')->name('ea.expire-exceptions');
Schedule::job(new RunAnomalyRules)->dailyAt('02:30')->name('ea.run-anomalies');
Schedule::job(new RecomputeKris)->dailyAt('03:00')->name('ea.recompute-kris');
Schedule::job(new RecomputeVendorConcentration)->dailyAt('03:30')->name('ea.vendor-concentration');
Schedule::job(new SyncEolFeed)->weekly()->mondays()->at('04:00')->name('ea.sync-eol');
Schedule::job(new SyncCveFeed)->dailyAt('04:30')->name('ea.sync-cve');
Schedule::job(new SyncAssetCatalogue)->hourly()->name('ea.sync-assets');

/* ---- Phase 1 stewardship (ATH-EAR-002 WS 1.2 / 1.3) ----
 | The two jobs that make the repository maintain itself: the quality seal
 | expires on its renewal interval whether or not anything changed, and the
 | survey engine launches recurring campaigns, chases non-respondents and
 | closes windows. Without a clock behind them both mechanics are inert. */
Schedule::job(new ExpireQualitySeals)->dailyAt('05:00')->name('ea.expire-seals');
Schedule::job(new RunSurveyCampaigns)->dailyAt('06:00')->name('ea.survey-campaigns');

/* ---- Regulatory Intelligence ----
 | New circulars land as drafts and reach tenants only after Content-Ops publishes them. */
Schedule::command('atheris:crawl-regulators --since=2')->dailyAt('05:30')->name('reg-intel.crawl');

/* =====================  EA convenience artisan commands ===================== */
Artisan::command('ea:recompute-all', function () {
    dispatch_sync(new RecomputeTechObsolescence);
    dispatch_sync(new ExpireExceptions);
    dispatch_sync(new RunAnomalyRules);
    dispatch_sync(new RecomputeKris);
    dispatch_sync(new RecomputeVendorConcentration);
    $this->info('All EA recompute jobs executed.');
})->purpose('Run every EA recompute job once');

Artisan::command('ea:export-archimate {path?}', function (?string $path = null) {
    $rel = $path ?? 'exchange/ea-cli-export-'.now()->format('YmdHis').'.xml';
    $svc = new ArchiMateExchange;
    $r = $svc->exportToDisk($rel);
    $this->info("Exported {$r['element_count']} elements + {$r['relationship_count']} relationships to {$rel}. SHA-256: ".substr($r['sha256'], 0, 16).'…');
})->purpose('Export the EA repository to ArchiMate Open Exchange XML');

/* =====================  Issues & Remediation ===================== */
// SLA breach → level 1 (CISO by default); 150% of the SLA window → level 2 (CRO).
Artisan::command('issues:escalate', function (IssueWorkflow $workflow) {
    $this->info($workflow->escalateOverdue().' escalation(s) raised.');
})->purpose('Escalate open issues whose remediation SLA has been breached');
Schedule::command('issues:escalate')->hourly()->name('issues.escalate')->withoutOverlapping();

/* =====================  Change Management ===================== */
// Overdue approval reminders to the current approvers and the change manager, once per 24 h.
Artisan::command('changes:sweep', function (ChangeWorkflow $workflow) {
    $this->info($workflow->sweepOverdueApprovals().' overdue approval reminder(s) sent.');
})->purpose('Remind approvers of overdue change approvals');
Schedule::command('changes:sweep')->hourly()->name('changes.sweep')->withoutOverlapping();

/* =====================  IT Financial Management ===================== */
Artisan::command('fin:approvals-sweep', function (FinanceApprovalSweeper $s) {
    $this->info($s->sweep().' overdue finance approval reminder(s) sent.');
})->purpose('Remind approvers of overdue IT finance approvals (ATH-FIN-001 §7)');
Schedule::command('fin:approvals-sweep')->hourly()->name('finance.approvals-sweep')->withoutOverlapping();
Artisan::command('fin:doa-activate', function (DoaBandService $d) {
    $this->info($d->activateDue().' DoA band version(s) activated.');
})->purpose('Activate approved DoA band versions whose effective date has arrived');
Schedule::command('fin:doa-activate')->dailyAt('00:05')->name('finance.doa-activate')->withoutOverlapping();
Artisan::command('fin:contracts-sweep {--date= : Y-m-d, run as if today (demo/testing)}', function (ContractSweeper $s) {
    $r = $s->sweep($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone'))->startOfDay() : null);
    $this->info(sprintf('%d derived, %d rolled, %d T-minus sent, %d escalated, %d passed notices, %d approved-not-executed alerts.', $r['derived'], $r['rolled'], $r['notified'], $r['escalated'], $r['passed'], $r['unexecuted']));
})->purpose('Derive contract statuses, auto-renew, send renewal T-minus, escalation and passed notices (ATH-FIN-001 §5.4)');
Schedule::command('fin:contracts-sweep')->dailyAt('06:30')->timezone(config('finance.timezone'))->name('finance.contracts-sweep')->withoutOverlapping();
Artisan::command('fin:digest', function (FinanceDigest $d) {
    $this->info($d->send().' finance digest(s) sent.');
})->purpose("Send the IT finance daily digest to opted-in users at their bank's digest hour");
Artisan::command('fin:recurring-generate {--date= : Y-m-d, run as if today}', function (RecurringExpenseService $s) {
    $this->info($s->generateDue($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone'))->startOfDay() : null).' generated');
})->purpose('Generate recurring expected invoices and expense requests that are due (ATH-FIN-001 §5.4)');
Schedule::command('fin:recurring-generate')->dailyAt('00:30')->timezone(config('finance.timezone'))->name('finance.recurring-generate')->withoutOverlapping();
Artisan::command('fin:invoices-sweep {--date= : Y-m-d, run as if today}', function (InvoiceSweeper $s) {
    $this->info($s->sweep($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone'))->startOfDay() : null).' invoice notice(s) sent.');
})->purpose('Send invoice due and overdue notices (ATH-FIN-001 §7)');
Schedule::command('fin:invoices-sweep')->dailyAt('07:30')->timezone(config('finance.timezone'))->name('finance.invoices-sweep')->withoutOverlapping();
Artisan::command('fin:variances-sweep {--date= : Y-m-d, run as if today}', function (VarianceService $v) {
    $this->info($v->evaluateDue($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone'))->startOfDay() : null).' period(s) evaluated');
})->purpose('Re-evaluate the budget variances of ended, unlocked periods and notify new or escalated breaches (ATH-FIN-001 §7)');
Schedule::command('fin:variances-sweep')->dailyAt('07:00')->timezone(config('finance.timezone'))->name('finance.variances-sweep')->withoutOverlapping();
Artisan::command('fin:period-reminders {--date= : Y-m-d, run as if today}', function (PeriodCloseReminderSweeper $s) {
    $this->info($s->sweep($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone'))->startOfDay() : null).' period-close reminder(s) sent.');
})->purpose('Remind Finance and cost-centre owners that a period is about to close or is overdue (ATH-FIN-001 §7)');
Schedule::command('fin:period-reminders')->dailyAt('08:00')->timezone(config('finance.timezone'))->name('finance.period-reminders')->withoutOverlapping();
Artisan::command('fin:value-reviews-sweep {--date= : Y-m-d, run as if today}', function (ValueReviewSweeper $s) {
    $this->info($s->sweep($this->option('date') ? CarbonImmutable::parse($this->option('date'), config('finance.timezone'))->startOfDay() : null).' value-review notification(s) sent.');
})->purpose('Mark value reviews due or overdue and notify the reviewer and sponsor (ATH-FIN-001 §7)');
Schedule::command('fin:value-reviews-sweep')->dailyAt('07:45')->timezone(config('finance.timezone'))->name('finance.value-reviews-sweep')->withoutOverlapping();
Schedule::command('fin:digest')->hourly()->name('finance.digest')->withoutOverlapping();

/* =====================  Policy Management ===================== */
// Approved policy exceptions past their end date become expired (ATH-VAL-009).
Artisan::command('policies:sweep', function () {
    $expired = PolicyException::withoutGlobalScopes()->where('status', 'approved')
        ->whereDate('expires_on', '<', today())->update(['status' => 'expired', 'updated_at' => now()]);
    $this->info("{$expired} policy exception(s) expired.");
})->purpose('Expire approved policy exceptions past their end date');
Schedule::command('policies:sweep')->dailyAt('00:15')->name('policies.sweep')->withoutOverlapping();

/* =====================  Continuous Monitoring ===================== */
// Run every enabled CCM test whose cron schedule is due (ATH-VAL-013).
Artisan::command('ccm:run-due', function (CcmRunner $runner) {
    $this->info($runner->runDue().' CCM test(s) run.');
})->purpose('Run CCM tests that are due');
Schedule::command('ccm:run-due')->everyFifteenMinutes()->name('ccm.run-due')->withoutOverlapping();

/* =====================  Core Banking ===================== */
// Daily technical-metadata sync of every connected adapter, per bank (ATH-VAL-017).
Artisan::command('core-banking:sync', function (CoreBankingConnector $connector) {
    $n = 0;
    foreach (CoreBankingIntegration::withoutGlobalScopes()->where('status', 'connected')->orderBy('id')->get() as $i) {
        try {
            $connector->sync($i);
            $n++;
        } catch (ValidationException) {
            // recorded on the integration as a failed sync
        }
    }
    $this->info("{$n} integration(s) synced.");
})->purpose('Sync connected core banking and switch adapters');
Schedule::command('core-banking:sync')->dailyAt('01:30')->name('core-banking.sync')->withoutOverlapping();

/* =====================  KRIs ===================== */
// Measure every platform-sourced KRI from the bank's records each Monday (ATH-VAL-014).
Artisan::command('kri:measure', function (KriService $kris) {
    $this->info($kris->measureAll().' KRI(s) measured.');
})->purpose('Measure KRIs sourced from platform data');
Schedule::command('kri:measure')->weeklyOn(1, '06:00')->name('kri.measure')->withoutOverlapping();

/* =====================  Scheduled reports (ATH-VAL-018) ===================== */
// Records a run (key figures snapshot) for every due schedule. No delivery is configured.
Artisan::command('reports:run-due', function () {
    $n = 0;
    $due = ScheduledReport::withoutGlobalScopes()->where('active', true)
        ->where('next_run_at', '<=', now())->orderBy('id')->get();
    foreach ($due as $schedule) {
        $isFinance = $schedule->report_type === 'finance' || str_starts_with((string) $schedule->report_type, 'finance.');
        $query = User::withoutGlobalScopes()->where('organization_id', $schedule->organization_id);
        // Finance reports run as the user who scheduled them, so their cost-centre and amount visibility apply.
        $user = $isFinance ? (clone $query)->whereKey($schedule->created_by)->first() : $query->orderBy('id')->first();
        if (! $user) {
            $isFinance && ScheduledReportRun::create([
                'organization_id' => $schedule->organization_id, 'scheduled_report_id' => $schedule->id, 'ran_at' => now(), 'status' => 'failed',
                'summary' => ['error' => 'The user who scheduled this report no longer exists.'],
            ]);
            $isFinance && $schedule->update(['next_run_at' => ScheduledReport::advance($schedule->frequency)]);

            continue;
        }
        auth()->setUser($user); // report figures are tenant-scoped through the signed-in user
        try {
            if ($isFinance && ! ($user->can('view it-finance') && $user->can('export it-finance'))) {
                throw new RuntimeException('The user who scheduled this report no longer holds the IT finance view and export permissions.');
            }
            $summary = ReportFigures::summary($schedule->report_type, (int) $schedule->organization_id);
            ScheduledReportRun::create([
                'organization_id' => $schedule->organization_id, 'scheduled_report_id' => $schedule->id,
                'ran_at' => now(), 'status' => 'recorded', 'summary' => $summary,
            ]);
            $schedule->update(['last_run_at' => now(), 'next_run_at' => ScheduledReport::advance($schedule->frequency)]);
            $n++;
        } catch (Throwable $e) {
            report($e);
            ScheduledReportRun::create([
                'organization_id' => $schedule->organization_id, 'scheduled_report_id' => $schedule->id, 'ran_at' => now(), 'status' => 'failed',
                'summary' => ['error' => $e instanceof RuntimeException ? $e->getMessage() : 'The report could not be generated.'],
            ]);
            $schedule->update(['next_run_at' => ScheduledReport::advance($schedule->frequency)]);
        } finally {
            auth()->forgetUser();
        }
    }
    $this->info("{$n} scheduled report(s) recorded.");
})->purpose('Record due scheduled report runs');
Schedule::command('reports:run-due')->daily()->name('reports.run-due')->withoutOverlapping();
