<?php

namespace Tests\Feature\Finance\Concerns;

use App\Models\Finance\Actual;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Period;
use App\Models\Finance\Setting;
use App\Models\Finance\VarianceNote;
use App\Models\User;
use App\Services\Finance\BudgetService;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\VarianceService;

/**
 * Fixtures of the P4 monitoring, variance and period-close tests: the P3 bank (NGN, FY2027) on "today" 2027-04-05, so January to
 * March have ended, with one baseline of three lines (12,000,000 each, phased equally, 1,000,000 a month) in $this->ccChild, owned by
 * the requester. $this->line is the first of them, so every P3 helper (expense, po, invoice) works against it. Variance thresholds are
 * amber 5 %, red 10 % and a floor of 100,000.
 */
trait BuildsMonitoring
{
    use BuildsExpenses;

    /** @var list<BudgetLine> */
    protected array $monLines = [];

    protected function bootMonitoring(string $now = '2027-04-05 09:00:00'): void
    {
        $this->bootContracts($now);
        $this->requester = $this->userWith('IT Budget Owner');
        $this->md = $this->userWith('Finance Approver', ['job_title' => 'Managing Director']);
        $this->ccChild = CostCentre::create(['code' => 'CC-1A', 'name' => 'Ops Support', 'owner_id' => $this->requester->id, 'parent_id' => $this->cc->id]);

        $svc = app(BudgetService::class);
        $b = $svc->createVersion($this->openCycle(), $this->ccChild, 'zero_based', $this->requester);
        foreach (['Core banking AMC', 'Network support', 'Cloud hosting'] as $name) {
            $svc->addLine($b, $this->lineData(['annual_amount' => '12000000.00', 'description' => $name]), $this->requester);
        }
        $svc->submit($b->refresh(), $this->requester);
        $svc->approve($b->refresh(), $this->cfo);
        $b = $svc->baseline($b->refresh(), $this->fin)->refresh();
        $this->monLines = $b->lines()->orderBy('line_no')->get()->all();
        $this->line = $this->monLines[0];

        $setting = app(FinanceSettings::class)->for($this->org->id);
        Setting::withoutGlobalScopes()->whereKey($setting->id)->update(['variance_amber_pct' => 5, 'variance_red_pct' => 10, 'variance_abs_floor' => 100000]);
        app(FinanceSettings::class)->flush();
        $this->actingAs($this->fin);
    }

    protected function periodSeq(int $n): Period
    {
        return Period::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('fiscal_year_id', $this->fy->id)->where('sequence', $n)->firstOrFail();
    }

    /** A posted, matched actual written straight into the ledger (base currency = line currency NGN). */
    protected function postActual(BudgetLine $l, Period $p, string $base, array $over = []): Actual
    {
        return Actual::withoutGlobalScopes()->create($over + [
            'organization_id' => $this->org->id, 'created_by_id' => $this->fin->id, 'fiscal_year_id' => $p->fiscal_year_id, 'period_id' => $p->id, 'posted_on' => $p->ends_on->toDateString(),
            'source' => 'adjustment', 'source_ref' => 'T-'.random_int(1, 99999999), 'posting_key' => 'test:'.$l->id.':'.$p->id.':'.random_int(1, 99999999),
            'cost_centre_id' => $l->cost_centre_id, 'budget_line_id' => $l->id, 'description' => 'Test posting', 'amount' => $base, 'currency' => 'NGN', 'fx_rate' => 1,
            'base_amount' => $base, 'match_status' => 'matched',
        ]);
    }

    /** Explains every open note of the period as the cost-centre owner. */
    protected function explainAll(Period $p, ?User $by = null): int
    {
        $by ??= $this->requester;
        $n = 0;
        foreach (VarianceNote::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('period_id', $p->id)->where('status', 'open')->get() as $note) {
            app(VarianceService::class)->explain($note, 'Explained in the test: the spend was planned and approved by the owner.', 'Monitor next month', $by);
            $n++;
        }

        return $n;
    }
}
