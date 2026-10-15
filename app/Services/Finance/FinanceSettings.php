<?php

namespace App\Services\Finance;

use App\Models\Finance\Currency;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Setting;
use App\Models\Finance\TaxType;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

/**
 * Per-bank finance settings and their lazily created defaults (ATH-FIN-001 §3.1). A bank that has
 * never opened the module gets its settings, base currency, starter chart of accounts, TBM
 * taxonomy and Nigerian tax types the first time anything asks (FIN-01.03).
 */
class FinanceSettings
{
    public const DOA_AMOUNT = '/^\d{1,16}(\.\d{1,2})?$/';

    /** Keys each settings section may write. */
    public const SECTION_KEYS = [
        'general' => ['fiscal_year_start_month', 'period_scheme', 'overbudget_mode', 'match_tolerance_pct', 'match_tolerance_abs', 'eac_method', 'showback_enabled', 'digest_hour'],
        'doa' => ['doa_bands'],
        'variance' => ['variance_amber_pct', 'variance_red_pct', 'variance_abs_floor'],
        'renewal' => ['renewal_lead_days', 'renewal_notice_offsets', 'escalation_role'],
    ];

    /** @var array<int, Setting> memoised per request (the service is a container singleton) */
    private array $memo = [];

    public function flush(): void
    {
        $this->memo = [];
    }

    public function ensureDefaults(int $orgId): Setting
    {
        $setting = Setting::withoutGlobalScopes()->where('organization_id', $orgId)->first()
            ?? Setting::withoutGlobalScopes()->create(['organization_id' => $orgId] + config('finance.settings_defaults'));

        $this->ensureCurrencies($orgId);
        $this->ensureGlAccounts($orgId);
        TbmTaxonomy::seed($orgId);
        $this->ensureTaxTypes($orgId);

        return $setting;
    }

    public function for(int $orgId): Setting
    {
        return $this->memo[$orgId] ??= $this->ensureDefaults($orgId);
    }

    public function update(Setting $setting, string $section, array $validated): Setting
    {
        $keys = self::SECTION_KEYS[$section] ?? throw ValidationException::withMessages(['section' => 'Unknown settings section.']);
        $data = array_intersect_key($validated, array_flip($keys));

        if ($section === 'renewal' && isset($data['renewal_notice_offsets'])) {
            $offsets = array_values(array_unique(array_map('intval', $data['renewal_notice_offsets'])));
            rsort($offsets);
            $data['renewal_notice_offsets'] = $offsets;
        }
        if ($section === 'doa') {
            $bands = array_values($data['doa_bands'] ?? []);
            $this->validateBands($bands);
            $data['doa_bands'] = $this->withKeys($bands);
        }

        $setting->fill($data)->save();
        $this->flush();

        return $setting->refresh();
    }

    /** Key of the DoA band an amount (in base currency) falls in: min <= amount < max. */
    public function bandFor(int $orgId, string|float $baseAmount): string
    {
        $bands = $this->for($orgId)->doa_bands ?? config('finance.settings_defaults.doa_bands');
        $amount = (string) $baseAmount;

        foreach ($bands as $band) {
            $aboveMin = bccomp($amount, (string) $band['min'], 2) >= 0;
            $belowMax = $band['max'] === null || bccomp($amount, (string) $band['max'], 2) < 0;
            if ($aboveMin && $belowMax) {
                return $band['key'];
            }
        }

        return end($bands)['key'];
    }

    public function baseCurrency(int $orgId): string
    {
        $this->for($orgId);

        return Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_base', true)->value('code')
            ?? (Organization::query()->whereKey($orgId)->value('currency') ?: 'NGN');
    }

    /** @param array<int, array{label:string,min:mixed,max:mixed}> $bands */
    public function validateBands(array $bands): void
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['doa_bands' => $m]);
        $n = count($bands);
        if ($n < 1 || $n > 8) {
            $fail('Define between 1 and 8 bands.');
        }
        foreach (array_values($bands) as $i => $b) {
            $min = $b['min'] ?? null;
            $max = $b['max'] ?? null;
            if (! is_scalar($min) || ! preg_match(self::DOA_AMOUNT, (string) $min)) {
                $fail('Every band needs a plain numeric minimum (up to 2 decimals).');
            }
            if ($i === 0 && bccomp((string) $min, '0', 2) !== 0) {
                $fail('The first band must start at 0.');
            }
            if ($i > 0 && bccomp((string) $min, (string) $bands[$i - 1]['max'], 2) !== 0) {
                $fail('Bands must be contiguous: each minimum equals the previous maximum.');
            }
            if ($i < $n - 1) {
                if ($max === null || ! is_scalar($max) || ! preg_match(self::DOA_AMOUNT, (string) $max)) {
                    $fail('Only the last band may be open-ended.');
                }
                if (bccomp((string) $max, (string) $min, 2) <= 0) {
                    $fail('Each band maximum must be greater than its minimum.');
                }
            } elseif ($max !== null) {
                $fail('The last band must have no upper limit.');
            }
        }
    }

    /** @param array<int, array<string,mixed>> $bands */
    private function withKeys(array $bands): array
    {
        $out = [];
        foreach ($bands as $i => $b) {
            $min = $this->short($b['min']);
            $key = $b['max'] === null ? "{$min}+" : "{$min}-".$this->short($b['max']);
            $out[] = ['key' => $key, 'label' => (string) $b['label'], 'min' => $b['min'] + 0, 'max' => $b['max'] === null ? null : $b['max'] + 0];
        }

        return $out;
    }

    /** 1000000 => "1m", 250000000 => "250m", 500000 => "500k", 0 => "0". */
    private function short(int|float|string $n): string
    {
        $n = (float) $n;
        if ($n >= 1_000_000 && fmod($n, 1_000_000) == 0.0) {
            return ((int) ($n / 1_000_000)).'m';
        }
        if ($n >= 1_000 && fmod($n, 1_000) == 0.0) {
            return ((int) ($n / 1_000)).'k';
        }

        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    private function ensureCurrencies(int $orgId): void
    {
        $catalogue = config('finance.currencies');
        $orgCurrency = Organization::query()->whereKey($orgId)->value('currency') ?: 'NGN';
        $hasBase = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_base', true)->exists();

        foreach (array_values(array_unique(array_merge([$orgCurrency], config('finance.default_currencies')))) as $code) {
            [$name, $symbol, $decimals] = $catalogue[$code] ?? [$code, null, 2];
            Currency::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $code],
                ['name' => $name, 'symbol' => $symbol, 'decimals' => $decimals, 'is_base' => ! $hasBase && $code === $orgCurrency, 'is_active' => true],
            );
        }
    }

    private function ensureGlAccounts(int $orgId): void
    {
        foreach (config('finance.gl_accounts') as [$code, $name, $type]) {
            GlAccount::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $code],
                ['name' => $name, 'type' => $type, 'is_active' => true],
            );
        }
    }

    private function ensureTaxTypes(int $orgId): void
    {
        foreach (config('finance.tax_types') as $t) {
            TaxType::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $t['code']],
                ['name' => $t['name'], 'kind' => $t['kind'], 'rate' => $t['rate'], 'is_inclusive' => $t['is_inclusive'], 'applies_to' => $t['applies_to'], 'is_active' => true],
            );
        }
    }
}
