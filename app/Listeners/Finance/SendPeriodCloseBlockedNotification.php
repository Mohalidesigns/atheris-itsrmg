<?php

namespace App\Listeners\Finance;

use App\Events\Finance\PeriodCloseBlocked;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Notifications\Finance\PeriodCloseReminderNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\ContractSweeper;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\PeriodCloseReminderSweeper;
use App\Services\Finance\PeriodCloseService;
use Illuminate\Support\Facades\Notification;

/** Auto-discovered: a refused close tells Finance and the owners of red variances, once per period per day. */
class SendPeriodCloseBlockedNotification
{
    public function __construct(private readonly ContractService $contracts, private readonly ContractSweeper $sweeper, private readonly PeriodCloseService $closes) {}

    public function handle(PeriodCloseBlocked $e): void
    {
        $period = Period::withoutGlobalScopes()->find($e->periodId);
        if (! $period) {
            return;
        }
        $orgId = (int) $period->organization_id;
        $ccIds = VarianceNote::withoutGlobalScopes()->where('organization_id', $orgId)->where('period_id', $period->id)->where('severity', 'red')->where('is_breaching', true)
            ->whereIn('status', ['open'])->pluck('cost_centre_id')->unique();
        $owners = User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)
            ->whereIn('id', CostCentre::withoutGlobalScopes()->whereIn('id', $ccIds)->whereNotNull('owner_id')->pluck('owner_id'))->get();
        $users = $this->contracts->financeUsers($orgId)->merge($owners)->unique('id')->values();
        $users = $this->sweeper->claim($period, 'close_blocked:'.FinanceClock::today()->toDateString(), 0, $users);
        if ($users->isEmpty()) {
            return;
        }
        Notification::send($users, new PeriodCloseReminderNotification(
            $this->label($period), 'blocked', 0, PeriodCloseReminderSweeper::openItems($this->closes->checklist($period)),
            route('finance.monitor.period-close', ['fiscal_year_id' => $period->fiscal_year_id, 'period_id' => $period->id])));
    }

    private function label(Period $p): string
    {
        return trim(($p->fiscalYear?->year_label ?? '').' '.$p->name);
    }
}
