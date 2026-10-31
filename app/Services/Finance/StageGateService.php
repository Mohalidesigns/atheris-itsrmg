<?php

namespace App\Services\Finance;

use App\Models\Finance\Benefit;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentChange;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\InvestmentScore;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\Risk;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stage gates (plan P5-4, P5-5, P5-7, P5-8). Each request for a gate is one StageGate row. The `approved` and `delivered` gates (and an
 * investment change beyond tolerance) go through `finance.business_case_approval`; the others pass on their checklist alone.
 */
class StageGateService
{
    use LogsFinanceEvents;

    public const PROCESS = 'finance.business_case_approval';

    public const PERMISSION = 'approve it-finance-investments';

    private const ORDER = ['idea', 'business_case', 'approved', 'in_delivery', 'delivered', 'benefits_tracking', 'closed'];

    public function __construct(
        private readonly FinanceApprovals $approvals,
        private readonly InvestmentService $investments,
        private readonly BusinessCaseService $businessCases,
        private readonly ValueReviewService $reviews,
    ) {}

    /** @return array{to_stage:string,requires_approval:bool,criteria:list<array<string,mixed>>}|null */
    public function nextGate(Investment $i): ?array
    {
        $at = array_search($i->stage, self::ORDER, true);
        if ($at === false || ! isset(self::ORDER[$at + 1])) {
            return null;
        }
        $to = self::ORDER[$at + 1];

        return ['to_stage' => $to, 'requires_approval' => in_array($to, (array) config('finance.investments.approval_gates'), true), 'criteria' => $this->criteria($i, $to)];
    }

    /**
     * @param  list<string>  $ticked  manual criteria the requester ticked
     * @param  array<string,mixed>  $payload
     * @return list<array{key:string,label:string,auto:bool,met:bool,detail:?string}>
     */
    public function criteria(Investment $i, string $to, array $ticked = [], array $payload = []): array
    {
        $out = [];
        foreach ((array) config("finance.investments.gate_criteria.{$to}", []) as [$key, $label, $auto]) {
            [$met, $detail] = $auto ? $this->evaluate($i, $key, $payload) : [in_array($key, $ticked, true), 'Confirm manually'];
            $out[] = ['key' => $key, 'label' => $label, 'auto' => (bool) $auto, 'met' => (bool) $met, 'detail' => $detail];
        }

        return $out;
    }

    /** @param array<string,mixed> $payload @return array{0:bool,1:?string} */
    private function evaluate(Investment $i, string $key, array $payload): array
    {
        $orgId = (int) $i->organization_id;
        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ['draft', 'submitted'])->orderByDesc('version_no')->first();

        return match ($key) {
            'sponsor_named' => [$i->sponsor_id !== null, $i->sponsor_id ? null : 'Name a sponsor'],
            'manager_named' => [$i->manager_id !== null, $i->manager_id ? null : 'Name a manager'],
            'cost_centre_set' => [$i->cost_centre_id !== null, $i->cost_centre_id ? null : 'Set a cost centre'],
            'business_case_complete' => [(bool) ($bc && ! empty($bc->costs) && bccomp((string) $bc->one_off_cost, '0', 2) > 0 && $bc->horizon_years && $bc->discount_rate_pct !== null),
                $bc ? (bccomp((string) $bc->one_off_cost, '0', 2) > 0 ? null : 'Enter the investment costs') : 'Prepare a business case'],
            'scored' => [InvestmentScore::withoutGlobalScopes()->where('investment_id', $i->id)->count() >= count(Investment::CRITERIA), 'Score all five criteria'],
            'risks_recorded' => [filled($bc?->risks_summary) || InvestmentLink::withoutGlobalScopes()->where('investment_id', $i->id)->where('linkable_type', Risk::class)->exists(), 'Record risks or link a risk'],
            'regulatory_reference' => [! $i->is_regulatory || filled($i->regulatory_reference), 'Give the regulatory reference'],
            'funded' => $this->funded($i, $bc),
            'start_date' => [$i->starts_on !== null, 'Set the start date'],
            'actual_end' => $this->actualEnd($i, $payload),
            'benefits_registered' => [Benefit::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', '!=', 'cancelled')
                ->whereNotNull('owner_id')->whereNotNull('due_on')->whereNotNull('target_value')->exists(), 'Register a benefit with owner, target and due date'],
            'reviews_scheduled' => [ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', '!=', 'cancelled')->exists(), 'No value review is scheduled'],
            'reviews_done' => [! ValueReview::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ValueReview::OPEN)->exists(), 'Complete or cancel the open value reviews'],
            'benefits_final' => [! Benefit::withoutGlobalScopes()->where('investment_id', $i->id)->whereNotIn('status', Benefit::FINAL)->exists(), 'Every benefit needs a final status'],
            default => [false, 'Unknown criterion'],
        };
    }

    /** Σ current base of the tagged baseline lines must cover BAC (or the draft business case's one-off cost). @return array{0:bool,1:?string} */
    private function funded(Investment $i, ?BusinessCase $bc): array
    {
        $need = $i->bac !== null ? (string) $i->bac : ($bc && $bc->one_off_cost !== null ? (string) $bc->one_off_cost : null);
        $ids = BudgetLine::withoutGlobalScopes()->where('organization_id', $i->organization_id)->where('investment_id', $i->id)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $have = app(InvestmentFinancials::class)->allocated($ids);
        if (! $ids || bccomp($have, '0', 2) <= 0) {
            return [false, 'Tag a baseline budget line'];
        }
        if ($need !== null && bccomp($have, $need, 2) < 0) {
            return [false, 'Tagged baseline lines hold '.$have.' of '.bcadd($need, '0', 2).'; gap '.bcsub($need, $have, 2)];
        }

        return [true, null];
    }

    /** @return array{0:bool,1:?string} */
    private function actualEnd(Investment $i, array $payload): array
    {
        $d = $payload['actual_ends_on'] ?? null;
        if (! $d) {
            return [false, 'Give the actual end date'];
        }
        try {
            $day = CarbonImmutable::parse((string) $d)->toDateString();
        } catch (\Throwable) {
            return [false, 'Give a valid date'];
        }
        if ($day > FinanceClock::today()->toDateString()) {
            return [false, 'The actual end date cannot be in the future'];
        }
        if ($i->starts_on && $day < $i->starts_on->toDateString()) {
            return [false, 'The end date is before the start date'];
        }

        return [true, $day];
    }

    /**
     * @param  list<string>  $ticked
     * @param  array<string,mixed>  $payload
     */
    public function advance(Investment $i, string $to, array $ticked, array $payload, ?string $note, User $by): StageGate
    {
        (int) $i->organization_id === (int) $by->organization_id || $this->fail('That investment belongs to another bank.', 'gate');

        return DB::transaction(function () use ($i, $to, $ticked, $payload, $note, $by) {
            // one pending request per investment: serialise on the investment row before the check (P5 security M5)
            Investment::withoutGlobalScopes()->whereKey($i->id)->lockForUpdate()->first();
            $i->refresh();
            $i->status === 'on_hold' && $this->fail('This investment is on hold; resume it first.', 'gate');
            $next = $this->nextGate($i) ?? $this->fail("A {$i->stage} investment has no further gate.", 'gate');
            $next['to_stage'] === $to || $this->fail("The next gate of a {$i->stage} investment is {$next['to_stage']}.", 'gate');
            StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->exists() && $this->fail('A gate request for this investment is already awaiting approval.', 'gate');
            $criteria = $this->criteria($i, $to, $ticked, $payload);
            $unmet = array_values(array_filter($criteria, fn ($c) => ! $c['met']));
            $unmet && $this->fail('Not every criterion is met: '.implode('; ', array_map(fn ($c) => $c['label'].($c['auto'] && $c['detail'] ? " ({$c['detail']})" : ''), $unmet)).'.', 'criteria');

            $needsApproval = in_array($to, (array) config('finance.investments.approval_gates'), true);
            $bc = $to === 'approved' ? BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'draft')->orderByDesc('version_no')->first() : null;
            $to === 'approved' && ! $bc && $this->fail('Prepare and save a draft business case first.', 'business_case');

            $gate = StageGate::withoutGlobalScopes()->create([
                'organization_id' => $i->organization_id, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'gate' => $to, 'from_stage' => $i->stage,
                'status' => $needsApproval ? 'submitted' : 'passed', 'criteria' => $criteria,
                'payload' => $to === 'delivered' ? ['actual_ends_on' => collect($criteria)->firstWhere('key', 'actual_end')['detail'] ?? null] : null,
                'business_case_id' => $bc?->id, 'requested_by_id' => $by->id, 'requested_at' => now(), 'request_note' => $note ? trim($note) : null,
                'approval_base_amount' => $to === 'approved' ? (string) $bc->one_off_cost : (string) ($i->bac ?? $i->planned_cost ?? '0'),
            ]);
            if ($needsApproval) {
                $gate->setRelation('investment', $i);
                $gate->forceFill(['sod_excluded_ids' => $gate->liveSodExcludedIds()])->save();
                $to === 'approved' && $this->businessCases->markSubmitted($bc, $by);
                $request = $this->approvals->start(self::PROCESS, $gate, $by);
                $request && $gate->forceFill(['approval_request_id' => $request->id])->save();
                $this->audit($gate, $by, 'requested', "Gate {$to} requested for {$i->investment_code}");
            } else {
                $gate->forceFill(['passed_at' => now(), 'passed_by_id' => $by->id])->save();
                $this->investments->applyStage($i, $to, $by);
                $this->audit($gate, $by, 'passed', "Gate {$to} passed for {$i->investment_code}");
            }

            return $gate->refresh();
        });
    }

    public function requestChange(InvestmentChange $c, User $by): StageGate
    {
        $i = Investment::withoutGlobalScopes()->findOrFail($c->investment_id);
        $bac = (string) ($i->bac ?? '0');
        $amount = bccomp((string) $c->cost_delta, '0', 2) > 0 ? bcadd($bac, (string) $c->cost_delta, 2) : $bac;

        return DB::transaction(function () use ($c, $i, $by, $amount) {
            $gate = StageGate::withoutGlobalScopes()->create([
                'organization_id' => $i->organization_id, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'gate' => 'change', 'from_stage' => $i->stage,
                'status' => 'submitted', 'criteria' => [], 'payload' => ['change' => $c->change_code, 'cost_delta' => (string) $c->cost_delta, 'schedule_delta_days' => (int) $c->schedule_delta_days],
                'investment_change_id' => $c->id, 'approval_base_amount' => $amount, 'requested_by_id' => $by->id, 'requested_at' => now(), 'request_note' => $c->reason,
            ]);
            $gate->setRelation('investment', $i);
            $gate->forceFill(['sod_excluded_ids' => $gate->liveSodExcludedIds()])->save();
            $request = $this->approvals->start(self::PROCESS, $gate, $by);
            if ($request) {
                $gate->forceFill(['approval_request_id' => $request->id])->save();
                $c->forceFill(['approval_request_id' => $request->id])->save();
            }
            $this->audit($gate, $by, 'requested', "Change {$c->change_code} sent for approval on {$i->investment_code}");

            return $gate->refresh();
        });
    }

    /** Returns a message while stages remain; the final approval applies the gate. */
    public function approve(StageGate $g, User $by, ?string $note = null): string
    {
        $this->assertSubmitted($g);
        $this->assertApprover($g, $by);

        return DB::transaction(function () use ($g, $by, $note) {
            $g = StageGate::withoutGlobalScopes()->lockForUpdate()->findOrFail($g->id);
            $this->assertSubmitted($g);
            $this->approvals->assertZeroStepSoD(self::PROCESS, $g, $by, 'requested_by_id', 'sponsor_id', self::PERMISSION);
            if (! $this->approvals->configured(self::PROCESS, (int) $g->organization_id)) {
                $this->approvals->assertDefaultApprover($g, $by, 'requested_by_id', 'sponsor_id', self::PERMISSION);
            } else {
                $this->approvals->assertNoStageStranded(self::PROCESS, $g, $by);
                if ($message = $this->approvals->approve(self::PROCESS, $g, $by, $note)) {
                    $this->audit($g, $by, 'stage_approved', $message);

                    return $message;
                }
            }
            $i = Investment::withoutGlobalScopes()->findOrFail($g->investment_id);
            if (in_array($g->gate, ['approved', 'delivered'], true)) {
                $unmet = array_values(array_filter($this->criteria($i, $g->gate, [], (array) $g->payload), fn ($c) => $c['auto'] && ! $c['met']));
                $unmet && $this->fail('An automatic criterion is no longer met: '.implode('; ', array_map(fn ($c) => $c['label'].($c['detail'] ? " ({$c['detail']})" : ''), $unmet)).'.', 'criteria');
            }
            $g->forceFill(['status' => 'approved', 'decided_by_id' => $by->id, 'decided_at' => now(), 'passed_at' => now(), 'passed_by_id' => $by->id])->save();
            match ($g->gate) {
                'approved' => $this->finaliseApproved($g, $i, $by),
                'delivered' => $this->finaliseDelivered($g, $i, $by),
                'change' => $this->finaliseChange($g, $by),
                default => $this->fail('Unexpected gate.'),
            };
            $this->approvals->finalised(self::PROCESS, $g, $by, $note);
            $this->audit($g, $by, 'approved', "Gate {$g->gate} approved for {$i->investment_code}");

            return "{$g->gate_title} approved.";
        });
    }

    public function reject(StageGate $g, User $by, string $note): StageGate
    {
        $this->assertSubmitted($g);
        blank(trim($note)) && $this->fail('Give a reason.', 'note');
        $this->assertApprover($g, $by);
        if (! $this->approvals->configured(self::PROCESS, (int) $g->organization_id)) {
            $this->approvals->assertDefaultApprover($g, $by, 'requested_by_id', 'sponsor_id', self::PERMISSION);
        }
        DB::transaction(function () use ($g, $by, $note) {
            $this->approvals->decline(self::PROCESS, $g, $by, $note, 'rejected');
            $g->forceFill(['status' => 'rejected', 'rejection_reason' => $note, 'decided_by_id' => $by->id, 'decided_at' => now()])->save();
            if ($g->business_case_id && ($bc = BusinessCase::withoutGlobalScopes()->find($g->business_case_id))) {
                $this->businessCases->markRejected($bc, $note, $by);
            }
            if ($g->investment_change_id && ($c = InvestmentChange::withoutGlobalScopes()->find($g->investment_change_id))) {
                $c->forceFill(['status' => 'rejected', 'rejection_reason' => $note, 'decided_by_id' => $by->id, 'decided_at' => now()])->save();
            }
            $this->audit($g, $by, 'rejected', "Gate {$g->gate} rejected", ['note' => $note]);
        });

        return $g->refresh();
    }

    public function withdraw(StageGate $g, User $by): StageGate
    {
        $this->assertSubmitted($g);
        $i = Investment::withoutGlobalScopes()->findOrFail($g->investment_id);
        (int) $g->requested_by_id === (int) $by->id || (int) $i->manager_id === (int) $by->id || $this->fail('Only the requester or the investment manager can withdraw a gate request.');
        DB::transaction(fn () => $this->close($g, $by));

        return $g->refresh();
    }

    /** Withdraws a pending gate because the investment is being cancelled. */
    public function cancelPending(Investment $i, User $by): void
    {
        foreach (StageGate::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'submitted')->get() as $g) {
            $this->close($g, $by);
        }
    }

    private function close(StageGate $g, User $by): void
    {
        $g->forceFill(['status' => 'withdrawn'])->save();
        $this->approvals->withdraw(self::PROCESS, $g, $by);
        if ($g->business_case_id && ($bc = BusinessCase::withoutGlobalScopes()->find($g->business_case_id)) && $bc->status === 'submitted') {
            $this->businessCases->markWithdrawn($bc, $by);
        }
        if ($g->investment_change_id && ($c = InvestmentChange::withoutGlobalScopes()->find($g->investment_change_id)) && $c->status === 'submitted') {
            $c->forceFill(['status' => 'withdrawn'])->save();
        }
        $this->audit($g, $by, 'withdrawn', "Gate {$g->gate} request withdrawn");
    }

    private function finaliseApproved(StageGate $g, Investment $i, User $by): void
    {
        $bc = BusinessCase::withoutGlobalScopes()->findOrFail($g->business_case_id);
        $this->businessCases->markApproved($bc, $by);
        $i->forceFill(['bac' => $bc->one_off_cost, 'bac_baseline_amount' => $bc->one_off_cost, 'bac_baselined_at' => now(), 'bac_baselined_tolerance_pct' => $i->cost_tolerance_pct, 'planned_cost' => $bc->one_off_cost, 'eac' => null, 'eac_reason' => null])->save();
        $this->investments->applyStage($i, 'approved', $by);
    }

    private function finaliseDelivered(StageGate $g, Investment $i, User $by): void
    {
        $day = $this->actualEnd($i, (array) $g->payload);
        $day[0] || $this->fail($day[1] ?? 'The actual end date is no longer valid.', 'actual_ends_on');
        $i->forceFill(['actual_ends_on' => $day[1]])->save();
        $this->investments->applyStage($i, 'delivered', $by);
        $this->reviews->schedule($i->refresh(), $by);
    }

    private function finaliseChange(StageGate $g, User $by): void
    {
        $c = InvestmentChange::withoutGlobalScopes()->findOrFail($g->investment_change_id);
        app(InvestmentChangeService::class)->apply($c, $by, true);
    }

    private function assertSubmitted(StageGate $g): void
    {
        $g->status === 'submitted' || $this->fail("A {$g->status} gate request cannot be decided.");
    }

    private function assertApprover(StageGate $g, User $by): void
    {
        (int) $by->organization_id === (int) $g->organization_id || $this->fail('This gate belongs to another bank.', 'approval');
        $by->can(self::PERMISSION) || $this->fail('You need the "'.self::PERMISSION.'" permission to decide a gate.', 'approval');
    }
}
