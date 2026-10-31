<?php

namespace App\Services\Finance;

use App\Models\Finance\Allocation;
use App\Models\Finance\Currency;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Investment;
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
        'doa' => ['invoice_sod_from'],
        'variance' => ['variance_amber_pct', 'variance_red_pct', 'variance_abs_floor'],
        'renewal' => ['renewal_lead_days', 'renewal_notice_offsets', 'escalation_role', 'renewal_cfo_from', 'renewal_cfo_uplift_pct'],
        'investments' => ['investment_score_weights', 'investment_discount_rate_pct', 'investment_cost_tolerance_pct', 'value_review_lead_days'],
        'finops' => ['cloud_anomaly_pct', 'cloud_anomaly_abs_floor', 'showback_units', 'chargeback_debit_gl', 'chargeback_credit_gl'],
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
        FinanceKriSource::ensureDefinitions($orgId);

        return $setting;
    }

    public function for(int $orgId): Setting
    {
        return $this->memo[$orgId] ??= $this->ensureDefaults($orgId);
    }

    public function update(Setting $setting, string $section, array $validated): Setting
    {
        if ($section === 'doa' && array_key_exists('doa_bands', $validated)) {
            throw ValidationException::withMessages(['workflow' => 'DoA bands change through a proposal and a second approver (maker-checker).']);
        }
        $keys = self::SECTION_KEYS[$section] ?? throw ValidationException::withMessages(['section' => 'Unknown settings section.']);
        $data = array_intersect_key($validated, array_flip($keys));

        if ($section === 'renewal' && isset($data['renewal_notice_offsets'])) {
            $offsets = array_values(array_unique(array_map('intval', $data['renewal_notice_offsets'])));
            rsort($offsets);
            $data['renewal_notice_offsets'] = $offsets;
        }

        if ($section === 'finops' && array_key_exists('showback_units', $data)) {
            $data['showback_units'] = $this->normaliseUnits($data['showback_units'], (int) $setting->organization_id);
        }

        $weightsChanged = false;
        if ($section === 'investments' && array_key_exists('investment_score_weights', $data)) {
            $weights = $this->normaliseWeights($data['investment_score_weights']);
            $weightsChanged = $weights !== $this->investmentWeights((int) $setting->organization_id);
            $data['investment_score_weights'] = $weights;
        }

        $setting->fill($data)->save();
        $this->flush();
        $weightsChanged && app(InvestmentScoring::class)->rescore((int) $setting->organization_id);

        return $setting->refresh();
    }

    /**
     * The bank's showback business units (plan P6-9): at most 50, unique upper-case codes. A unit still used by an active allocation cannot be removed.
     *
     * @return list<array{code:string,name:string,erp_cost_centre_code:?string}>
     */
    public function normaliseUnits(mixed $units, int $orgId): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['showback_units' => $m]);
        $units = is_array($units) ? array_values($units) : [];
        count($units) <= 50 || $fail('Define at most 50 business units.');
        $out = [];
        $seen = [];
        foreach ($units as $u) {
            $code = strtoupper(trim((string) ($u['code'] ?? '')));
            $name = trim((string) ($u['name'] ?? ''));
            $erp = trim((string) ($u['erp_cost_centre_code'] ?? ''));
            preg_match('/^[A-Z0-9_-]{2,16}$/', $code) || $fail("Unit code \"{$code}\" must be 2 to 16 letters, digits, dash or underscore.");
            isset($seen[$code]) && $fail("Unit code {$code} is used twice.");
            ($name !== '' && mb_strlen($name) <= 120) || $fail("Give unit {$code} a name (max 120 characters).");
            mb_strlen($erp) <= 32 || $fail("The ERP cost centre of {$code} is too long (max 32 characters).");
            $seen[$code] = true;
            $out[] = ['code' => $code, 'name' => $name, 'erp_cost_centre_code' => $erp === '' ? null : $erp];
        }
        $used = Allocation::withoutGlobalScopes()->where('organization_id', $orgId)->where('target_type', 'business_unit')->where('is_active', true)->whereNull('deleted_at')
            ->whereNotNull('target_ref')->distinct()->pluck('target_ref')->all();
        foreach ($used as $code) {
            isset($seen[$code]) || $fail("Unit {$code} is used by an active allocation rule and cannot be removed.");
        }

        return $out;
    }

    /** Cloud anomaly thresholds: the bank's setting, else the config default (plan P6-4). @return array{pct:string,abs_floor:string,baseline_days:int,min_baseline_days:int,lookback_days:int} */
    public function cloudAnomalyThresholds(int $orgId): array
    {
        $s = $this->for($orgId);
        $c = (array) config('finance.cloud.anomaly');

        return [
            'pct' => (string) ($s->cloud_anomaly_pct ?? $c['default_pct']),
            'abs_floor' => (string) ($s->cloud_anomaly_abs_floor ?? $c['default_abs_floor']),
            'baseline_days' => (int) $c['baseline_days'], 'min_baseline_days' => (int) $c['min_baseline_days'], 'lookback_days' => (int) $c['lookback_days'],
        ];
    }

    /** @return list<array{code:string,name:string,erp_cost_centre_code:?string}> */
    public function showbackUnits(int $orgId): array
    {
        $units = $this->for($orgId)->showback_units;

        return array_values(is_array($units) ? $units : (array) config('finance.showback.default_units'));
    }

    /** @return array{debit:string,credit:string} */
    public function chargebackGl(int $orgId): array
    {
        $s = $this->for($orgId);

        return [
            'debit' => (string) ($s->chargeback_debit_gl ?: config('finance.showback.chargeback_debit_gl')),
            'credit' => (string) ($s->chargeback_credit_gl ?: config('finance.showback.chargeback_credit_gl')),
        ];
    }

    /**
     * The five scoring weights as whole percentages that sum to 100 (plan P5-10).
     *
     * @return array<string,int>
     */
    public function normaliseWeights(mixed $weights): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['investment_score_weights' => $m]);
        is_array($weights) || $fail('Give a weight for each of the five criteria.');
        $out = [];
        foreach (Investment::CRITERIA as $k) {
            $v = $weights[$k] ?? null;
            (is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v >= 0 && (int) $v <= 100 || $fail('Each weight must be a whole number from 0 to 100.');
            $out[$k] = (int) $v;
        }
        array_sum($out) === 100 || $fail('The five weights must add up to 100 (they add up to '.array_sum($out).').');

        return $out;
    }

    /** @return array<string,int> */
    public function investmentWeights(int $orgId): array
    {
        $stored = (array) ($this->for($orgId)->investment_score_weights ?? []);
        $default = (array) config('finance.settings_defaults.investment_score_weights');
        $out = [];
        foreach (Investment::CRITERIA as $k) {
            $out[$k] = (int) ($stored[$k] ?? $default[$k] ?? 0);
        }

        return $out;
    }

    /** Hurdle rate (percent) for new business cases; null setting falls back to config. */
    public function discountRatePct(int $orgId): string
    {
        return (string) ($this->for($orgId)->investment_discount_rate_pct ?? config('finance.investments.default_discount_rate_pct'));
    }

    public function costTolerancePct(int $orgId): string
    {
        return (string) ($this->for($orgId)->investment_cost_tolerance_pct ?? config('finance.investments.default_cost_tolerance_pct'));
    }

    public function valueReviewLeadDays(int $orgId): int
    {
        return (int) ($this->for($orgId)->value_review_lead_days ?? config('finance.investments.value_review_lead_days'));
    }

    /**
     * The bank's CFO triggers for a contract renewal: base-currency value from which, and like-for-like uplift % above which,
     * the CFO stage applies. A null setting falls back to config('finance.approval_defaults').
     *
     * @return array{0:string,1:string}
     */
    public function renewalCfoThresholds(int $orgId): array
    {
        $s = $this->for($orgId);

        return [
            (string) ($s->renewal_cfo_from ?? config('finance.approval_defaults.renewal_cfo_from')),
            (string) ($s->renewal_cfo_uplift_pct ?? config('finance.approval_defaults.renewal_cfo_uplift_pct')),
        ];
    }

    /** Base-currency value from which the final invoice approver may not be a purchase-order approver (plan P3-9). */
    public function invoiceSodFrom(int $orgId): string
    {
        return (string) ($this->for($orgId)->invoice_sod_from ?? config('finance.approval_defaults.invoice_sod_from'));
    }

    /** Key of the DoA band an amount (in base currency) falls in: min <= amount < max. */
    public function bandFor(int $orgId, string|float $baseAmount): string
    {
        return app(DoaBandService::class)->bandFor($orgId, $baseAmount)['key'];
    }

    public function baseCurrency(int $orgId): string
    {
        $this->for($orgId);

        return RequestMemo::remember("baseCurrency:{$orgId}", fn () => Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_base', true)->value('code')
            ?? (Organization::query()->whereKey($orgId)->value('currency') ?: 'NGN'));
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
    public function bandsWithKeys(array $bands): array
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
        if ($n >= 1_000_000_000 && fmod($n, 100_000_000) == 0.0) {
            return rtrim(rtrim(number_format($n / 1_000_000_000, 1, '.', ''), '0'), '.').'bn';
        }
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
        $codes = array_values(array_unique(array_merge([$orgCurrency], config('finance.default_currencies'))));
        $have = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->whereIn('code', $codes)->pluck('code')->all();
        if (count($have) === count($codes)) {
            return;
        }
        $hasBase = Currency::withoutGlobalScopes()->where('organization_id', $orgId)->where('is_base', true)->exists();

        foreach (array_diff($codes, $have) as $code) {
            [$name, $symbol, $decimals] = $catalogue[$code] ?? [$code, null, 2];
            Currency::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $code],
                ['name' => $name, 'symbol' => $symbol, 'decimals' => $decimals, 'is_base' => ! $hasBase && $code === $orgCurrency, 'is_active' => true],
            );
        }
    }

    private function ensureGlAccounts(int $orgId): void
    {
        $have = GlAccount::withoutGlobalScopes()->withTrashed()->where('organization_id', $orgId)->pluck('code')->all();
        foreach (config('finance.gl_accounts') as [$code, $name, $type]) {
            if (in_array($code, $have, true)) {
                continue;
            }
            GlAccount::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $code],
                ['name' => $name, 'type' => $type, 'is_active' => true],
            );
        }
    }

    private function ensureTaxTypes(int $orgId): void
    {
        $have = TaxType::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('code')->all();
        foreach (config('finance.tax_types') as $t) {
            if (in_array($t['code'], $have, true)) {
                continue;
            }
            TaxType::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $orgId, 'code' => $t['code']],
                ['name' => $t['name'], 'kind' => $t['kind'], 'rate' => $t['rate'], 'is_inclusive' => $t['is_inclusive'], 'applies_to' => $t['applies_to'], 'is_active' => true],
            );
        }
    }
}
