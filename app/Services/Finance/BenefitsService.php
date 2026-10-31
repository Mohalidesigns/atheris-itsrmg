<?php

namespace App\Services\Finance;

use App\Models\Finance\Benefit;
use App\Models\Finance\BenefitReading;
use App\Models\Finance\FinanceDocument;
use App\Models\Finance\Investment;
use App\Models\Finance\Period;
use App\Models\User;
use App\Services\Finance\Concerns\LogsFinanceEvents;
use App\Services\Finance\Concerns\ValidatesFinanceInput;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Benefits and their readings (plan P5-12): realised value and percent, status derivation, and the investment-level average. */
class BenefitsService
{
    use LogsFinanceEvents, ValidatesFinanceInput;

    public const TRANSITIONS = [
        'record' => ['from' => ['planned', 'tracking', 'partially_realised', 'not_realised', 'realised'], 'to' => 'tracking'],
        'cancel' => ['from' => ['planned', 'tracking'], 'to' => 'cancelled'],
    ];

    public function __construct(private readonly FinanceSettings $settings) {}

    /** @param array<string,mixed> $data */
    public function store(Investment $i, array $data, User $by): Benefit
    {
        $i->is_open || $this->fail("A {$i->stage} investment cannot take new benefits.", 'title');
        $orgId = (int) $i->organization_id;
        $a = $this->attributes($orgId, $data, null);
        $b = Benefit::withoutGlobalScopes()->create($a + [
            'organization_id' => $orgId, 'created_by_id' => $by->id, 'investment_id' => $i->id, 'benefit_code' => Benefit::generateNextCode($orgId), 'status' => 'planned',
        ]);
        $this->audit($b, $by, 'created', "Benefit {$b->benefit_code} added to {$i->investment_code}");

        return $b->refresh();
    }

    /** @param array<string,mixed> $data */
    public function update(Benefit $b, array $data, User $by): Benefit
    {
        in_array($b->status, Benefit::FINAL, true) && $this->fail("A {$b->status} benefit cannot be edited.", 'title');
        $inv = Investment::withoutGlobalScopes()->findOrFail($b->investment_id);
        if ($this->pastApproval($inv)) {
            foreach (['target_value', 'baseline_value', 'unit', 'due_on', 'aggregation', 'type', 'measurement_method'] as $k) {
                if (! array_key_exists($k, $data)) {
                    continue;
                }
                $old = $b->{$k};
                $new = $data[$k];
                $same = match ($k) {
                    'due_on' => ($old?->toDateString()) === ($new ? substr((string) $new, 0, 10) : null),
                    'unit', 'aggregation', 'type', 'measurement_method' => (string) $old === (string) $new,
                    default => ($old === null || $old === '') ? ($new === null || $new === '') : ($new !== null && $new !== '' && is_numeric($new) && bccomp((string) $old, (string) $new, 2) === 0),
                };
                $same || $this->fail('Target, baseline, unit, due date, aggregation, type and measurement method are frozen once the investment is approved; cancel this benefit and register a new one.', $k);
            }
        }
        $b->fill($this->attributes((int) $b->organization_id, $data, $b))->save();
        $this->recompute($b);
        $this->audit($b, $by, 'updated', "Benefit {$b->benefit_code} updated");

        return $b->refresh();
    }

    public function record(Benefit $b, Period $p, string $value, ?FinanceDocument $doc, ?string $note, User $by): BenefitReading
    {
        $b->status === 'cancelled' && $this->fail('A cancelled benefit takes no readings.', 'value');
        $inv = Investment::withoutGlobalScopes()->findOrFail($b->investment_id);
        in_array($inv->stage, ['cancelled', 'closed'], true) && $this->fail("A {$inv->stage} investment takes no readings.", 'value');
        (int) $p->organization_id === (int) $b->organization_id || $this->fail('Unknown period for this bank.', 'period_id');
        $p->is_adjustment && $this->fail('Pick a regular fiscal period.', 'period_id');
        $p->status === 'locked' && $this->fail("{$p->name} is locked; record the reading in an open or closed period.", 'period_id');
        $v = $this->money($value, 'value', false, 'The reading');
        bccomp($v, '0', 2) < 0 && $this->fail('A reading cannot be negative.', 'value');
        $doc && (int) $doc->organization_id !== (int) $b->organization_id && $this->fail('Unknown document.', 'document_id');

        return DB::transaction(function () use ($b, $p, $v, $doc, $note, $by) {
            $r = BenefitReading::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $b->organization_id, 'benefit_id' => $b->id, 'period_id' => $p->id],
                ['created_by_id' => $by->id, 'value' => $v, 'document_id' => $doc?->id, 'note' => $note ? mb_substr(trim($note), 0, 500) : null, 'recorded_by_id' => $by->id],
            );
            $this->recompute($b->refresh());
            $this->audit($b, $by, 'reading', "Reading recorded for {$b->benefit_code} in {$p->name}", ['value' => $v, 'period' => $p->name]);

            return $r->refresh();
        });
    }

    private function pastApproval(Investment $i): bool
    {
        $at = array_search($i->stage, ['idea', 'business_case', 'approved', 'in_delivery', 'delivered', 'benefits_tracking', 'closed'], true);

        return $i->bac_baselined_at !== null || ($at !== false && $at >= 2);
    }

    public function cancel(Benefit $b, string $reason, User $by): Benefit
    {
        in_array($b->status, self::TRANSITIONS['cancel']['from'], true) || $this->fail("A {$b->status} benefit cannot be cancelled.");
        mb_strlen(trim($reason)) >= 10 || $this->fail('Give a reason of at least 10 characters.', 'reason');
        $b->forceFill(['status' => 'cancelled', 'cancel_reason' => trim($reason)])->save();
        $this->audit($b, $by, 'cancelled', "Benefit {$b->benefit_code} cancelled", ['reason' => trim($reason)]);

        return $b->refresh();
    }

    /**
     * @param  list<string>  $values  readings in period order
     * @return array{value:string,pct:?string}
     */
    public static function realised(string $aggregation, string $baseline, string $target, array $values): array
    {
        if (! $values) {
            return ['value' => '0.00', 'pct' => null];
        }
        if ($aggregation === 'sum') {
            $sum = array_reduce($values, fn ($c, $v) => bcadd($c, $v, 2), '0.00');
            $den = $target;
            $num = $sum;
            $value = $sum;
        } else {
            $value = bcadd((string) end($values), '0', 2);
            $num = bcsub($value, $baseline, 2);
            $den = bcsub($target, $baseline, 2);
        }
        if (bccomp($den, '0', 2) === 0) {
            return ['value' => $value, 'pct' => null];
        }
        $pct = bcmul(bcdiv($num, $den, 8), '100', 8);
        $neg = str_starts_with($pct, '-');
        $r = bcadd(ltrim($pct, '-'), '0.005', 8);

        return ['value' => $value, 'pct' => ($neg && bccomp($r, '0', 2) > 0 ? '-' : '').bcadd($r, '0', 2)];
    }

    public function deriveStatus(Benefit $b, ?CarbonInterface $today = null): string
    {
        if ($b->status === 'cancelled') {
            return 'cancelled';
        }
        $today ??= FinanceClock::today();
        $has = BenefitReading::withoutGlobalScopes()->where('benefit_id', $b->id)->exists();
        if (! $b->due_on || $today->toDateString() < $b->due_on->toDateString()) {
            return $has ? 'tracking' : 'planned';
        }
        $pct = $b->realised_pct;
        if ($pct !== null && bccomp((string) $pct, '100', 2) >= 0) {
            return 'realised';
        }

        return $pct !== null && bccomp((string) $pct, '0', 2) > 0 ? 'partially_realised' : 'not_realised';
    }

    /** Mean of the non-cancelled benefits' percent, each capped at 100; benefits with no reading count as 0. */
    public function investmentRealisedPct(Investment $i): ?string
    {
        return self::meanPct(Benefit::withoutGlobalScopes()->where('investment_id', $i->id)->get());
    }

    /** @param Collection<int,Investment> $investments @return array<int,?string> */
    public function pctsFor(Collection $investments): array
    {
        $by = Benefit::withoutGlobalScopes()->whereIn('investment_id', $investments->pluck('id'))->get()->groupBy('investment_id');

        return $investments->mapWithKeys(fn ($i) => [(int) $i->id => self::meanPct($by->get($i->id, collect()))])->all();
    }

    /** @param Collection<int,Benefit> $benefits */
    public static function meanPct(Collection $benefits): ?string
    {
        $live = $benefits->where('status', '!=', 'cancelled');
        if ($live->isEmpty()) {
            return null;
        }
        $sum = '0';
        foreach ($live as $b) {
            $p = $b->realised_pct === null ? '0' : (string) $b->realised_pct;
            $sum = bcadd($sum, bccomp($p, '100', 2) > 0 ? '100' : $p, 4);
        }

        return bcadd(bcdiv($sum, (string) $live->count(), 6), '0.005', 2);
    }

    /** Re-derives the stored realised value, percent, last period and status from the readings. */
    public function recompute(Benefit $b): void
    {
        $readings = BenefitReading::withoutGlobalScopes()->where('benefit_id', $b->id)
            ->join('fin_periods as p', 'p.id', '=', 'fin_benefit_readings.period_id')->join('fin_fiscal_years as y', 'y.id', '=', 'p.fiscal_year_id')
            ->orderBy('y.starts_on')->orderBy('p.sequence')->orderBy('p.id')->get(['fin_benefit_readings.value', 'fin_benefit_readings.period_id']);
        $r = self::realised((string) $b->aggregation, (string) $b->baseline_value, (string) $b->target_value, $readings->map(fn ($x) => (string) $x->value)->all());
        $b->forceFill([
            'realised_value' => $readings->isEmpty() ? null : $r['value'], 'realised_pct' => $r['pct'], 'last_reading_period_id' => $readings->last()?->period_id,
        ])->save();
        $b->forceFill(['status' => $this->deriveStatus($b)])->save();
    }

    /** Re-derives the status of every live benefit of a bank (used by the daily sweep). */
    public function deriveAll(int $orgId, ?CarbonInterface $today = null): int
    {
        $n = 0;
        foreach (Benefit::withoutGlobalScopes()->where('organization_id', $orgId)->where('status', '!=', 'cancelled')->get() as $b) {
            $s = $this->deriveStatus($b, $today);
            if ($s !== $b->status) {
                $b->forceFill(['status' => $s])->save();
                $n++;
            }
        }

        return $n;
    }

    /** @return array<string,mixed> */
    private function attributes(int $orgId, array $data, ?Benefit $existing): array
    {
        $out = [];
        $has = fn ($k) => array_key_exists($k, $data);
        if (! $existing || $has('title')) {
            $t = trim((string) ($data['title'] ?? ''));
            ($t === '' || mb_strlen($t) > 200) && $this->fail('Give the benefit a title (max 200 characters).', 'title');
            $out['title'] = $t;
        }
        foreach (['description', 'measurement_method'] as $k) {
            $has($k) && $out[$k] = ($v = trim((string) ($data[$k] ?? ''))) === '' ? null : mb_substr($v, 0, 5000);
        }
        if (! $existing || $has('type')) {
            in_array($data['type'] ?? null, Benefit::TYPES, true) || $this->fail('Choose a benefit type.', 'type');
            $out['type'] = $data['type'];
        }
        $unit = $out['unit'] = $existing && ! $has('unit') ? $existing->unit : ($data['unit'] ?? null);
        in_array($unit, Benefit::UNITS, true) || $this->fail('Choose a unit.', 'unit');
        if (! $existing || $has('aggregation') || $has('unit')) {
            $agg = $data['aggregation'] ?? ($existing && ! $has('unit') ? $existing->aggregation : (in_array($unit, ['pct', 'score'], true) ? 'latest' : 'sum'));
            in_array($agg, Benefit::AGGREGATIONS, true) || $this->fail('Choose how readings combine.', 'aggregation');
            $out['aggregation'] = $agg;
        }
        $out['currency'] = $unit === 'currency' ? $this->settings->baseCurrency($orgId) : null;
        if ($has('owner_id') || ! $existing) {
            $out['owner_id'] = ! empty($data['owner_id']) ? (int) $this->inBank(User::class, $orgId, $data['owner_id'], 'owner_id', 'Pick an owner from this bank.')->id : null;
        }
        if ($has('baseline_value') || ! $existing) {
            $out['baseline_value'] = $this->money($data['baseline_value'] ?? '0', 'baseline_value', false, 'The baseline');
        }
        if ($has('target_value') || ! $existing) {
            $out['target_value'] = $this->money($data['target_value'] ?? null, 'target_value', false, 'The target');
        }
        if ($has('due_on')) {
            $out['due_on'] = ! empty($data['due_on']) ? $this->date($data['due_on'], 'due_on')->toDateString() : null;
        }

        return $out;
    }
}
