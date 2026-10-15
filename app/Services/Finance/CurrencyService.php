<?php

namespace App\Services\Finance;

use App\Models\Finance\Currency;
use App\Models\Finance\FxRate;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exchange-rate lookup and conversion into the bank's base currency (ATH-FIN-001 FIN-01.05).
 * bcmath at scale 8; every money write in later phases stores amount, currency, fx_rate and base_amount.
 */
class CurrencyService
{
    private const SCALE = 8;

    private const WORK_SCALE = 18;

    public function __construct(private readonly FinanceSettings $settings) {}

    /** Latest rate on or before $on: direct, else inverse, else triangulated through the base currency; rounded half-up once to 8 dp. */
    public function rate(int $orgId, string $from, string $to, CarbonInterface $on, string $rateType = 'spot'): ?string
    {
        return $this->quote($orgId, $from, $to, $on, $rateType)['rate'] ?? null;
    }

    /**
     * Rate of the requested type, falling back through config('finance.fx.fallback') in order.
     * rate_date is the date of the rate used (the older leg for a triangulated rate).
     *
     * @return array{rate:string, rate_date:string, rate_type:string}|null
     */
    public function quote(int $orgId, string $from, string $to, CarbonInterface $on, string $rateType = 'spot'): ?array
    {
        if ($from === $to) {
            return ['rate' => bcadd('1', '0', self::SCALE), 'rate_date' => $on->toDateString(), 'rate_type' => $rateType];
        }

        foreach (array_merge([$rateType], config('finance.fx.fallback')[$rateType] ?? []) as $type) {
            $found = $this->lookup($orgId, $from, $to, $on, $type);
            if ($found !== null) {
                return ['rate' => $this->roundHalfUp($found[0], self::SCALE), 'rate_date' => $found[1], 'rate_type' => $type];
            }
        }

        return null;
    }

    /** @return array{amount:string, currency:string, fx_rate:string, base_amount:string, rate_type:string, rate_date:string} */
    public function convert(int $orgId, string $amount, string $currency, CarbonInterface $on, string $rateType = 'spot'): array
    {
        if (! preg_match('/^-?\d{1,16}(\.\d{1,8})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'The amount must be a plain decimal number.']);
        }
        $base = $this->settings->baseCurrency($orgId);
        $quote = $this->quote($orgId, $currency, $base, $on, $rateType);
        if ($quote === null) {
            throw ValidationException::withMessages(['currency' => "No exchange rate from {$currency} to {$base} on or before {$on->toDateString()}."]);
        }

        $amountDp = $this->decimals($orgId, $currency);
        $baseDp = $this->decimals($orgId, $base);
        $rounded = $this->roundHalfUp($amount, $amountDp);

        return [
            'amount' => $rounded,
            'currency' => $currency,
            'fx_rate' => $quote['rate'],
            'base_amount' => $this->roundHalfUp(bcmul($rounded, $quote['rate'], self::WORK_SCALE), $baseDp),
            'rate_type' => $quote['rate_type'],
            'rate_date' => $quote['rate_date'],
        ];
    }

    /** Direct, inverse or triangulated rate of one type at working precision, with the date it was struck. */
    private function lookup(int $orgId, string $from, string $to, CarbonInterface $on, string $type): ?array
    {
        $pair = $this->pair($orgId, $from, $to, $on, $type);
        if ($pair === null) {
            $base = $this->settings->baseCurrency($orgId);
            if ($from !== $base && $to !== $base) {
                $legA = $this->pair($orgId, $from, $base, $on, $type);
                $legB = $this->pair($orgId, $base, $to, $on, $type);
                if ($legA !== null && $legB !== null) {
                    $pair = [bcmul($legA[0], $legB[0], self::WORK_SCALE), min($legA[1], $legB[1])];
                }
            }
        }

        return $pair;
    }

    private function decimals(int $orgId, string $code): int
    {
        $dp = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('code', $code)->value('decimals');

        return $dp === null ? 2 : max(0, min(8, (int) $dp));
    }

    /** Why the base currency cannot change (money rows exist, soft-deleted included), or null. */
    public function baseLockedReason(int $orgId): ?string
    {
        foreach (array_unique(config('finance.money_tables')) as $table) {
            if (DB::table($table)->where('organization_id', $orgId)->exists()) {
                return 'The base currency cannot change once budgets, transfers or supplementary requests exist.';
            }
        }

        return null;
    }

    public function setBase(Currency $c): void
    {
        if (! $c->is_active) {
            throw ValidationException::withMessages(['workflow' => 'An inactive currency cannot be the base currency.']);
        }
        if ($reason = $this->baseLockedReason((int) $c->organization_id)) {
            throw ValidationException::withMessages(['workflow' => $reason]);
        }
        Currency::withoutGlobalScopes()->where('organization_id', $c->organization_id)
            ->where('id', '!=', $c->id)->where('is_base', true)->get()
            ->each(fn (Currency $o) => $o->forceFill(['is_base' => false])->save());
        $c->forceFill(['is_base' => true])->save();
    }

    /** Direct rate, else the inverse of the opposite pair, at working precision (unrounded), with its date. */
    private function pair(int $orgId, string $from, string $to, CarbonInterface $on, string $type): ?array
    {
        $direct = $this->direct($orgId, $from, $to, $on, $type);
        if ($direct !== null) {
            return $direct;
        }
        $inverse = $this->direct($orgId, $to, $from, $on, $type);

        return $inverse === null || bccomp($inverse[0], '0', self::WORK_SCALE) <= 0 ? null : [bcdiv('1', $inverse[0], self::WORK_SCALE), $inverse[1]];
    }

    private function direct(int $orgId, string $from, string $to, CarbonInterface $on, string $type): ?array
    {
        if ($from === $to) {
            return [bcadd('1', '0', self::WORK_SCALE), $on->toDateString()];
        }
        $row = FxRate::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('from_currency', $from)->where('to_currency', $to)->where('rate_type', $type)
            ->whereDate('rate_date', '<=', $on->toDateString())
            ->orderByDesc('rate_date')->orderByDesc('id')->first(['rate', 'rate_date']);

        return $row === null ? null : [bcadd((string) $row->rate, '0', self::WORK_SCALE), $row->rate_date->toDateString()];
    }

    private function roundHalfUp(string $value, int $dp): string
    {
        $half = $dp === 0 ? '0.5' : '0.'.str_repeat('0', $dp).'5';
        $negative = str_starts_with($value, '-');

        return $negative
            ? bcsub($value, $half, $dp)
            : bcadd($value, $half, $dp);
    }
}
