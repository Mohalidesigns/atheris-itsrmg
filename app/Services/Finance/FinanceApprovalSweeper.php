<?php

namespace App\Services\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Approvals\ApprovalStep;
use App\Models\Finance\NotificationLog;
use App\Notifications\Finance\FinanceApprovalOverdueNotification;
use App\Services\Approvals\ApprovalProcesses;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Hourly reminders for overdue finance approval steps, once per 24 h per step (fin:approvals-sweep). */
class FinanceApprovalSweeper
{
    public const KEY = 'approval_overdue';

    public function __construct(private readonly FinanceApprovalNotifier $notifier) {}

    public function sweep(): int
    {
        $count = 0;
        $steps = ApprovalStep::withoutGlobalScopes()->where('status', 'pending')->where('due_at', '<', now())
            ->whereHas('request', fn ($q) => $q->withoutGlobalScopes()->where('status', 'pending')->where('process', 'like', 'finance.%'))
            ->orderBy('id')->get();

        foreach ($steps as $step) {
            try {
                $request = ApprovalRequest::withoutGlobalScopes()->with('steps.decisions')->find($step->request_id);
                if (! $request || $request->currentStep()?->id !== $step->id) {
                    continue;
                }
                $recent = NotificationLog::withoutGlobalScopes()->where('notifiable_type', ApprovalStep::class)->where('notifiable_id', $step->id)
                    ->where('notification_key', self::KEY)->where('sent_at', '>', now()->subHours(24))->exists();
                $subject = $request->subject_type::withoutGlobalScopes()->find($request->subject_id);
                if ($recent || ! $subject) {
                    continue;
                }
                $recipients = $this->notifier->approversFor($request->process, $subject, $request)
                    ->merge($this->notifier->escalationUsers((int) $request->organization_id))->unique('id')->values();
                if ($recipients->isEmpty()) {
                    continue;
                }
                Notification::send($recipients, new FinanceApprovalOverdueNotification(
                    $request->process, $request->subject_label ?: ApprovalProcesses::label($request->process, $subject),
                    $step->name, $step->due_at->format('d M Y H:i'), $this->notifier->url($request->process, $subject)));
                foreach ($recipients as $u) {
                    NotificationLog::withoutGlobalScopes()->create([
                        'organization_id' => $request->organization_id, 'notifiable_type' => ApprovalStep::class, 'notifiable_id' => $step->id,
                        'notification_key' => self::KEY, 'sent_to_user_id' => $u->id, 'sent_at' => now(), 'channel' => 'database',
                    ]);
                }
                $count++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $count;
    }
}
