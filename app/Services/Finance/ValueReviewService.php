<?php

namespace App\Services\Finance;

use App\Models\Finance\Benefit;
use App\Models\Finance\Investment;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Post-implementation value reviews (plan P5-13): scheduled on delivery, derived due/overdue daily, completed with the realised outcome. */
class ValueReviewService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public function __construct(
        private readonly FinanceSettings $settings,
        private readonly BenefitsService $benefits,
        private readonly InvestmentFinancials $financials,
    ) {}

    /** Creates the 3, 6 and 12 month reviews after the actual end date; idempotent per type. Returns the number created. */
    public function schedule(Investment $i, User $by): int
    {
        $i->actual_ends_on || $this->fail('The investment has no actual end date.', 'actual_ends_on');
        $n = 0;
        foreach ((array) config('finance.investments.value_review_offsets_months') as $type => $months) {
            if (ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->where('review_type', $type)->where('status', '!=', 'cancelled')->exists()) {
                continue;
            }
            ValueReview::withoutGlobalScopes()->create([
                'organization_id' => $i->organization_id, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'review_type' => $type,
                'due_on' => $i->actual_ends_on->addMonthsNoOverflow((int) $months)->toDateString(), 'reviewer_id' => $this->defaultReviewerId($i), 'status' => 'scheduled',
            ]);
            $n++;
        }
        $this->syncDates($i);
        $n && $this->audit($i, $by, 'reviews_scheduled', "Value reviews scheduled for {$i->investment_code}", ['created' => $n]);

        return $n;
    }

    public function adHoc(Investment $i, string $dueOn, ?int $reviewerId, User $by): ValueReview
    {
        $i->is_open || $this->fail("A {$i->stage} investment takes no new reviews.", 'due_on');
        $orgId = (int) $i->organization_id;
        $due = $this->date($dueOn, 'due_on');
        $reviewerId && $this->inBank(User::class, $orgId, $reviewerId, 'reviewer_id', 'Pick a reviewer from this bank.');
        $reviewerId && $this->assertIndependent($i, (int) $reviewerId, 'reviewer_id');
        $r = ValueReview::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'review_type' => 'ad_hoc', 'due_on' => $due->toDateString(),
            'reviewer_id' => $reviewerId ?: $this->defaultReviewerId($i), 'status' => 'scheduled',
        ]);
        $this->syncDates($i);
        $this->audit($i, $by, 'review_added', "Ad hoc value review added to {$i->investment_code}", ['due_on' => $due->toDateString()]);

        return $r;
    }

    /** @param array{due_on?:?string,reviewer_id?:mixed,reason?:?string} $data */
    public function update(ValueReview $r, array $data, User $by): ValueReview
    {
        in_array($r->status, ValueReview::OPEN, true) || $this->fail("A {$r->status} review cannot be changed.", 'due_on');
        $i = Investment::withoutGlobalScopes()->findOrFail($r->investment_id);
        $attrs = [];
        if (! empty($data['due_on'])) {
            $due = $this->date($data['due_on'], 'due_on')->toDateString();
            if ($due !== $r->due_on->toDateString()) {
                mb_strlen(trim((string) ($data['reason'] ?? ''))) >= (int) config('finance.monitoring.override_reason_min') || $this->fail('Give the reason for moving the review date.', 'reason');
                $attrs = ['due_on' => $due, 'status' => 'scheduled'];
            }
        }
        if (array_key_exists('reviewer_id', $data)) {
            $attrs['reviewer_id'] = $data['reviewer_id'] ? (int) $this->inBank(User::class, (int) $r->organization_id, $data['reviewer_id'], 'reviewer_id', 'Pick a reviewer from this bank.')->id : null;
            $attrs['reviewer_id'] && $this->assertIndependent($i, $attrs['reviewer_id'], 'reviewer_id');
        }
        $r->forceFill($attrs)->save();
        $this->syncDates($i);
        $this->audit($i, $by, 'review_updated', "Value review of {$i->investment_code} updated", $attrs + ['reason' => $data['reason'] ?? null]);

        return $r->refresh();
    }

    /** @param array{summary?:string,lessons?:?string} $data */
    public function complete(ValueReview $r, array $data, User $by): ValueReview
    {
        in_array($r->status, ValueReview::OPEN, true) || $this->fail("A {$r->status} review cannot be completed.", 'summary');
        $summary = trim((string) ($data['summary'] ?? ''));
        mb_strlen($summary) >= (int) config('finance.investments.reason_min') || $this->fail('Summarise the findings (at least '.config('finance.investments.reason_min').' characters).', 'summary');
        $i = Investment::withoutGlobalScopes()->findOrFail($r->investment_id);
        $this->assertIndependent($i, (int) $by->id, 'summary', true);
        $evm = $this->financials->evm($i);
        $band = (string) config('finance.investments.cost_outcome_band_pct');
        $outcome = 'on';
        if ($evm['bac'] !== null && $evm['eac'] !== null && bccomp($evm['bac'], '0', 2) > 0) {
            $tol = bcmul($evm['bac'], bcdiv($band, '100', 6), 2);
            $diff = bcsub($evm['eac'], $evm['bac'], 2);
            $outcome = bccomp($diff, $tol, 2) > 0 ? 'over' : (bccomp($diff, bcsub('0', $tol, 2), 2) < 0 ? 'under' : 'on');
        }
        $r->forceFill([
            'status' => 'completed', 'completed_on' => FinanceClock::today()->toDateString(), 'completed_by_id' => $by->id, 'summary' => $summary,
            'lessons' => ($l = trim((string) ($data['lessons'] ?? ''))) === '' ? null : $l, 'benefits_realised_pct' => $this->benefits->investmentRealisedPct($i), 'cost_outcome' => $outcome,
        ])->save();
        $this->audit($i, $by, 'review_completed', "Value review of {$i->investment_code} completed", ['cost_outcome' => $outcome]);

        return $r->refresh();
    }

    /**
     * Moves open reviews to due/overdue by the date and refreshes benefit statuses. Returns the reviews whose status changed.
     *
     * @return Collection<int,ValueReview>
     */
    public function derive(int $orgId, CarbonInterface $today): Collection
    {
        $lead = $this->settings->valueReviewLeadDays($orgId);
        $changed = collect();
        foreach (ValueReview::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('status', ValueReview::OPEN)->get() as $r) {
            $status = $today->toDateString() > $r->due_on->toDateString() ? 'overdue'
                : ($today->toDateString() >= $r->due_on->subDays($lead)->toDateString() ? 'due' : 'scheduled');
            if ($status !== $r->status) {
                $r->forceFill(['status' => $status])->save();
                $changed->push($r);
            }
        }
        $this->benefits->deriveAll($orgId, $today);

        return $changed;
    }

    /** The sponsor, else a benefit owner who is not the manager; never the manager. */
    private function defaultReviewerId(Investment $i): ?int
    {
        if ($i->sponsor_id && (int) $i->sponsor_id !== (int) $i->manager_id) {
            return (int) $i->sponsor_id;
        }
        $owner = Benefit::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', '!=', 'cancelled')->whereNotNull('owner_id')
            ->where('owner_id', '!=', (int) $i->manager_id)->orderBy('id')->value('owner_id');

        return $owner ? (int) $owner : null;
    }

    /** The manager (and, for completing, whoever requested the delivered gate) cannot review the delivery. */
    private function assertIndependent(Investment $i, int $userId, string $field, bool $completing = false): void
    {
        $userId === (int) $i->manager_id && $this->fail($completing
            ? 'The investment manager delivered this project and cannot complete its value review (independence).'
            : 'The investment manager cannot be the reviewer of his own project; name the sponsor or a benefit owner.', $field);
        $completing && StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('gate', 'delivered')->where('requested_by_id', $userId)->exists()
            && $this->fail('You requested the delivery gate of this investment, so you cannot complete its value review (independence).', $field);
    }

    private function syncDates(Investment $i): void
    {
        $dates = ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', '!=', 'cancelled')->orderBy('due_on')->get()
            ->map(fn ($r) => $r->due_on->toDateString())->values()->all();
        Investment::withoutGlobalScopes()->whereKey($i->id)->get()->each(fn ($x) => $x->forceFill(['value_review_dates' => $dates])->save());
    }
}
