<?php

namespace App\Listeners\Finance;

use App\Events\Finance\VarianceBreached;
use App\Models\Finance\Budget;
use App\Models\Finance\CostCentre;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Notifications\Finance\BudgetVarianceNotification;
use App\Services\Finance\ContractService;
use App\Services\Finance\ContractSweeper;
use Illuminate\Support\Facades\Notification;

/** Auto-discovered: budget owner, cost-centre owner and Finance hear once per (note, severity) (plan P4-4). */
class SendBudgetVarianceNotification
{
    public function __construct(private readonly ContractService $contracts, private readonly ContractSweeper $sweeper) {}

    public function handle(VarianceBreached $e): void
    {
        $note = VarianceNote::withoutGlobalScopes()->with(['line', 'period'])->find($e->noteId);
        if (! $note) {
            return;
        }
        $orgId = (int) $note->organization_id;
        $budget = $note->line ? Budget::withoutGlobalScopes()->find($note->line->budget_id) : null;
        $cc = CostCentre::withoutGlobalScopes()->find($note->cost_centre_id);
        $ids = collect([$budget?->owner_id, $cc?->owner_id])->filter()->map(fn ($i) => (int) $i)->unique();
        $users = User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->whereIn('id', $ids)->get()
            ->merge($this->contracts->financeUsers($orgId))->unique('id')->values();
        $users = $this->sweeper->claim($note, "variance:{$e->severity}", 0, $users);
        if ($users->isEmpty()) {
            return;
        }
        Notification::send($users, new BudgetVarianceNotification(
            (string) ($note->line?->description ?? 'a budget line'), (string) ($cc?->code ?? 'no cost centre'), (string) ($note->period?->name ?? ''),
            $e->severity, (string) $note->direction,
            route('finance.monitor.variances', ['fiscal_year_id' => $note->fiscal_year_id, 'period_id' => $note->period_id])));
    }
}
