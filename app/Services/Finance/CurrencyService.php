<?php

namespace App\Services\Finance;

use App\Models\Finance\Currency;
use App\Models\Finance\FxRate;
use Carbon\CarbonInterface;
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
    public function rate(int $orgId, string $from, string $to, CarbonInterface $on): ?string
    {
        if ($from === $to) {
            return bcadd('1', '0', self::SCALE);
        }

        $pair = $this->pair($orgId, $from, $to, $on);
        if ($pair === null) {
            $base = $this->settings->baseCurrency($orgId);
            if ($from !== $base && $to !== $base) {
                $legA = $this->pair($orgId, $from, $base, $on);
                $legB = $this->pair($orgId, $base, $to, $on);
                if ($legA !== null && $legB !== null) {
                    $pair = bcmul($legA, $legB, self::WORK_SCALE);
                }
            }
        }

        return $pair === null ? null : $this->roundHalfUp($pair, self::SCALE);
    }

    /** @return array{amount:string, currency:string, fx_rate:string, base_amount:string} */
    public function convert(int $orgId, string $amount, string $currency, CarbonInterface $on): array
    {
        if (! preg_match('/^-?\d{1,16}(\.\d{1,8})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'The amount must be a plain decimal number.']);
        }
        $base = $this->settings->baseCurrency($orgId);
        $rate = $this->rate($orgId, $currency, $base, $on);
        if ($rate === null) {
            throw ValidationException::withMessages(['currency' => "No exchange rate from {$currency} to {$base} on or before {$on->toDateString()}."]);
        }

        $amountDp = $this->decimals($orgId, $currency);
        $baseDp = $this->decimals($orgId, $base);
        $rounded = $this->roundHalfUp($amount, $amountDp);

        return [
            'amount' => $rounded,
            'currency' => $currency,
            'fx_rate' => $rate,
            'base_amount' => $this->roundHalfUp(bcmul($rounded, $rate, self::WORK_SCALE), $baseDp),
        ];
    }

    private function decimals(int $orgId, string $code): int
    {
        $dp = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('code', $code)->value('decimals');

        return $dp === null ? 2 : max(0, min(8, (int) $dp));
    }

    public function setBase(Currency $c): void
    {
        if (! $c->is_active) {
            throw ValidationException::withMessages(['workflow' => 'An inactive currency cannot be the base currency.']);
        }
        Currency::withoutGlobalScopes()->where('organization_id', $c->organization_id)
            ->where('id', '!=', $c->id)->where('is_base', true)->get()
            ->each(fn (Currency $o) => $o->forceFill(['is_base' => false])->save());
        $c->forceFill(['is_base' => true])->save();
    }

    /** Direct rate, else the inverse of the opposite pair, at working precision (unrounded). */
    private function pair(int $orgId, string $from, string $to, CarbonInterface $on): ?string
    {
        $direct = $this->direct($orgId, $from, $to, $on);
        if ($direct !== null) {
            return $direct;
        }
        $inverse = $this->direct($orgId, $to, $from, $on);

        return $inverse === null || bccomp($inverse, '0', self::WORK_SCALE) <= 0 ? null : bcdiv('1', $inverse, self::WORK_SCALE);
    }

    private function direct(int $orgId, string $from, string $to, CarbonInterface $on): ?string
    {
        if ($from === $to) {
            return bcadd('1', '0', self::WORK_SCALE);
        }
        $rate = FxRate::withoutGlobalScopes()->where('organization_id', $orgId)
            ->where('from_currency', $from)->where('to_currency', $to)
            ->whereDate('rate_date', '<=', $on->toDateString())
            ->orderByDesc('rate_date')->orderByDesc('id')->value('rate');

        return $rate === null ? null : bcadd((string) $rate, '0', self::WORK_SCALE);
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
