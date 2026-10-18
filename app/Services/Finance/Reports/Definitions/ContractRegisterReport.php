<?php

namespace App\Services\Finance\Reports\Definitions;

use App\Models\Finance\Contract;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\Reports\FinanceReport;

/** The contract register; the window narrows it to contracts whose end or notice date falls within 30, 60 or 90 days. */
class ContractRegisterReport extends FinanceReport
{
    public function key(): string
    {
        return 'contract_register';
    }

    public function title(): string
    {
        return 'Contract register';
    }

    public function description(): string
    {
        return 'Live and recent contracts with their end and notice dates, optionally only those due within 30, 60 or 90 days.';
    }

    public function group(): string
    {
        return 'contracts';
    }

    public function filters(): array
    {
        return ['window', 'cost_centre_id'];
    }

    public function columns(): array
    {
        return [
            'code' => ['label' => 'Code', 'type' => 'text'], 'title' => ['label' => 'Title', 'type' => 'text'], 'vendor' => ['label' => 'Vendor', 'type' => 'text'], 'type' => ['label' => 'Type', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'text'], 'commenced_on' => ['label' => 'Commenced', 'type' => 'date'], 'ends_on' => ['label' => 'Ends', 'type' => 'date'],
            'notice_deadline_on' => ['label' => 'Notice deadline', 'type' => 'date'], 'days_to_notice' => ['label' => 'Days to notice', 'type' => 'text'],
            'auto_renew' => ['label' => 'Auto-renew', 'type' => 'text'], 'owner' => ['label' => 'Owner', 'type' => 'text'], 'base_value' => ['label' => 'Base value', 'type' => 'money'],
        ];
    }

    public function rows(int $orgId, array $filters, ?array $costCentreIds): iterable
    {
        $window = (string) ($filters['window'] ?? 'all');
        $today = FinanceClock::today();
        $q = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereNotIn('status', ['draft'])
            ->when($costCentreIds !== null, fn ($w) => $w->whereIn('cost_centre_id', $costCentreIds ?: [0]))
            ->when(! empty($filters['cost_centre_id']), fn ($w) => $w->where('cost_centre_id', (int) $filters['cost_centre_id']));
        if (in_array($window, ['30', '60', '90'], true)) {
            $to = $today->addDays((int) $window)->toDateString();
            $from = $today->toDateString();
            $q->live()->where(fn ($w) => $w->whereBetween('ends_on', [$from, $to])->orWhereBetween('notice_deadline_on', [$from, $to]));
        } else {
            $q->whereNotIn('status', Contract::TERMINAL);
        }
        $contracts = $q->orderBy('notice_deadline_on')->orderBy('ends_on')->orderBy('id')->get();
        $vendors = Vendor::withoutGlobalScopes()->whereIn('id', $contracts->pluck('vendor_id')->filter()->unique())->pluck('name', 'id');
        $owners = User::withoutGlobalScopes()->whereIn('id', $contracts->pluck('owner_id')->filter()->unique())->pluck('name', 'id');

        return $contracts->map(fn (Contract $c) => [
            'code' => (string) $c->contract_code, 'title' => (string) $c->title, 'vendor' => (string) ($vendors[$c->vendor_id] ?? ''), 'type' => (string) $c->type, 'status' => (string) $c->status,
            'commenced_on' => $c->commenced_on?->toDateString(), 'ends_on' => $c->ends_on?->toDateString(), 'notice_deadline_on' => $c->notice_deadline_on?->toDateString(),
            'days_to_notice' => $c->notice_deadline_on ? (string) FinanceClock::daysUntil($c->notice_deadline_on, $today) : '', 'auto_renew' => $c->auto_renew ? 'Yes' : 'No',
            'owner' => (string) ($owners[$c->owner_id] ?? ''), 'base_value' => bcadd((string) $c->base_contract_value, '0', 2),
        ])->all();
    }
}
