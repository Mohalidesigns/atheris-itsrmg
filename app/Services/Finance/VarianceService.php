<?php

namespace App\Services\Finance;

use App\Events\Finance\VarianceBreached;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\Finance\VarianceComment;
use App\Models\Finance\VarianceNote;
use App\Models\Issue;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Issues\IssueWorkflow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Variance analysis (FIN-04.03, .10, plan P4-1 to P4-4): one note per (line, period) measuring YTD actual against YTD budget,
 * flagged in both directions when the percentage AND the absolute floor are reached. Notes are explained by the owner and
 * acknowledged by someone else (segregation of duties); corrective actions are raised as system Issues.
 */
class VarianceService
{
    use LogsFinanceEvents;

    public const TRANSITIONS = [
        'explain' => ['from' => ['open', 'explained', 'acknowledged', 'cleared'], 'to' => 'explained'],
        'acknowledge' => ['from' => ['explained'], 'to' => 'acknowledged'],
    ];

    private const RANK = ['amber' => 1, 'red' => 2];

    public function __construct(private readonly FinanceSettings $settings) {}

    private function monitoring(): MonitoringService
    {
        return app(MonitoringService::class); // lazy: MonitoringService depends on this class
    }

    /** @return array{amber_pct:string,red_pct:string,abs_floor:string} */
    public function thresholds(int $orgId, ?BudgetLine $line = null): array
    {
        $s = $this->settings->for($orgId);

        return [
            'amber_pct' => bcadd((string) ($line?->variance_amber_pct ?? $s->variance_amber_pct ?? '5'), '0', 2),
            'red_pct' => bcadd((string) ($line?->variance_red_pct ?? $s->variance_red_pct ?? '10'), '0', 2),
            'abs_floor' => bcadd((string) ($s->variance_abs_floor ?? '0'), '0', 2),
        ];
    }

    /** @return array{severity:?string,direction:string,variance:string,pct:?string} */
    public static function classify(string $budget, string $actual, string $amberPct, string $redPct, string $floor): array
    {
        $variance = bcsub($actual, $budget, 2);
        $abs = ltrim($variance, '-');
        $direction = str_starts_with($variance, '-') ? 'under' : 'over';
        $floorReached = bccomp($abs, $floor, 2) >= 0;
        if (bccomp($budget, '0', 2) === 0) {
            $sev = bccomp($variance, '0', 2) !== 0 && $floorReached ? 'red' : null;

            return ['severity' => $sev, 'direction' => $direction, 'variance' => $variance, 'pct' => null];
        }
        $ratio = bcmul(bcdiv($variance, ltrim($budget, '-'), 10), '100', 10);
        $absPct = ltrim($ratio, '-');
        $sev = null;
        if ($floorReached) {
            $sev = bccomp($absPct, $redPct, 10) >= 0 ? 'red' : (bccomp($absPct, $amberPct, 10) >= 0 ? 'amber' : null);
        }

        return ['severity' => $sev, 'direction' => $direction, 'variance' => $variance, 'pct' => bcadd($ratio, '0', 2)];
    }

    /** @return array{created:int,updated:int,cleared:int,escalated:int} */
    public function evaluate(Period $p, ?User $by = null, bool $force = false): array
    {
        $orgId = (int) $p->organization_id;
        in_array($p->status, ['closed', 'locked'], true) && $this->fail("{$p->name} is {$p->status}; re-open it before its variances are evaluated again.");
        ! $force && $p->ends_on->toDateString() >= FinanceClock::today()->toDateString() && $this->fail("{$p->name} has not ended yet; its variances cannot be evaluated.");
        $events = [];
        $stats = ['created' => 0, 'updated' => 0, 'cleared' => 0, 'escalated' => 0];

        DB::transaction(function () use ($p, $orgId, &$events, &$stats) {
            $fyId = (int) $p->fiscal_year_id;
            $lines = $this->monitoring()->baselineLines($orgId, $fyId);
            if ($lines->isEmpty()) {
                return;
            }
            $figures = $this->monitoring()->lineFigures($lines, $fyId, null, (int) $p->sequence);
            $lineage = $this->monitoring()->lineage($orgId, $lines);
            $existing = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $p->id)->get()->keyBy('line_key');
            $settings = $this->thresholds($orgId);
            foreach ($lines as $l) {
                $key = (int) end($lineage[$l->id]);
                $fg = $figures[$l->id];
                $t = $this->thresholds($orgId, $l);
                $c = self::classify($fg['budget'], $fg['actual'], $t['amber_pct'], $t['red_pct'], $settings['abs_floor']);
                $periodVar = bcsub($fg['by_period'][$p->id]['actual'] ?? '0.00', $fg['by_period'][$p->id]['budget'] ?? '0.00', 2);
                $note = $existing->get($key);
                $amounts = ['budget_line_id' => $l->id, 'cost_centre_id' => $l->cost_centre_id, 'budget_amount' => $fg['budget'], 'actual_amount' => $fg['actual'],
                    'variance_amount' => $c['variance'], 'variance_pct' => $c['pct'], 'period_variance_amount' => $periodVar, 'direction' => $c['direction'], 'evaluated_at' => now()];
                if ($c['severity'] === null) {
                    if ($note) {
                        $wasBreaching = $note->is_breaching;
                        $note->forceFill($amounts + ['is_breaching' => false, 'status' => $note->status === 'open' ? 'cleared' : $note->status])->save();
                        $wasBreaching && $stats['cleared']++;
                    }

                    continue;
                }
                if (! $note) {
                    $n = VarianceNote::withoutGlobalScopes()->create($amounts + [
                        'organization_id' => $orgId, 'created_by_id' => null, 'fiscal_year_id' => $fyId, 'period_id' => $p->id, 'line_key' => $key, 'basis' => 'ytd',
                        'severity' => $c['severity'], 'peak_severity' => $c['severity'], 'is_breaching' => true, 'status' => 'open',
                    ]);
                    $events[] = [$n->id, $c['severity']];
                    $stats['created']++;

                    continue;
                }
                $prevPeak = $note->peak_severity;
                $peak = self::RANK[$c['severity']] > self::RANK[$note->peak_severity] ? $c['severity'] : $note->peak_severity;
                $status = $note->status === 'cleared' ? 'open' : $note->status;
                $reopen = $this->reopenReason($note, $c, $fg['budget'], $settings['abs_floor']);
                if ($reopen) {
                    $this->resetExplanation($note, $reopen);
                    $status = 'open';
                    $amounts += ['explanation' => null, 'author_id' => null, 'explained_at' => null, 'acknowledged_by_id' => null, 'acknowledged_at' => null];
                }
                $note->forceFill($amounts + ['severity' => $c['severity'], 'peak_severity' => $peak, 'is_breaching' => true, 'status' => $status])->save();
                $stats['updated']++;
                if ($peak !== $prevPeak) {
                    $events[] = [$note->id, $peak];
                    $stats['escalated']++;
                }
            }
        });
        foreach ($events as [$id, $sev]) {
            event(new VarianceBreached($id, $sev));
        }

        return $stats;
    }

    /**
     * H1: an explanation or acknowledgement no longer stands when the variance got worse (amber to red) or, after acknowledgement,
     * moved by more than `finance.monitoring.variance_reopen_delta_pct` of the budget. Returns the reason, or null to keep it.
     */
    private function reopenReason(VarianceNote $note, array $c, string $budget, string $floor): ?string
    {
        if (! in_array($note->status, ['explained', 'acknowledged'], true)) {
            return null;
        }
        if (self::RANK[$c['severity']] > (self::RANK[$note->severity] ?? 0)) {
            return "the variance escalated from {$note->severity} to {$c['severity']}";
        }
        if ($note->status !== 'acknowledged') {
            return null;
        }
        $moved = ltrim(bcsub($c['variance'], (string) $note->variance_amount, 2), '-');
        $pct = (string) config('finance.monitoring.variance_reopen_delta_pct', 10);
        $limit = bccomp($budget, '0', 2) === 0 ? $floor : bcdiv(bcmul(ltrim($budget, '-'), $pct, 6), '100', 2);

        return bccomp($moved, $limit, 2) > 0 && bccomp($moved, '0', 2) > 0 ? "the variance moved by {$moved} after it was acknowledged" : null;
    }

    /** The earlier explanation stays visible as a comment on the note; the note itself goes back to `open`. */
    private function resetExplanation(VarianceNote $note, string $why): void
    {
        $authorId = $note->author_id ?? $note->acknowledged_by_id ?? $note->created_by_id;
        if ($note->explanation && $authorId) {
            VarianceComment::withoutGlobalScopes()->create(['organization_id' => $note->organization_id, 'created_by_id' => null, 'variance_note_id' => $note->id, 'author_id' => $authorId,
                'body' => 'Earlier explanation (superseded because '.$why.'): '.$note->explanation.($note->acknowledged_at ? ' [acknowledged '.$note->acknowledged_at->toDateString().']' : '')]);
        }
        $this->audit($note, null, 'explanation_reset', 'Variance explanation reset: '.$why);
    }

    /** Nightly sweep: ended, still open, regular periods of open fiscal years of every bank. Returns the number of periods evaluated. */
    public function evaluateDue(?CarbonInterface $today = null): int
    {
        $d = ($today ?? FinanceClock::today())->toDateString();
        $n = 0;
        $orgs = FiscalYear::withoutGlobalScopes()->where('status', 'open')->whereNull('deleted_at')->distinct()->pluck('organization_id');
        foreach ($orgs as $orgId) {
            try {
                $open = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open')->whereNull('deleted_at')->pluck('id');
                $periods = Period::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('fiscal_year_id', $open)->where('is_adjustment', false)
                    ->whereDate('ends_on', '<', $d)->where('status', 'open')->orderBy('fiscal_year_id')->orderBy('sequence')->get();
                foreach ($periods as $p) {
                    $this->evaluate($p);
                    $n++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $n;
    }

    public function explain(VarianceNote $n, string $explanation, ?string $action, User $by): VarianceNote
    {
        $this->sameBank($n, $by);
        $this->assertPeriodOpen($n);
        $explanation = trim($explanation);
        mb_strlen($explanation) >= 20 || $this->fail('Explain the variance in at least 20 characters.', 'explanation');
        in_array($n->status, self::TRANSITIONS['explain']['from'], true) || $this->fail("A {$n->status} note cannot be explained.");
        $action = $action === null || trim($action) === '' ? null : trim($action);
        $n->forceFill([
            'explanation' => $explanation, 'corrective_action' => $action, 'status' => 'explained', 'author_id' => $by->id, 'explained_at' => now(),
            'acknowledged_by_id' => null, 'acknowledged_at' => null,
        ])->save();
        $this->audit($n, $by, 'explained', 'Variance explained: '.$n->label);

        return $n->refresh();
    }

    public function acknowledge(VarianceNote $n, User $by): VarianceNote
    {
        $this->sameBank($n, $by);
        $this->assertPeriodOpen($n);
        in_array($n->status, self::TRANSITIONS['acknowledge']['from'], true) || $this->fail("A {$n->status} note cannot be acknowledged.");
        $n->author_id && (int) $n->author_id === (int) $by->id && $this->fail('You wrote this explanation, so you cannot acknowledge it (segregation of duties).', 'approval');
        $n->forceFill(['status' => 'acknowledged', 'acknowledged_by_id' => $by->id, 'acknowledged_at' => now()])->save();
        $this->audit($n, $by, 'acknowledged', 'Variance explanation acknowledged: '.$n->label);

        return $n->refresh();
    }

    public function comment(VarianceNote $n, string $body, User $by): VarianceComment
    {
        $this->sameBank($n, $by);
        $this->assertPeriodOpen($n);
        $body = trim($body);
        ($body === '' || mb_strlen($body) > 2000) && $this->fail('Write a comment of up to 2000 characters.', 'body');
        $c = VarianceComment::withoutGlobalScopes()->create(['organization_id' => $n->organization_id, 'created_by_id' => $by->id, 'variance_note_id' => $n->id, 'author_id' => $by->id, 'body' => $body]);
        $this->audit($n, $by, 'commented', 'Comment added on '.$n->label);

        return $c;
    }

    /** Raises the corrective action as a system Issue (plan P4-12); refused while the note's issue is still open. */
    public function raiseIssue(VarianceNote $n, User $by, ?string $title = null): Issue
    {
        $this->sameBank($n, $by);
        $this->assertPeriodOpen($n);
        $existing = $n->issue_id ? Issue::withoutGlobalScopes()->find($n->issue_id) : null;
        $existing && $existing->isOpen() && $this->fail("An issue ({$existing->issue_code}) is already open for this variance.", 'issue');
        $n->loadMissing(['costCentre', 'line.budget']);
        $owner = $n->costCentre?->owner_id ?? $n->line?->budget?->owner_id ?? $by->id;
        $title = trim((string) $title) !== '' ? mb_substr(trim((string) $title), 0, 200) : mb_substr('Budget variance: '.$n->label, 0, 200);
        $issue = app(IssueWorkflow::class)->open([
            'organization_id' => $n->organization_id, 'title' => $title,
            'description' => trim("{$n->label}: {$n->severity} ".($n->direction === 'over' ? 'overspend' : 'underspend').' against the year-to-date budget. '
                .($n->corrective_action ? 'Corrective action: '.$n->corrective_action : 'See IT Finance, Budget Monitor, Variances.')),
            'severity' => $n->severity === 'red' ? 'high' : 'moderate', 'source_type' => 'finance_variance', 'source_id' => $n->id, 'source_reference' => $n->label,
            'owner_id' => $owner,
        ], $by);
        $n->forceFill(['issue_id' => $issue->id])->save();
        $this->audit($n, $by, 'issue_raised', "Issue {$issue->issue_code} raised for ".$n->label);

        return $issue;
    }

    /** Notes of the period that still need an explanation before it can close (plan P4-5). @return Collection<int, VarianceNote> */
    public function unexplained(Period $p): Collection
    {
        $sev = config('finance.monitoring.explain_amber_at_close') ? ['red', 'amber'] : ['red'];

        return VarianceNote::withoutGlobalScopes()->where('organization_id', $p->organization_id)->where('period_id', $p->id)->where('is_breaching', true)
            ->whereIn('status', VarianceNote::NEEDS_EXPLANATION)->whereIn('severity', $sev)->get();
    }

    /** A note of a closed or locked period is part of the closed record: the period must be re-opened first. */
    private function assertPeriodOpen(VarianceNote $n): void
    {
        $status = Period::withoutGlobalScopes()->whereKey($n->period_id)->value('status');
        in_array($status, ['closed', 'locked'], true) && $this->fail("The period of this variance is {$status}; re-open it before changing the note.");
    }

    private function sameBank(VarianceNote $n, User $by): void
    {
        (int) $by->organization_id === (int) $n->organization_id || $this->fail('This note belongs to another bank.');
    }
}
