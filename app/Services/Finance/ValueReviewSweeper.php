<?php

namespace App\Services\Finance;

use App\Models\Finance\Investment;
use App\Models\Finance\ValueReview;
use App\Models\User;
use App\Notifications\Finance\ValueReviewDueNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Daily value-review sweep (fin:value-reviews-sweep, plan P5-13): derives due/overdue, notifies once on due and once per overdue offset. */
class ValueReviewSweeper
{
    public function __construct(private readonly ValueReviewService $reviews, private readonly ContractSweeper $sweeper, private readonly FinanceSettings $settings) {}

    public function sweep(?CarbonInterface $today = null): int
    {
        $today = ($today ?? FinanceClock::today())->startOfDay();
        $sent = 0;
        $orgIds = ValueReview::withoutGlobalScopes()->whereIn('status', ValueReview::OPEN)->distinct()->orderBy('organization_id')->pluck('organization_id');
        foreach ($orgIds as $orgId) {
            try {
                $this->reviews->derive((int) $orgId, $today);
                $sent += $this->sweepOrg((int) $orgId, $today);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    private function sweepOrg(int $orgId, CarbonInterface $today): int
    {
        $lead = $this->settings->valueReviewLeadDays($orgId);
        $offsets = collect((array) config('finance.investments.value_review_overdue_offsets', [1, 7, 14, 30]))->map(fn ($o) => (int) $o)->sort()->values();
        $sent = 0;
        $reviews = ValueReview::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ['due', 'overdue'])->orderBy('id')->get();
        foreach ($reviews as $r) {
            $inv = Investment::withoutGlobalScopes()->with(['sponsor', 'manager'])->whereKey($r->investment_id)->whereNull('deleted_at')->first();
            if (! $inv || ! $inv->is_open) {
                continue;
            }
            $key = ($r->status === 'overdue' ? 'value_review_overdue:' : 'value_review_due:').$r->due_on->format('Y-m-d');
            if ($r->status === 'due') {
                $kind = 'due';
                $offset = $lead;
                $late = 0;
            } else {
                $late = -(int) FinanceClock::daysUntil($r->due_on, $today);
                $reached = $offsets->filter(fn ($o) => $o <= $late);
                if ($reached->isEmpty()) {
                    continue;
                }
                $kind = 'overdue';
                $offset = (int) $reached->max();
                $recipients = $this->recipients($r, $inv);
                foreach ($reached->reject(fn ($o) => $o === $offset) as $lower) {
                    $this->sweeper->claim($r, $key, $lower, $recipients); // a late first run sends the highest reached offset only
                }
            }
            $users = $this->sweeper->claim($r, $key, $offset, $this->recipients($r, $inv));
            if ($users->isEmpty()) {
                continue;
            }
            Notification::send($users, new ValueReviewDueNotification(
                (string) $inv->investment_code, ValueReview::TYPE_LABELS[$r->review_type] ?? $r->review_type, $r->due_on->format('d M Y'), $kind, (int) $late,
                route('finance.investments.show', [$inv->id, 'tab' => 'reviews'])));
            $sent++;
        }

        return $sent;
    }

    /** Reviewer and sponsor who are active and may view IT finance (security L8). @return Collection<int,User> */
    private function recipients(ValueReview $r, Investment $inv): Collection
    {
        return User::withoutGlobalScopes()->where('organization_id', $r->organization_id)->where('is_active', true)
            ->whereIn('id', array_filter([$r->reviewer_id, $inv->sponsor_id]))->get()
            ->filter(fn (User $u) => $u->can('view it-finance'))->unique('id')->values();
    }
}
