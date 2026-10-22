<?php

namespace App\Services\Finance;

use App\Models\Finance\CostCentre;
use App\Models\Finance\Invoice;
use App\Models\User;
use App\Notifications\Finance\InvoiceDueNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Daily invoice sweep (fin:invoices-sweep, plan §3.5): due and overdue series, one send per invoice, due date and offset. */
class InvoiceSweeper
{
    public function __construct(private readonly ContractService $contracts, private readonly ContractSweeper $sweeper) {}

    public function sweep(?CarbonInterface $today = null): int
    {
        $today ??= FinanceClock::today();
        $sent = 0;
        $orgIds = Invoice::withoutGlobalScopes()->whereNull('deleted_at')->whereIn('status', Invoice::UNPAID)->distinct()->orderBy('organization_id')->pluck('organization_id');
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
        $catchUp = (int) config('finance.contracts.catch_up_days', 3);
        $dueOffsets = (array) config('finance.invoices.due_offsets', [7, 0]);
        $overdueOffsets = (array) config('finance.invoices.overdue_offsets', [1, 7, 14, 30]);
        $finance = $this->contracts->financeUsers($orgId);
        $sent = 0;

        $invoices = Invoice::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', Invoice::UNPAID)
            ->where('status', '!=', 'disputed')->whereNotNull('due_on')->with(['vendor:id,name'])->orderBy('id')->get();
        foreach ($invoices as $i) {
            $d = FinanceClock::daysUntil($i->due_on, $today);
            if ($d === null) {
                continue;
            }
            if ($d >= 0) {
                $k = collect($dueOffsets)->filter(fn ($o) => $o - $catchUp <= $d && $d <= $o)->min();
                $offset = $k;
            } else {
                $late = -$d;
                $k = collect($overdueOffsets)->filter(fn ($o) => $o <= $late && $late <= $o + $catchUp)->max();
                $offset = $k === null ? null : -$k;
            }
            if ($k === null) {
                continue;
            }
            $recipients = $finance->merge($this->owners($i));
            $d < 0 && $i->submittedBy && $recipients->push($i->submittedBy);
            $users = $this->sweeper->claim($i, 'invoice_due:'.$i->due_on->format('Y-m-d'), (int) $offset, $recipients->filter(fn ($u) => $u->is_active)->unique('id')->values());
            if ($users->isEmpty()) {
                continue;
            }
            Notification::send($users, new InvoiceDueNotification((string) $i->invoice_code, (string) ($i->vendor?->name ?? 'No vendor'), $i->due_on->format('d M Y'), $d, route('finance.invoices.show', $i->id)));
            $sent++;
        }

        return $sent;
    }

    /** @return Collection<int,User> */
    private function owners(Invoice $i)
    {
        $ownerId = $i->cost_centre_id ? CostCentre::withoutGlobalScopes()->whereKey($i->cost_centre_id)->value('owner_id') : null;

        return $ownerId ? User::withoutGlobalScopes()->where('organization_id', $i->organization_id)->whereKey($ownerId)->get() : collect();
    }
}
