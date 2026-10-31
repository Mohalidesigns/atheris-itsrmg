<?php

namespace App\Services\Finance;

use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use InvalidArgumentException;

/**
 * Business-case versions (plan P5-8). Only one draft or submitted version at a time, only while the investment is an idea or has a
 * business case in preparation. Submitting is requesting the `approved` gate (StageGateService); this service performs the state moves.
 */
class BusinessCaseService
{
    use LogsFinanceEvents;

    public const TRANSITIONS = [
        'submit' => ['from' => ['draft'], 'to' => 'submitted'],
        'approve' => ['from' => ['submitted'], 'to' => 'approved'],
        'reject' => ['from' => ['submitted'], 'to' => 'rejected'],
        'withdraw' => ['from' => ['submitted'], 'to' => 'draft'],
        'supersede' => ['from' => ['approved'], 'to' => 'superseded'],
    ];

    public function __construct(private readonly FinanceSettings $settings) {}

    public function createVersion(Investment $i, User $by, ?BusinessCase $copyFrom = null): BusinessCase
    {
        in_array($i->stage, ['idea', 'business_case'], true)
            || $this->fail('A business case is prepared while the investment is an idea or at the business-case stage; later changes go through investment changes.', 'business_case');
        (int) $i->organization_id === (int) $by->organization_id || $this->fail('That investment belongs to another bank.', 'business_case');
        BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->whereIn('status', ['draft', 'submitted'])->exists()
            && $this->fail('This investment already has a business case in draft or awaiting approval.', 'business_case');

        $orgId = (int) $i->organization_id;
        $latest = $copyFrom ?? BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->orderByDesc('version_no')->first();
        $next = ((int) BusinessCase::withoutGlobalScopes()->withTrashed()->where('investment_id', $i->id)->max('version_no')) + 1;
        $bc = BusinessCase::withoutGlobalScopes()->create([
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'version_no' => $next, 'status' => 'draft',
            'horizon_years' => $latest?->horizon_years ?? (int) config('finance.investments.default_horizon_years'),
            'discount_rate_pct' => $latest?->discount_rate_pct ?? $this->settings->discountRatePct($orgId),
            'option_summary' => $latest?->option_summary, 'recommended_option' => $latest?->recommended_option,
            'costs' => $latest?->costs ?? [], 'benefits' => $latest?->benefits ?? [], 'non_financial_benefits' => $latest?->non_financial_benefits,
            'assumptions' => $latest?->assumptions, 'risks_summary' => $latest?->risks_summary,
        ]);
        $this->recalculate($bc);
        $this->audit($bc, $by, 'version_created', "Business case v{$next} created for {$i->investment_code}", ['copied_from' => $latest?->version_no]);

        return $bc->refresh();
    }

    /** @param array<string,mixed> $data */
    public function update(BusinessCase $bc, array $data, User $by): BusinessCase
    {
        $bc->status === 'draft' || $this->fail("A {$bc->status} business case cannot be edited; start a new version.", 'business_case');
        (int) $bc->organization_id === (int) $by->organization_id || $this->fail('That business case belongs to another bank.', 'business_case');
        $merged = $data + [
            'horizon_years' => $bc->horizon_years, 'discount_rate_pct' => $bc->discount_rate_pct, 'costs' => $bc->costs, 'benefits' => $bc->benefits,
        ];
        $calc = $this->calculate($merged, (int) $bc->organization_id);
        $nonFinancial = array_key_exists('non_financial_benefits', $data) ? $this->nonFinancial($data['non_financial_benefits']) : $bc->non_financial_benefits;

        $text = [];
        foreach (['option_summary' => 10000, 'recommended_option' => 200, 'assumptions' => 10000, 'risks_summary' => 10000] as $k => $max) {
            if (array_key_exists($k, $data)) {
                $v = trim((string) $data[$k]);
                mb_strlen($v) > $max && $this->fail("That text is too long (max {$max} characters).", $k);
                $text[$k] = $v === '' ? null : $v;
            }
        }
        $bc->forceFill([
            'horizon_years' => (int) $merged['horizon_years'], 'discount_rate_pct' => $this->rate($merged['discount_rate_pct']),
            'costs' => $this->normalisedCosts($merged['costs']), 'benefits' => $this->normalisedBenefits($merged['benefits']),
            'non_financial_benefits' => $nonFinancial,
        ] + $this->resultColumns($calc) + $text)->save();
        Investment::withoutGlobalScopes()->whereKey($bc->investment_id)->toBase()->update(['planned_cost' => $calc['one_off_cost']]);
        $this->audit($bc, $by, 'updated', "Business case v{$bc->version_no} saved", ['npv' => $calc['npv'], 'irr_pct' => $calc['irr_pct']]);

        return $bc->refresh();
    }

    /**
     * Live evaluation without saving. Defaults the rate and horizon from the bank's settings.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function evaluate(array $data, int $orgId): array
    {
        return $this->calculate($data, $orgId);
    }

    public function markSubmitted(BusinessCase $bc, User $by): void
    {
        $this->move($bc, 'submit');
        $bc->forceFill(['submitted_by_id' => $by->id, 'submitted_at' => now(), 'rejection_reason' => null])->save();
        $this->audit($bc, $by, 'submitted', "Business case v{$bc->version_no} submitted");
    }

    public function markApproved(BusinessCase $bc, User $by): void
    {
        $this->move($bc, 'approve');
        BusinessCase::withoutGlobalScopes()->where('investment_id', $bc->investment_id)->where('status', 'approved')->where('id', '!=', $bc->id)->get()
            ->each(fn (BusinessCase $old) => $old->forceFill(['status' => 'superseded'])->save());
        $bc->forceFill(['approved_by_id' => $by->id, 'approved_at' => now()])->save();
        $this->audit($bc, $by, 'approved', "Business case v{$bc->version_no} approved");
    }

    public function markRejected(BusinessCase $bc, string $reason, ?User $by = null): void
    {
        $this->move($bc, 'reject');
        $bc->forceFill(['rejection_reason' => $reason])->save();
        $this->audit($bc, $by, 'rejected', "Business case v{$bc->version_no} rejected", ['reason' => $reason]);
    }

    public function markWithdrawn(BusinessCase $bc, ?User $by = null): void
    {
        $this->move($bc, 'withdraw');
        $this->audit($bc, $by, 'withdrawn', "Business case v{$bc->version_no} withdrawn to draft");
    }

    private function move(BusinessCase $bc, string $action): void
    {
        $t = self::TRANSITIONS[$action];
        in_array($bc->status, $t['from'], true) || $this->fail("A {$bc->status} business case cannot be moved to {$t['to']}.", 'business_case');
        $bc->forceFill(['status' => $t['to']])->save();
    }

    private function recalculate(BusinessCase $bc): void
    {
        $calc = $this->calculate(['horizon_years' => $bc->horizon_years, 'discount_rate_pct' => $bc->discount_rate_pct, 'costs' => $bc->costs ?? [], 'benefits' => $bc->benefits ?? []], (int) $bc->organization_id);
        $bc->forceFill($this->resultColumns($calc))->save();
    }

    /** @return array<string,mixed> */
    private function calculate(array $data, int $orgId): array
    {
        $horizon = (int) ($data['horizon_years'] ?? config('finance.investments.default_horizon_years'));
        $rate = $data['discount_rate_pct'] ?? $this->settings->discountRatePct($orgId);
        try {
            return BusinessCaseCalculator::evaluate((array) ($data['costs'] ?? []), (array) ($data['benefits'] ?? []), is_string($rate) || is_int($rate) || is_float($rate) ? $rate : '', $horizon);
        } catch (InvalidArgumentException $e) {
            $this->fail($e->getMessage(), 'costs');
        }
    }

    /** @return array<string,mixed> */
    private function resultColumns(array $calc): array
    {
        return [
            'npv' => $calc['npv'], 'irr_pct' => $calc['irr_pct'], 'irr_multiple' => $calc['irr_multiple'], 'payback_months' => $calc['payback_months'],
            'discounted_payback_months' => $calc['discounted_payback_months'], 'roi_pct' => $calc['roi_pct'], 'tco' => $calc['tco'],
            'one_off_cost' => $calc['one_off_cost'], 'total_benefit' => $calc['total_benefit'],
        ];
    }

    private function rate(mixed $r): string
    {
        return bcadd(is_scalar($r) ? (string) $r : '0', '0', 3);
    }

    /** Stored costs keep only the known keys, as plain 2-decimal strings (the calculator has already validated them). */
    private function normalisedCosts(mixed $costs): array
    {
        return array_map(fn ($row) => ['year' => (int) $row['year'], 'capex' => $this->dec($row['capex'] ?? null), 'opex' => $this->dec($row['opex'] ?? null), 'run' => $this->dec($row['run'] ?? null)], array_values((array) $costs));
    }

    private function normalisedBenefits(mixed $benefits): array
    {
        return array_map(fn ($row) => ['year' => (int) $row['year'], 'amount' => $this->dec($row['amount'] ?? null)], array_values((array) $benefits));
    }

    private function dec(mixed $v): string
    {
        return bcadd($v === null || $v === '' ? '0' : (is_float($v) ? number_format($v, 2, '.', '') : (string) $v), '0', 2);
    }

    /** @return list<array{label:string,score:int,note:?string}> */
    private function nonFinancial(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $r) {
            $label = trim((string) ($r['label'] ?? ''));
            $score = $r['score'] ?? null;
            ($label === '' || mb_strlen($label) > 200) && $this->fail('Each non-financial benefit needs a label (max 200 characters).', 'non_financial_benefits');
            (is_int($score) || (is_string($score) && ctype_digit($score))) && (int) $score >= 1 && (int) $score <= 5 || $this->fail('Score each non-financial benefit from 1 to 5.', 'non_financial_benefits');
            $out[] = ['label' => $label, 'score' => (int) $score, 'note' => isset($r['note']) ? mb_substr(trim((string) $r['note']), 0, 500) : null];
        }

        return $out;
    }
}
