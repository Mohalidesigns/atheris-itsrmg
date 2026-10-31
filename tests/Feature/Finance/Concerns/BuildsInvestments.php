<?php

namespace Tests\Feature\Finance\Concerns;

use App\Models\Finance\BudgetLine;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\Investment;
use App\Models\Finance\StageGate;
use App\Models\User;
use App\Services\Finance\BusinessCaseService;
use App\Services\Finance\InvestmentScoring;
use App\Services\Finance\InvestmentService;
use App\Services\Finance\StageGateService;

/**
 * Fixtures of the P5 investment tests: the P4 bank (NGN, FY2027, "today" 2027-04-05) plus a manager (IT Budget Owner), a sponsor
 * (Investment Sponsor) and the stage deciders already built by the earlier fixtures: $this->head (Head of IT),
 * $this->cfo (Chief Financial Officer), $this->cio and $this->md (Managing Director). Everything runs through the services.
 */
trait BuildsInvestments
{
    use BuildsMonitoring;

    protected User $manager;

    protected User $sponsor;

    protected User $headofit;

    protected function bootInvestments(string $now = '2027-04-05 09:00:00'): void
    {
        $this->bootMonitoring($now);
        $this->manager = $this->userWith('IT Budget Owner');
        $this->sponsor = $this->userWith('Investment Sponsor', ['job_title' => 'Head of Digital Channels']);
        $this->headofit = $this->head;
        $this->actingAs($this->fin);
    }

    /** @param array<string,mixed> $over */
    protected function makeInvestment(array $over = [], ?User $by = null): Investment
    {
        return app(InvestmentService::class)->store($over + [
            'title' => 'Mobile app re-platform', 'type' => 'project', 'rgt' => 'grow', 'driver' => 'strategic', 'sponsor_id' => $this->sponsor->id, 'manager_id' => $this->manager->id,
            'cost_centre_id' => $this->cc->id, 'fiscal_year_id' => $this->fy->id, 'starts_on' => '2027-04-01', 'planned_ends_on' => '2027-12-31',
        ], $by ?? $this->fin)->refresh();
    }

    /** A draft business case with the reference cash flows unless told otherwise (cost 1,000,000 at year 0, 300,000 a year for five years, 10 %). */
    protected function makeBusinessCase(Investment $i, ?array $costs = null, ?array $benefits = null, string $rate = '10', int $horizon = 5): BusinessCase
    {
        $svc = app(BusinessCaseService::class);
        $bc = BusinessCase::withoutGlobalScopes()->where('investment_id', $i->id)->where('status', 'draft')->first() ?? $svc->createVersion($i, $this->fin);

        return $svc->update($bc, [
            'horizon_years' => $horizon, 'discount_rate_pct' => $rate,
            'costs' => $costs ?? [['year' => 0, 'capex' => '1000000', 'opex' => '0', 'run' => '0']],
            'benefits' => $benefits ?? array_map(fn ($y) => ['year' => $y, 'amount' => '300000'], range(1, 5)),
            'risks_summary' => 'Vendor delay and adoption risk.', 'recommended_option' => 'Build',
        ], $this->fin);
    }

    protected function scoreAll(Investment $i, int $score = 3): Investment
    {
        return app(InvestmentScoring::class)->score($i, array_fill_keys(Investment::CRITERIA, $score), $this->fin);
    }

    protected function tagLines(Investment $i, BudgetLine ...$lines): array
    {
        return app(InvestmentService::class)->linkLines($i, array_map(fn ($l) => $l->id, $lines), $this->fin);
    }

    /** Walks idea to the `approved` stage: business case, scores, gate request and the approval by $this->head (unconfigured workflow: one other approver). */
    protected function approvedInvestment(array $over = [], string $cost = '1000000'): Investment
    {
        $i = $this->makeInvestment($over);
        $gates = app(StageGateService::class);
        $gates->advance($i, 'business_case', ['problem_agreed'], [], null, $this->fin);
        $this->makeBusinessCase($i->refresh(), [['year' => 0, 'capex' => $cost, 'opex' => '0', 'run' => '0']]);
        $this->scoreAll($i);
        $gate = $gates->advance($i->refresh(), 'approved', ['options_considered'], [], null, $this->fin);
        $this->approveGate($gate, $this->headofit);

        return $i->refresh();
    }

    protected function approveGate(StageGate $gate, User ...$deciders): StageGate
    {
        foreach ($deciders as $d) {
            app(StageGateService::class)->approve($gate->refresh(), $d);
        }

        return $gate->refresh();
    }

    /** Moves an approved investment to in_delivery (a baseline line tagged, a manual criterion ticked). */
    protected function inDelivery(Investment $i): Investment
    {
        $this->tagLines($i, $this->monLines[0]);
        app(StageGateService::class)->advance($i->refresh(), 'in_delivery', ['delivery_plan'], [], null, $this->fin);

        return $i->refresh();
    }
}
