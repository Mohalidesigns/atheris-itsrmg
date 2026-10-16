<?php

namespace App\Services\Finance;

use App\Models\Approvals\ApprovalRequest;
use App\Models\Finance\Contract;
use App\Models\User;
use App\Notifications\Finance\FinanceDailyDigestNotification;
use App\Services\Approvals\ApprovalEngine;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Opt-in daily digest at each bank's digest hour (fin:digest, plan C63). P3/P4 append invoice and variance sections. */
class FinanceDigest
{
    public function __construct(private readonly ContractService $contracts, private readonly ApprovalEngine $engine, private readonly FinanceSettings $settings) {}

    public function send(?CarbonInterface $now = null): int
    {
        $now ??= FinanceClock::now();
        $sent = 0;
        $orgIds = User::withoutGlobalScopes()->whereNotNull('organization_id')->where('is_active', true)->distinct()->orderBy('organization_id')->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            try {
                $hour = (int) ($this->settings->for((int) $orgId)->digest_hour ?? config('finance.settings_defaults.digest_hour', 7));
                if ($hour !== (int) $now->hour) {
                    continue;
                }
                $users = User::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_active', true)->orderBy('id')->get()
                    ->filter(fn (User $u) => ($u->notification_preferences['finance_daily_digest'] ?? false) === true && $u->can('view it-finance'));
                foreach ($users as $user) {
                    $sent += $this->sendTo($user, (int) $orgId, $now);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function sendTo(User $user, int $orgId, CarbonInterface $now): int
    {
        $key = 'daily_digest.'.$now->format('Ymd');
        $taken = DB::table('fin_notification_logs')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->where('notification_key', $key)->exists();
        if ($taken) {
            return 0;
        }
        $sections = array_values(array_filter([$this->renewalsSection($user, $orgId), $this->approvalsSection($user, $orgId)]));
        if (! $sections) {
            return 0;
        }
        $ts = now();
        $claimed = DB::table('fin_notification_logs')->insertOrIgnore([
            'organization_id' => $orgId, 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'notification_key' => $key, 'offset_days' => 0,
            'sent_to_user_id' => $user->id, 'sent_at' => $ts, 'channel' => 'database', 'created_at' => $ts, 'updated_at' => $ts,
        ]);
        if (! $claimed) {
            return 0;
        }
        $user->notify(new FinanceDailyDigestNotification($sections, route('finance.contracts.index'), $now->format('d M Y')));

        return 1;
    }

    /** @return array{title:string,lines:array<int,string>}|null */
    private function renewalsSection(User $user, int $orgId): ?array
    {
        $today = FinanceClock::today();
        $mine = Contract::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', Contract::LIVE)->get()
            ->filter(fn (Contract $c) => $this->contracts->recipients($c)->contains('id', $user->id));
        $due = $mine->filter(function (Contract $c) use ($today) {
            $e = FinanceClock::daysUntil($c->ends_on, $today);
            $n = FinanceClock::daysUntil($c->notice_deadline_on, $today);

            return ($e !== null && $e >= 0 && $e <= 30) || ($n !== null && $n >= 0 && $n <= 30);
        })->sortBy('ends_on');
        $passed = $mine->filter(fn (Contract $c) => $c->decision === null && FinanceClock::daysUntil($c->notice_deadline_on, $today) < 0)->count();
        if ($due->isEmpty() && $passed === 0) {
            return null;
        }
        $lines = $due->map(function (Contract $c) use ($today) {
            $n = FinanceClock::daysUntil($c->notice_deadline_on, $today);
            $e = FinanceClock::daysUntil($c->ends_on, $today);

            return $c->label.($n >= 0 && $n <= 30 ? ": notice deadline in {$n} day(s)" : ": ends in {$e} day(s)");
        })->values()->all();
        $passed && $lines[] = "{$passed} contract(s) past the notice deadline with no decision";

        return ['title' => 'Renewals due in 30 days', 'lines' => $lines];
    }

    /** @return array{title:string,lines:array<int,string>}|null */
    private function approvalsSection(User $user, int $orgId): ?array
    {
        $lines = [];
        $requests = ApprovalRequest::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', 'pending')->where('process', 'like', 'finance.%')
            ->with('steps.decisions')->orderBy('id')->get();
        foreach ($requests as $r) {
            $step = $r->currentStep();
            $subject = $r->subject_type::withoutGlobalScopes()->find($r->subject_id);
            if ($step && $this->engine->canDecide($step, $r, $subject, $user)) {
                $lines[] = ($r->subject_label ?: class_basename($r->subject_type).' #'.$r->subject_id).' · '.$step->name;
            }
        }

        return $lines ? ['title' => 'Approvals waiting for you', 'lines' => $lines] : null;
    }
}
