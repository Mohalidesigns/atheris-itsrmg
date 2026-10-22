<?php

namespace Tests\Feature\Finance;

use App\Models\Finance\Actual;
use App\Models\Finance\Benefit;
use App\Models\Finance\BenefitReading;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\Finance\ValueReview;
use App\Notifications\Finance\ValueReviewDueNotification;
use App\Services\Finance\BenefitsService;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;
use App\Services\Finance\ValueReviewService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Finance\Concerns\BuildsInvestments;
use Tests\TestCase;

/**
 * Plan P5 §5.2: benefit readings and realised percentages, benefit status at the due date, value reviews scheduled on delivery,
 * the daily sweep and who may complete a review. "Today" is 2027-04-05 (the P4 bank, NGN, FY2027).
 */
class BenefitsAndValueReviewTest extends TestCase
{
    use BuildsInvestments, RefreshDatabase;

    private Investment $inv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInvestments();
        $this->inv = $this->inDelivery($this->approvedInvestment());
    }

    /** @param array<string,mixed> $over */
    private function benefit(array $over = [], ?Investment $inv = null): Benefit
    {
        return app(BenefitsService::class)->store($inv ?? $this->inv, $over + [
            'title' => 'Payment fees saved', 'type' => 'cost_saving', 'unit' => 'currency', 'target_value' => '1000', 'owner_id' => $this->manager->id, 'due_on' => '2027-12-31',
        ], $this->fin);
    }

    private function record(Benefit $b, int $seq, string $value): BenefitReading
    {
        return app(BenefitsService::class)->record($b->refresh(), $this->periodSeq($seq), $value, null, null, $this->manager);
    }

    /** Delivers the investment on a date and returns the three reviews, oldest first. */
    private function deliver(Investment $i, string $endsOn): array
    {
        $gate = app(StageGateService::class)->advance($i->refresh(), 'delivered', ['acceptance', 'handover'], ['actual_ends_on' => $endsOn], null, $this->fin);
        $this->approveGate($gate, $this->headofit);

        return ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->orderBy('due_on')->get()->all();
    }

    /** FIN-05.06: realised percent moves with each reading: sum against the target, latest against baseline to target (also a falling target). */
    public function test_realised_pct_updates_on_readings(): void
    {
        $sum = $this->benefit(['target_value' => '1000']);
        $this->assertNull($sum->realised_pct);
        $this->record($sum, 1, '250');
        $this->assertSame('25.00', (string) $sum->refresh()->realised_pct);
        $this->assertSame('250.00', (string) $sum->realised_value);
        $this->record($sum, 2, '250');
        $this->assertSame('50.00', (string) $sum->refresh()->realised_pct);
        $this->assertSame($this->periodSeq(2)->id, (int) $sum->last_reading_period_id);

        $latest = $this->benefit(['title' => 'Straight-through rate', 'unit' => 'pct', 'baseline_value' => '10', 'target_value' => '50']);
        $this->assertSame('latest', $latest->aggregation); // a percentage defaults to the latest reading
        $this->record($latest, 1, '20');
        $this->record($latest, 2, '30');
        $this->assertSame('50.00', (string) $latest->refresh()->realised_pct); // (30 - 10) / (50 - 10)
        $this->assertSame('30.00', (string) $latest->realised_value);

        $falling = $this->benefit(['title' => 'Hours to onboard a customer', 'type' => 'productivity', 'unit' => 'hours', 'aggregation' => 'latest', 'baseline_value' => '80', 'target_value' => '20']);
        $this->record($falling, 1, '50');
        $this->assertSame('50.00', (string) $falling->refresh()->realised_pct); // (50 - 80) / (20 - 80)
        $this->record($falling, 2, '20');
        $this->assertSame('100.00', (string) $falling->refresh()->realised_pct);
    }

    /** Re-recording the same period replaces the reading and recomputes. */
    public function test_re_recording_a_period_replaces_the_reading(): void
    {
        $b = $this->benefit();
        $this->record($b, 1, '400');
        $this->record($b, 1, '600');
        $this->assertSame(1, BenefitReading::withoutGlobalScopes()->where('benefit_id', $b->id)->count());
        $this->assertSame('60.00', (string) $b->refresh()->realised_pct);
        $this->assertRefused(fn () => $this->record($b, 1, '-5'), 'value');
        $this->assertRefused(fn () => app(BenefitsService::class)->record($b, $this->periodSeq(1), 'abc', null, null, $this->manager), 'value');
    }

    /** A locked period takes no reading; a closed one does. */
    public function test_locked_period_refused_closed_allowed(): void
    {
        $b = $this->benefit();
        Period::withoutGlobalScopes()->whereKey($this->periodSeq(1)->id)->update(['status' => 'locked']);
        Period::withoutGlobalScopes()->whereKey($this->periodSeq(2)->id)->update(['status' => 'closed']);
        $this->assertRefused(fn () => $this->record($b, 1, '100'), 'period_id', 'locked');
        $this->assertSame('100.00', (string) $this->record($b, 2, '100')->value);
        $this->assertSame('10.00', (string) $b->refresh()->realised_pct);
    }

    /** Status follows the readings and the due date: planned, tracking, then realised, partially or not realised once it is due. */
    public function test_status_derivation_at_due_date(): void
    {
        $svc = app(BenefitsService::class);
        $none = $this->benefit(['title' => 'Never measured', 'due_on' => '2027-06-30']);
        $part = $this->benefit(['title' => 'Partly measured', 'due_on' => '2027-06-30']);
        $full = $this->benefit(['title' => 'Fully measured', 'due_on' => '2027-06-30']);
        $this->assertSame('planned', $none->status);
        $this->record($part, 1, '300');
        $this->record($full, 1, '1000');
        $this->assertSame(['planned', 'tracking', 'tracking'], [$none->refresh()->status, $part->refresh()->status, $full->refresh()->status]);

        $svc->deriveAll($this->org->id, CarbonImmutable::parse('2027-07-01'));
        $this->assertSame(['not_realised', 'partially_realised', 'realised'], [$none->refresh()->status, $part->refresh()->status, $full->refresh()->status]);

        $gone = $this->benefit(['title' => 'Dropped measure', 'due_on' => '2027-06-30']);
        $svc->cancel($gone, 'The measure was dropped when the scope changed.', $this->fin);
        $svc->deriveAll($this->org->id, CarbonImmutable::parse('2027-07-01'));
        $this->assertSame('cancelled', $gone->refresh()->status); // derivation leaves a cancelled benefit alone
        $this->assertRefused(fn () => $svc->cancel($none, 'Cannot cancel a benefit that is already final', $this->fin));
        $this->assertRefused(fn () => $svc->update($full->refresh(), ['title' => 'Edited'], $this->fin), 'title'); // a final benefit is frozen
        $this->assertRefused(fn () => $svc->cancel($full, 'Trying to cancel a realised benefit', $this->fin));
        $this->assertRefused(fn () => $this->record($gone, 2, '10'), 'value');
    }

    /** The investment's realised percent is the mean of its live benefits, each capped at 100, an unmeasured benefit counting zero. */
    public function test_investment_benefits_realised_pct(): void
    {
        $svc = app(BenefitsService::class);
        $this->assertNull($svc->investmentRealisedPct($this->inv));
        $a = $this->benefit(['title' => 'A']);
        $b = $this->benefit(['title' => 'B']);
        $c = $this->benefit(['title' => 'C']);
        $this->record($a, 1, '2000'); // 200 %, counts as 100
        $this->record($b, 1, '500');
        $this->assertSame('50.00', $svc->investmentRealisedPct($this->inv)); // (100 + 50 + 0) / 3
        $svc->cancel($c, 'Dropped from the programme scope.', $this->fin);
        $this->assertSame('75.00', $svc->investmentRealisedPct($this->inv)); // (100 + 50) / 2
    }

    /** FIN-05.07: delivery schedules the 3, 6 and 12 month reviews from the actual end date, idempotently. */
    public function test_reviews_scheduled_on_delivery(): void
    {
        $i = $this->approvedInvestment(['title' => 'Month-end start', 'starts_on' => '2026-11-30']);
        $this->tagLines($i, $this->monLines[1]);
        app(StageGateService::class)->advance($i->refresh(), 'in_delivery', ['delivery_plan'], [], null, $this->fin);
        $reviews = $this->deliver($i, '2027-01-31');
        $this->assertSame(['pir_3m', 'pir_6m', 'pir_12m'], array_map(fn ($r) => $r->review_type, $reviews));
        $this->assertSame(['2027-04-30', '2027-07-31', '2028-01-31'], array_map(fn ($r) => $r->due_on->toDateString(), $reviews));
        $this->assertSame(array_fill(0, 3, $this->sponsor->id), array_map(fn ($r) => $r->reviewer_id, $reviews)); // the sponsor reviews by default, never the manager
        $this->assertSame(['2027-04-30', '2027-07-31', '2028-01-31'], $i->refresh()->value_review_dates);
        $this->assertSame(0, app(ValueReviewService::class)->schedule($i, $this->fin));
        $this->assertSame('2027-01-31', $i->actual_ends_on->toDateString());
    }

    /** FIN-05.07: the sweep marks a review due inside the lead window and notifies the reviewer and sponsor once, however often it runs. */
    public function test_sweep_notifies_once(): void
    {
        Notification::fake();
        $r = ValueReview::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $this->inv->id, 'review_type' => 'pir_3m', 'due_on' => '2027-04-12',
            'reviewer_id' => $this->manager->id, 'status' => 'scheduled',
        ]);
        $far = ValueReview::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'investment_id' => $this->inv->id, 'review_type' => 'pir_6m', 'due_on' => '2027-07-12',
            'reviewer_id' => $this->manager->id, 'status' => 'scheduled',
        ]);

        $this->assertSame('0 value-review notification(s) sent.', $this->sweepCommand('2027-03-01')); // 42 days ahead: outside the 14 day lead
        $this->assertSame('1 value-review notification(s) sent.', $this->sweepCommand('2027-04-05'));
        $this->assertSame('0 value-review notification(s) sent.', $this->sweepCommand('2027-04-05')); // second run: nothing new
        $this->assertSame('due', $r->refresh()->status);
        $this->assertSame('scheduled', $far->refresh()->status);
        foreach ([$this->manager, $this->sponsor] as $u) {
            $this->assertSame(1, Notification::sent($u, ValueReviewDueNotification::class)->count());
        }
        $this->assertSame(0, Notification::sent($this->headofit, ValueReviewDueNotification::class)->count());

        // one overdue notice per offset (1, 7, 14, 30 days late), each once
        $total = fn () => Notification::sent($this->manager, ValueReviewDueNotification::class)->count();
        foreach (['2027-04-13' => 2, '2027-04-19' => 3, '2027-04-26' => 4, '2027-05-12' => 5] as $day => $expected) {
            $this->sweepCommand($day);
            $this->sweepCommand($day);
            $this->assertSame($expected, $total(), $day);
        }
        $this->assertSame('overdue', $r->refresh()->status);

        // moving the due date re-arms the series
        app(ValueReviewService::class)->update($r, ['due_on' => '2027-07-01', 'reason' => 'Go-live slipped by seven weeks.'], $this->fin);
        $this->assertSame('scheduled', $r->refresh()->status);
        $this->sweepCommand('2027-06-25');
        $this->sweepCommand('2027-06-25');
        $this->assertSame(6, $total());
        Carbon::setTestNow('2027-04-05 09:00:00');
    }

    private function sweepCommand(string $date): string
    {
        Carbon::setTestNow("{$date} 07:45:00");
        Artisan::call('fin:value-reviews-sweep', ['--date' => $date]);

        return trim(Artisan::output());
    }

    /** Rescheduling a review needs a reason; completed and cancelled reviews cannot be rescheduled. */
    public function test_reschedule_needs_a_reason(): void
    {
        $r = app(ValueReviewService::class)->adHoc($this->inv, '2027-09-01', $this->sponsor->id, $this->fin);
        $this->assertRefused(fn () => app(ValueReviewService::class)->update($r, ['due_on' => '2027-10-01'], $this->fin), 'reason');
        $this->assertRefused(fn () => app(ValueReviewService::class)->update($r, ['reviewer_id' => $this->cfo->id + 9999], $this->fin));
        app(ValueReviewService::class)->update($r, ['due_on' => '2027-10-01', 'reason' => 'Moved after the steering committee.'], $this->fin);
        $this->assertSame('2027-10-01', $r->refresh()->due_on->toDateString());
        $this->assertSame(['2027-10-01'], $this->inv->refresh()->value_review_dates);
    }

    /** FIN-05.07: completing a review stores the realised percent and a cost outcome of under, on or over the approved budget. */
    public function test_complete_computes_pct_and_cost_outcome(): void
    {
        $b = $this->benefit();
        $this->record($b, 1, '500');
        app(InvestmentService::class)->progress($this->inv->refresh(), ['percent_complete' => '100'], $this->manager);
        $outcome = function (string $spent) {
            Actual::withoutGlobalScopes()->where('budget_line_id', $this->monLines[0]->id)->delete();
            $this->postActual($this->monLines[0], $this->periodSeq(1), $spent);
            $r = app(ValueReviewService::class)->adHoc($this->inv->refresh(), '2027-09-01', $this->sponsor->id, $this->fin);
            $done = app(ValueReviewService::class)->complete($r, ['summary' => 'Reviewed with the benefit owner and Finance.', 'lessons' => 'Name the owners earlier.'], $this->sponsor);
            $this->assertSame('completed', $done->status);
            $this->assertSame('2027-04-05', $done->completed_on->toDateString());
            $this->assertSame('50.00', (string) $done->benefits_realised_pct);

            return $done->cost_outcome;
        };
        $this->assertSame('under', $outcome('900000')); // EAC 900,000 is more than 5 % below the BAC of 1,000,000
        $this->assertSame('on', $outcome('1030000'));
        $this->assertSame('over', $outcome('1200000'));

        $r = app(ValueReviewService::class)->adHoc($this->inv->refresh(), '2027-10-01', null, $this->fin);
        $this->assertRefused(fn () => app(ValueReviewService::class)->complete($r, ['summary' => 'too short'], $this->sponsor), 'summary');
        $done = app(ValueReviewService::class)->complete($r, ['summary' => 'Reviewed with the benefit owner and Finance.'], $this->sponsor);
        $this->assertRefused(fn () => app(ValueReviewService::class)->complete($done, ['summary' => 'Completing the review a second time.'], $this->sponsor), 'summary');
    }

    /** The named reviewer may complete a review without edit rights; anyone else with only view rights is refused. */
    public function test_reviewer_may_complete_without_edit_permission(): void
    {
        $reviewer = $this->userWith('Viewer');
        $reviewer->roles()->detach();
        $reviewer->givePermissionTo('view it-finance');
        $other = $this->userWith('Viewer');
        $other->roles()->detach();
        $other->givePermissionTo('view it-finance');
        $this->assertFalse($reviewer->can('edit it-finance'));

        $r = app(ValueReviewService::class)->adHoc($this->inv, '2027-09-01', $reviewer->id, $this->fin);
        $this->assertContains($this->actingAs($other)->post(route('finance.value-reviews.complete', $r), ['summary' => 'Reviewed with the benefit owner and Finance.'])->status(), [403, 404]);
        $this->assertSame('scheduled', $r->refresh()->status);
        $this->actingAs($reviewer)->post(route('finance.value-reviews.complete', $r), ['summary' => 'Reviewed with the benefit owner and Finance.'])->assertRedirect();
        $this->assertSame('completed', $r->refresh()->status);
        $this->assertSame($reviewer->id, (int) $r->completed_by_id);
    }

    /** A benefit reading is recorded by its owner (or an editor) and reviewers and owners can see the investment they are named on. */
    public function test_owner_records_a_reading_and_others_cannot(): void
    {
        $owner = $this->userWith('Viewer');
        $owner->roles()->detach();
        $owner->givePermissionTo('view it-finance');
        $stranger = $this->userWith('Viewer');
        $stranger->roles()->detach();
        $stranger->givePermissionTo('view it-finance');
        $b = $this->benefit(['owner_id' => $owner->id]);
        $period = $this->periodSeq(1);
        $this->assertContains($this->actingAs($stranger)->post(route('finance.benefits.readings.store', $b), ['period_id' => $period->id, 'value' => '100'])->status(), [403, 404]);
        $this->actingAs($owner)->post(route('finance.benefits.readings.store', $b), ['period_id' => $period->id, 'value' => '100'])->assertRedirect();
        $this->assertSame('10.00', (string) $b->refresh()->realised_pct);
    }

    /** P5 review domain H3: the reviewer defaults to the sponsor, never the manager, and the manager or delivery requester cannot complete a review. */
    public function test_value_review_is_independent_of_delivery(): void
    {
        $reviews = ValueReview::withoutGlobalScopes()->where('investment_id', $this->inv->id)->get();
        $this->assertCount(0, $reviews);
        $rows = $this->deliver($this->inv, '2027-04-01');
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame($this->sponsor->id, (int) $r->reviewer_id);
        }
        $svc = app(ValueReviewService::class);
        $this->assertRefused(fn () => $svc->adHoc($this->inv->refresh(), '2027-09-01', $this->manager->id, $this->fin), 'reviewer_id', 'cannot be the reviewer');
        $this->assertRefused(fn () => $svc->update($rows[0], ['reviewer_id' => $this->manager->id], $this->fin), 'reviewer_id');
        $this->assertRefused(fn () => $svc->complete($rows[0]->refresh(), ['summary' => 'Reviewed with the benefit owner and Finance.'], $this->manager), 'summary', 'independence');
        $this->assertRefused(fn () => $svc->complete($rows[0]->refresh(), ['summary' => 'Reviewed with the benefit owner and Finance.'], $this->fin), 'summary', 'requested the delivery gate');
        $this->assertFalse($this->manager->can('completeReview', $rows[0]->refresh()));
        $svc->complete($rows[0]->refresh(), ['summary' => 'Reviewed with the benefit owner and Finance.'], $this->sponsor);
        $this->assertSame('completed', $rows[0]->refresh()->status);
    }

    /** With no usable sponsor the default falls back to a benefit owner who is not the manager. */
    public function test_default_reviewer_falls_back_to_a_benefit_owner(): void
    {
        $owner = $this->userWith('Viewer');
        $this->benefit(['owner_id' => $owner->id]);
        Investment::withoutGlobalScopes()->whereKey($this->inv->id)->update(['sponsor_id' => $this->manager->id]);
        $rows = $this->deliver($this->inv->refresh(), '2027-04-01');
        $this->assertSame($owner->id, (int) $rows[0]->reviewer_id);
    }
}
