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
use App\Models\PolicyException;
use App\Services\Change\ChangeWorkflow;
use App\Services\Ea\ArchiMateExchange;
use App\Services\Issues\IssueWorkflow;
use App\Services\Kri\KriService;
use App\Services\Monitoring\CcmRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

/* =====================  KRIs ===================== */
// Measure every platform-sourced KRI from the bank's records each Monday (ATH-VAL-014).
Artisan::command('kri:measure', function (KriService $kris) {
    $this->info($kris->measureAll().' KRI(s) measured.');
})->purpose('Measure KRIs sourced from platform data');
Schedule::command('kri:measure')->weeklyOn(1, '06:00')->name('kri.measure')->withoutOverlapping();
