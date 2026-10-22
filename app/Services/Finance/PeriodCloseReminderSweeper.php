<?php

namespace App\Services\Finance;

use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Period;
use App\Models\User;
use App\Notifications\Finance\PeriodCloseReminderNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Daily period-close reminders (fin:period-reminders, plan WP-C): five working days before the end, and three working days after it while still open. */
class PeriodCloseReminderSweeper
{
    public function __construct(private readonly ContractService $contracts, private readonly ContractSweeper $sweeper, private readonly PeriodCloseService $closes) {}

    public function sweep(?CarbonInterface $today = null): int
    {
        $today = ($today ?? FinanceClock::today())->startOfDay();
        $sent = 0;
        $orgIds = FiscalYear::withoutGlobalScopes()->where('status', 'open')->whereNull('deleted_at')->distinct()->orderBy('organization_id')->pluck('organization_id');
        foreach ($orgIds as $orgId) {
            try {
                $sent += $this->sweepOrg((int) $orgId, $today);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function sweepOrg(int $orgId, CarbonInterface $today): int
    {
        $dueDays = (int) (array_values((array) config('finance.monitoring.close_reminder_working_days', [5]))[0] ?? 5);
        $overdueDays = (int) (array_values((array) config('finance.monitoring.close_overdue_working_days', [3]))[0] ?? 3);
        $fyIds = FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'open')->whereNull('deleted_at')->pluck('id');
        $periods = Period::withoutGlobalScopes()->with('fiscalYear:id,year_label')->where('organization_id', $orgId)->whereIn('fiscal_year_id', $fyIds)
            ->where('is_adjustment', false)->where('status', 'open')->orderBy('fiscal_year_id')->orderBy('sequence')->get();
        $finance = $this->contracts->financeUsers($orgId);
        $sent = 0;
        foreach ($periods as $p) {
            $ends = CarbonImmutable::parse($p->ends_on->toDateString(), 'UTC');
            if ($ends->toDateString() < $today->toDateString()) {
                $n = self::workingDaysBetween($ends, $today);
                [$kind, $key, $offset] = $n >= $overdueDays ? ['overdue', 'close_overdue', -$overdueDays] : [null, null, null];
            } else {
                $n = self::workingDaysBetween($today, $ends);
                [$kind, $key, $offset] = $n <= $dueDays ? ['due', 'close_due', $dueDays] : [null, null, null];
            }
            if ($kind === null) {
                continue;
            }
            $users = $this->sweeper->claim($p, $key, $offset, $finance->merge($this->owners($orgId, (int) $p->fiscal_year_id))->filter(fn ($u) => $u->is_active)->unique('id')->values());
            if ($users->isEmpty()) {
                continue;
            }
            Notification::send($users, new PeriodCloseReminderNotification(
                trim(($p->fiscalYear?->year_label ?? '').' '.$p->name), $kind, $n, self::openItems($this->closes->checklist($p)),
                route('finance.monitor.period-close', ['fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id])));
            $sent++;
        }

        return $sent;
    }

    /** Mon-Fri days after $from up to and including $to; no holiday calendar (plan C121). */
    public static function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $n = 0;
        $end = $to->toDateString();
        for ($d = CarbonImmutable::parse($from->toDateString(), 'UTC')->addDay(); $d->toDateString() <= $end; $d = $d->addDay()) {
            $d->isWeekday() && $n++;
        }

        return $n;
    }

    /** Labels and counts of the failing checklist items. @return list<array{label:string,count:int}> */
    public static function openItems(array $checklist): array
    {
        return array_values(array_map(fn ($i) => ['label' => $i['label'], 'count' => (int) $i['count']], array_filter($checklist['items'], fn ($i) => $i['status'] === 'fail')));
    }

    private function owners(int $orgId, int $fyId)
    {
        $budgets = Budget::withoutGlobalScopes()->where('organization_id', $orgId)->where('fiscal_year_id', $fyId)->where('status', 'baseline')->select('id');
        $ccIds = BudgetLine::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('budget_id', $budgets)->distinct()->pluck('cost_centre_id');
        $ownerIds = CostCentre::withoutGlobalScopes()->whereIn('id', $ccIds)->whereNotNull('owner_id')->pluck('owner_id');

        return User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->whereIn('id', $ownerIds)->get();
    }
}
