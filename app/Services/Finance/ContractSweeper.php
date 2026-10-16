<?php

namespace App\Services\Finance;

use App\Models\Finance\Contract;
use App\Models\User;
use App\Notifications\Finance\ContractDeadlinePassedNotification;
use App\Notifications\Finance\ContractRenewalDueNotification;
use App\Notifications\Finance\ContractRenewalEscalationNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Daily contract sweep (fin:contracts-sweep, ATH-FIN-001 §5.4): auto-roll, status derivation, T-minus series for the end
 * date and the notice deadline, escalation at notice -7, and "passed" notices. Every send is claimed in
 * fin_notification_logs first (unique per record, key, offset and recipient), so a re-run never repeats one.
 */
class ContractSweeper
{
    public function __construct(
        private readonly ContractService $contracts,
        private readonly RenewalService $renewals,
        private readonly ContractStatusDeriver $deriver,
        private readonly FinanceApprovalNotifier $approvalNotifier,
    ) {}

    /** @return array{derived:int,rolled:int,notified:int,escalated:int,passed:int} */
    public function sweep(?CarbonInterface $today = null): array
    {
        $today ??= FinanceClock::today();
        $totals = ['derived' => 0, 'rolled' => 0, 'notified' => 0, 'escalated' => 0, 'passed' => 0];

        $orgIds = Contract::withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('status', array_merge(['draft'], Contract::TERMINAL))
            ->distinct()->orderBy('organization_id')->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            try {
                foreach ($this->sweepOrg((int) $orgId, $today) as $k => $n) {
                    $totals[$k] += $n;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $totals;
    }

    /** @return array{derived:int,rolled:int,notified:int,escalated:int,passed:int} */
    private function sweepOrg(int $orgId, CarbonInterface $today): array
    {
        $r = ['derived' => 0, 'rolled' => 0, 'notified' => 0, 'escalated' => 0, 'passed' => 0];
        $queue = Contract::withoutGlobalScopes()->whereNull('deleted_at')->where('organization_id', $orgId)
            ->whereNotIn('status', array_merge(['draft'], Contract::TERMINAL))->orderBy('id')->get()->all();

        while ($c = array_shift($queue)) {
            try {
                if ($this->deriver->needsRoll($c, $today)) {
                    $successor = $this->renewals->autoRenew($c, $today);
                    if ($successor) {
                        $r['rolled']++;
                        $queue[] = $successor;
                    }
                    $c->refresh();
                    if ($c->is_terminal) {
                        continue;
                    }
                }
                $this->deriver->apply($c, $today) && $r['derived']++;
                $r['notified'] += $this->tMinus($c, $today);
                $r['escalated'] += $this->escalate($c, $today);
                $r['passed'] += $this->passed($c, $today);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $r;
    }

    private function tMinus(Contract $c, CarbonInterface $today): int
    {
        if ($c->decision !== null || ! in_array($c->status, ['active', 'in_renewal_window', 'notice_deadline_passed'], true)) {
            return 0;
        }
        $catchUp = (int) config('finance.contracts.catch_up_days', 3);
        $sent = 0;
        foreach (['end' => $c->ends_on, 'notice' => $c->notice_deadline_on] as $series => $date) {
            $d = FinanceClock::daysUntil($date, $today);
            if ($d === null || $d < 0) {
                continue;
            }
            $k = collect($c->effective_offsets)->filter(fn ($o) => $o - $catchUp <= $d && $d <= $o)->min();
            if ($k === null) {
                continue;
            }
            $key = "renewal_due.{$series}.".$date->format('Y-m-d'); // the target date re-arms the series for a new term or a moved date
            $recipients = $this->contracts->recipients($c)->merge($this->contracts->financeUsers((int) $c->organization_id))->unique('id')->values();
            $users = $this->claim($c, $key, (int) $k, $recipients);
            if ($users->isEmpty()) {
                continue;
            }
            Notification::send($users, new ContractRenewalDueNotification(
                $c->label, (string) ($c->vendor?->name ?? 'No vendor'), $series, (int) $k, $d, $date->format('d M Y'), $this->url($c)));
            $c->forceFill(['last_notified_offset' => $k])->save();
            $this->contracts->event($c, 'notified', ['series' => $series, 'offset' => (int) $k, 'recipients' => $users->pluck('id')->all()]);
            $sent++;
        }

        return $sent;
    }

    private function escalate(Contract $c, CarbonInterface $today): int
    {
        $d = FinanceClock::daysUntil($c->notice_deadline_on, $today);
        if ($c->decision !== null || $c->escalated_at !== null || $d === null || $d < 0 || $d > (int) config('finance.contracts.escalation_days_before_notice', 7)
            || ! in_array($c->status, Contract::LIVE, true)
            || $c->renewals()->whereIn('status', ['submitted', 'approved', 'executed'])->exists()) {
            return 0;
        }
        $users = $this->claim($c, 'renewal_escalation.'.$c->notice_deadline_on->format('Y-m-d'), 0, $this->escalationRecipients((int) $c->organization_id));
        if ($users->isEmpty()) {
            return 0;
        }
        Notification::send($users, new ContractRenewalEscalationNotification(
            $c->label, (string) ($c->vendor?->name ?? 'No vendor'), $c->notice_deadline_on->format('d M Y'), $d, $c->owner?->name, $this->url($c)));
        $c->forceFill(['escalated_at' => FinanceClock::now()])->save();
        $this->contracts->event($c, 'escalated', ['days_to_notice' => $d, 'recipients' => $users->pluck('id')->all()]);

        return 1;
    }

    private function passed(Contract $c, CarbonInterface $today): int
    {
        if ($c->decision !== null || $c->is_terminal || $c->status === 'draft') {
            return 0;
        }
        $sent = 0;
        foreach (['notice' => $c->notice_deadline_on, 'end' => $c->ends_on] as $which => $date) {
            $d = FinanceClock::daysUntil($date, $today);
            if ($d === null || $d >= 0) {
                continue;
            }
            $recipients = $this->contracts->recipients($c)->merge($this->contracts->financeUsers((int) $c->organization_id))
                ->merge($this->escalationRecipients((int) $c->organization_id))->unique('id')->values();
            $users = $this->claim($c, "deadline_passed.{$which}.".$date->format('Y-m-d'), 0, $recipients);
            if ($users->isEmpty()) {
                continue;
            }
            Notification::send($users, new ContractDeadlinePassedNotification(
                $c->label, (string) ($c->vendor?->name ?? 'No vendor'), $which, $date->format('d M Y'), abs($d), $c->is_auto, $this->url($c)));
            $sent++;
        }

        return $sent;
    }

    /** Escalation users from the settings role plus the configured executive job titles. @return Collection<int,User> */
    private function escalationRecipients(int $orgId): Collection
    {
        $titles = (array) config('finance.contracts.escalation_job_titles', []);
        $byTitle = User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->job_title && collect($titles)->contains(fn ($t) => stripos($u->job_title, $t) !== false));

        return $this->approvalNotifier->escalationUsers($orgId)->filter(fn (User $u) => $u->is_active)->merge($byTitle)->unique('id')->values();
    }

    /**
     * Claim (record, key, offset) for each recipient in fin_notification_logs. Returns only the users newly claimed;
     * nothing at all when any log (even one seeded with another recipient) already exists for the triple.
     *
     * @param  Collection<int,User>  $users
     * @return Collection<int,User>
     */
    public function claim(Model $subject, string $key, int $offset, Collection $users): Collection
    {
        $exists = DB::table('fin_notification_logs')->where('notifiable_type', $subject::class)->where('notifiable_id', $subject->getKey())
            ->where('notification_key', $key)->where('offset_days', $offset)->exists();
        if ($exists || $users->isEmpty()) {
            return collect();
        }
        $now = now();

        return $users->filter(fn (User $u) => DB::table('fin_notification_logs')->insertOrIgnore([
            'organization_id' => $subject->organization_id, 'notifiable_type' => $subject::class, 'notifiable_id' => $subject->getKey(),
            'notification_key' => $key, 'offset_days' => $offset, 'sent_to_user_id' => $u->id, 'sent_at' => $now, 'channel' => 'database',
            'created_at' => $now, 'updated_at' => $now,
        ]) > 0)->values();
    }

    private function url(Contract $c): string
    {
        return route('finance.contracts.show', $c->id);
    }
}
