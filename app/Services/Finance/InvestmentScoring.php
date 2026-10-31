<?php

namespace App\Services\Finance;

use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentScore;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use Illuminate\Support\Facades\DB;

/**
 * Priority scoring and ranking (plan P5-10). Five criteria scored 1 to 5, five is the most favourable; delivery risk is entered as a
 * risk (5 = high risk) and inverted. priority_score = sum(effective / 5 x weight), 0 to 100. Weights are snapshotted on every score row.
 */
class InvestmentScoring
{
    use LogsFinanceEvents;

    public function __construct(private readonly FinanceSettings $settings) {}

    /** @return array<string,int> */
    public function weights(int $orgId): array
    {
        return $this->settings->investmentWeights($orgId);
    }

    /**
     * @param  array<string,mixed>  $scores  criterion => 1..5 (all five)
     * @param  array<string,mixed>|null  $notes  criterion => note
     */
    public function score(Investment $i, array $scores, User $by, ?array $notes = null): Investment
    {
        in_array($i->stage, ['closed', 'cancelled'], true) && $this->fail("A {$i->stage} investment cannot be scored.", 'scores');
        (int) $i->organization_id === (int) $by->organization_id || $this->fail('That investment belongs to another bank.', 'scores');
        in_array((int) $by->id, array_filter([(int) $i->sponsor_id, (int) $i->manager_id]), true) && $this->fail('The sponsor and the manager cannot score their own investment.', 'scores');
        $clean = $this->validated($scores);
        $orgId = (int) $i->organization_id;
        $weights = $this->weights($orgId);

        DB::transaction(function () use ($i, $clean, $weights, $by, $orgId, $notes) {
            foreach ($clean as $criterion => $score) {
                InvestmentScore::withoutGlobalScopes()->updateOrCreate(
                    ['organization_id' => $orgId, 'investment_id' => $i->id, 'criterion' => $criterion],
                    ['created_by_id' => $by->id, 'score' => $score, 'weight_pct' => $weights[$criterion], 'weighted' => $this->weighted($criterion, $score, $weights[$criterion]),
                        'note' => isset($notes[$criterion]) ? mb_substr(trim((string) $notes[$criterion]), 0, 500) : null, 'scored_by_id' => $by->id],
                );
            }
            $total = self::priorityScore($clean, $weights);
            $i->forceFill(['priority_score' => $total])->save();
            $this->rank($orgId, $i->fiscal_year_id ? (int) $i->fiscal_year_id : null);
            $this->audit($i, $by, 'scored', "{$i->investment_code} scored {$total}", ['scores' => $clean, 'priority_score' => $total]);
        });

        return $i->refresh();
    }

    /** @param array<string,int|string> $scores @param array<string,int> $weights */
    public static function priorityScore(array $scores, array $weights): string
    {
        $sum = '0';
        foreach (Investment::CRITERIA as $c) {
            $effective = $c === 'delivery_risk' ? 6 - (int) $scores[$c] : (int) $scores[$c];
            $sum = bcadd($sum, bcmul(bcdiv((string) $effective, '5', 8), (string) ($weights[$c] ?? 0), 8), 8);
        }

        return bcadd(bcadd($sum, '0.005', 8), '0', 2);
    }

    /** Orders the open, scored investments of the funding fiscal year and writes priority_rank; everything else loses its rank. */
    public function rank(int $orgId, ?int $fiscalYearId): int
    {
        $rows = Investment::withoutGlobalScopes()->where('organization_id', $orgId)
            ->when($fiscalYearId, fn ($q) => $q->where('fiscal_year_id', $fiscalYearId), fn ($q) => $q->whereNull('fiscal_year_id'))->get();
        $ranked = $rows->filter(fn (Investment $i) => $i->is_open && $i->priority_score !== null)
            ->sort(function (Investment $a, Investment $b) {
                return [(float) $b->priority_score, (int) $b->is_regulatory, $a->planned_cost === null ? PHP_FLOAT_MAX : (float) $a->planned_cost, $a->id]
                    <=> [(float) $a->priority_score, (int) $a->is_regulatory, $b->planned_cost === null ? PHP_FLOAT_MAX : (float) $b->planned_cost, $b->id];
            })->values();
        $n = 0;
        foreach ($ranked as $pos => $i) {
            $rank = $pos + 1;
            if ((int) $i->priority_rank !== $rank) {
                Investment::withoutGlobalScopes()->whereKey($i->id)->toBase()->update(['priority_rank' => $rank]);
            }
            $n++;
        }
        $ids = $ranked->pluck('id')->all();
        $rows->reject(fn ($i) => in_array($i->id, $ids, true) || $i->priority_rank === null)
            ->each(fn ($i) => Investment::withoutGlobalScopes()->whereKey($i->id)->toBase()->update(['priority_rank' => null]));

        return $n;
    }

    /** Recomputes every open scored investment with the bank's current weights and re-ranks each funding year. Returns the number rescored. */
    public function rescore(int $orgId): int
    {
        $weights = $this->weights($orgId);
        $n = 0;
        $years = [];
        foreach (Investment::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('stage', Investment::OPEN_STAGES)->get() as $i) {
            $rows = InvestmentScore::withoutGlobalScopes()->where('investment_id', $i->id)->get();
            if ($rows->count() < count(Investment::CRITERIA)) {
                continue;
            }
            $scores = $rows->pluck('score', 'criterion')->all();
            foreach ($rows as $r) {
                $r->forceFill(['weight_pct' => $weights[$r->criterion], 'weighted' => $this->weighted($r->criterion, (int) $r->score, $weights[$r->criterion])])->save();
            }
            $i->forceFill(['priority_score' => self::priorityScore($scores, $weights)])->save();
            $years[$i->fiscal_year_id ?? 0] = $i->fiscal_year_id;
            $n++;
        }
        foreach ($years as $fy) {
            $this->rank($orgId, $fy ? (int) $fy : null);
        }

        return $n;
    }

    private function weighted(string $criterion, int $score, int $weight): string
    {
        $effective = $criterion === 'delivery_risk' ? 6 - $score : $score;

        return bcadd(bcadd(bcmul(bcdiv((string) $effective, '5', 8), (string) $weight, 8), '0.005', 8), '0', 2);
    }

    /** @return array<string,int> */
    private function validated(array $scores): array
    {
        $out = [];
        foreach (Investment::CRITERIA as $c) {
            $v = $scores[$c] ?? null;
            (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v >= 1 && (int) $v <= 5
                || $this->fail('Score every criterion from 1 to 5 ('.str_replace('_', ' ', $c).').', 'scores');
            $out[$c] = (int) $v;
        }

        return $out;
    }
}
