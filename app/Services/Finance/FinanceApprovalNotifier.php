<?php

namespace App\Services\Finance;

use App\Events\Finance\FinanceApprovalDecided;
use App\Events\Finance\FinanceApprovalRequested;
use App\Models\Approvals\ApprovalRequest;
use App\Models\User;
use App\Notifications\Finance\FinanceApprovalDecisionNotification;
use App\Notifications\Finance\FinanceApprovalRequestedNotification;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Approvals\ApprovalProcesses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/** Who hears about a finance approval, and where the link goes (plan §4.4). */
class FinanceApprovalNotifier
{
    public function __construct(private readonly ApprovalEngine $engine, private readonly FinanceSettings $settings) {}

    public function url(string $process, Model $subject): string
    {
        return match ($process) {
            'finance.budget_approval' => route('finance.budgets.show', [$subject->getKey(), 'tab' => 'approvals']),
            'finance.budget_transfer' => route('finance.transfers.index', ['highlight' => $subject->getKey()]),
            'finance.contract_renewal' => route('finance.renewals.show', $subject),
            'finance.contract_termination' => route('finance.terminations.show', $subject),
            'finance.expense_approval' => route('finance.expenses.show', $subject),
            'finance.invoice_approval' => route('finance.invoices.show', $subject),
            'finance.period_reopen' => route('finance.period-closes.show', $subject),
            default => route('finance.supplementary.index', ['highlight' => $subject->getKey()]),
        };
    }

    /** @return Collection<int, User> */
    public function approversFor(string $process, Model $subject, ?ApprovalRequest $request): Collection
    {
        $def = ApprovalProcesses::get($process);
        $orgId = (int) $subject->organization_id;
        $step = $request?->currentStep();
        if ($request && $step && $this->engine->workflow($orgId, $process)) {
            return $this->engine->eligibleUsers($step, $request)->filter(fn (User $u) => $u->can($def['permission']) || ($step->approver_type === 'owner' && ! empty($def['owner_stage_without_permission'])))->values();
        }
        $initiator = (int) ($subject->{$def['initiator']} ?? 0);
        $owner = isset($def['owner']) ? (int) ($subject->{$def['owner']} ?? 0) : 0;

        return User::where('organization_id', $orgId)->get()
            ->filter(fn (User $u) => $u->can($def['permission']) && (int) $u->id !== $initiator && (! $owner || (int) $u->id !== $owner))->values();
    }

    /** @return Collection<int, User> */
    public function escalationUsers(int $orgId): Collection
    {
        $role = trim((string) $this->settings->for($orgId)->escalation_role);
        $users = $role === '' ? collect() : User::where('organization_id', $orgId)->get()
            ->filter(fn (User $u) => $u->job_title && stripos($u->job_title, $role) !== false);

        return $users->isNotEmpty() ? $users->values()
            : User::where('organization_id', $orgId)->role('Organization Admin')->get();
    }

    public function requested(FinanceApprovalRequested $e): void
    {
        $recipients = $this->approversFor($e->process, $e->subject, $e->request)->reject(fn (User $u) => $e->actor && $u->id === $e->actor->id);
        if ($recipients->isEmpty()) {
            return;
        }
        $amount = $this->amountText($e->subject, $e->request);
        foreach ($recipients as $u) {
            Notification::send($u, new FinanceApprovalRequestedNotification(
                $e->process, ApprovalProcesses::label($e->process, $e->subject), $e->request?->currentStep()?->name, $u->can('view it-finance-amounts') ? $amount : '', $this->url($e->process, $e->subject)));
        }
    }

    public function decided(FinanceApprovalDecided $e): void
    {
        $def = ApprovalProcesses::get($e->process);
        $orgId = (int) $e->subject->organization_id;
        $ids = collect([$e->subject->{$def['initiator']} ?? null, isset($def['owner']) ? ($e->subject->{$def['owner']} ?? null) : null])
            ->filter()->map(fn ($i) => (int) $i)->unique()->reject(fn ($i) => $i === (int) $e->actor->id);
        $recipients = User::whereIn('id', $ids)->where('organization_id', $orgId)->get();
        if ($recipients->isEmpty()) {
            return;
        }
        Notification::send($recipients, new FinanceApprovalDecisionNotification(
            $e->process, ApprovalProcesses::label($e->process, $e->subject), $e->outcome, $e->actor->name, $e->note, $this->url($e->process, $e->subject)));
    }

    private function amountText(Model $subject, ?ApprovalRequest $request): string
    {
        $amount = $request?->context['base_amount'] ?? (method_exists($subject, 'approvalBaseAmount') ? $subject->approvalBaseAmount() : null) ?? $subject->base_amount ?? $subject->total_base_amount ?? null;
        if ($amount === null) {
            return 'amount n/a';
        }

        return $this->settings->baseCurrency((int) $subject->organization_id).' '.number_format((float) $amount);
    }
}
