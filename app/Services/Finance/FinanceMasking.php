<?php

namespace App\Services\Finance;

use App\Models\Kri;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One place that hides money from viewers without `view it-finance-amounts` outside the finance pages (security M2):
 * stored board-pack content and the finance-sourced KRIs. Status, thresholds, counts and non-money figures stay visible.
 */
class FinanceMasking
{
    public function canSeeAmounts(User $u): bool
    {
        return $u->can('view it-finance-amounts');
    }

    /** Masks the finance sections and the finance KRI rows of a stored board-pack content array. */
    public function boardContent(array $content, User $u): array
    {
        if ($this->canSeeAmounts($u)) {
            return $content;
        }

        return array_map(function ($s) {
            if (! is_array($s) || ! is_array($s['data'] ?? null)) {
                return $s;
            }
            switch ($s['key'] ?? null) {
                case 'it_financial_position':
                    foreach (FinanceMetrics::BOARD_MONEY_KEYS as $k) {
                        array_key_exists($k, $s['data']) && $s['data'][$k] = null;
                    }
                    $s['data']['top_variances'] = array_map(fn ($t) => array_merge($t, ['variance_pct' => null]), $s['data']['top_variances'] ?? []);
                    break;
                case 'it_investment_portfolio':
                    foreach (FinanceMetrics::BOARD_INVESTMENT_MONEY_KEYS as $k) {
                        array_key_exists($k, $s['data']) && $s['data'][$k] = null;
                    }
                    $s['data']['by_stage'] = array_map(fn ($r) => array_merge($r, ['bac' => null]), $s['data']['by_stage'] ?? []);
                    break;
                case 'kri_panel':
                    if (isset($s['data']['kris']) && is_array($s['data']['kris'])) {
                        $s['data']['kris'] = array_map(fn ($k) => $this->maskKriPayload($k, null), $s['data']['kris']);
                    }
                    break;
            }

            return $s;
        }, $content);
    }

    public function kriValueMasked(string $code, ?User $u): bool
    {
        return in_array($code, (array) config('finance.masked_kri_codes', []), true) && ! ($u && $this->canSeeAmounts($u));
    }

    /** A reading's value as text for flashes and messages: "hidden" when the viewer may not see the finance KRI's money. */
    public function kriValueText(Kri $kri, float|int|null $value, ?User $u): string
    {
        return $this->kriValueMasked((string) $kri->code, $u) ? 'value hidden (needs view it-finance-amounts)' : $kri->formatValue($value);
    }

    /**
     * Platform-provisioned finance KRIs: the masking is keyed on their code, so the code of a platform KRI is immutable and
     * its definition (category, unit, direction, thresholds) is changed only by `manage it-finance-settings` (P7 M2).
     */
    public static function isPlatformFinanceKri(Kri $kri): bool
    {
        return $kri->source === 'platform' && ($kri->category === 'finance' || str_starts_with((string) $kri->code, 'KRI-FIN-'));
    }

    /** Masks `value`, `trend[].value` and `readings[].value` of one KRI payload; `$u` null means "a viewer without amounts". */
    public function maskKriPayload(array $kri, ?User $u): array
    {
        if (! $this->kriValueMasked((string) ($kri['code'] ?? ''), $u)) {
            return $kri;
        }
        array_key_exists('value', $kri) && $kri['value'] = null;
        foreach (['trend', 'readings'] as $k) {
            if (isset($kri[$k]) && is_iterable($kri[$k])) {
                $kri[$k] = collect($kri[$k])->map(fn ($r) => is_array($r) ? array_merge($r, ['value' => null]) : $r)->all();
            }
        }

        return $kri;
    }

    /** Blanks the value of a Kri model's loaded readings in memory (never saved). @param Collection<int,Kri>|iterable<Kri> $kris */
    public function maskKriModels(iterable $kris, User $u): void
    {
        foreach ($kris as $k) {
            if (! $this->kriValueMasked((string) $k->code, $u)) {
                continue;
            }
            foreach (['readings', 'latestReading'] as $rel) {
                if ($k->relationLoaded($rel) && $k->{$rel}) {
                    $items = $k->{$rel} instanceof Model ? [$k->{$rel}] : $k->{$rel};
                    foreach ($items as $r) {
                        $r->setAttribute('value', null);
                    }
                }
            }
        }
    }
}
