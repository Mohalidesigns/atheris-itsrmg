<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Finance\Capitalisation;
use App\Models\Finance\Investment;
use App\Models\User;
use App\Services\Finance\FinanceClock;
use App\Services\Finance\FinanceSettings;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Props shaping for the capitalisation pages (plan §7.7, §7.8). Without `view it-finance-amounts` every amount, NBV and schedule figure is
 * nulled server-side (plan P6-18); status, dates, life, method and counts stay. Self-contained: do not combine with the other Presents* traits.
 */
trait PresentsCapitalisations
{
    private const CAP_MONEY_KEYS = [
        'amount', 'eligible_amount', 'excluded_amount', 'residual_value', 'nbv_today', 'amortised_to_date', 'opening_nbv', 'closing_nbv', 'pool', 'already', 'remaining', 'base_amount',
        'capitalised_total', 'nbv_total', 'amortisation_this_fy', 'amount_band_label', 'already_capitalised',
    ];

    protected function renderCapitalisations(string $component, array $props)
    {
        $user = request()->user();
        $can = $user->can('view it-finance-amounts');
        $props['baseCurrency'] ??= app(FinanceSettings::class)->baseCurrency((int) $user->organization_id);
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskCapitalisations(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskCapitalisations(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array($k, self::CAP_MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskCapitalisations($v);
            }
        }

        return $data;
    }

    /** @return array<string,mixed> */
    protected function capRow(Capitalisation $c, User $user): array
    {
        $today = FinanceClock::today();
        $source = $c->source();
        $href = $source instanceof Investment ? route('finance.investments.show', $source->id) : ($source ? route('finance.expenses.show', $source->id) : null);
        $asset = $c->asset;

        return [
            'id' => $c->id, 'capitalisation_code' => $c->capitalisation_code, 'title' => $c->title, 'source_type' => $c->source_type,
            'source' => ['id' => $source?->id, 'label' => $c->source_label, 'href' => $href], 'standard' => $c->standard, 'status' => $c->status, 'amount' => (string) $c->amount,
            'useful_life_months' => (int) $c->useful_life_months, 'method' => $c->method, 'available_for_use_on' => $c->available_for_use_on->toDateString(), 'capitalised_on' => $c->capitalised_on?->toDateString(),
            'nbv_today' => $c->nbvOn($today), 'amortised_to_date' => $c->amortisedTo($today),
            'asset' => $asset ? ['id' => $asset->id, 'code' => $asset->asset_id_code, 'name' => $asset->name, 'href' => route('assets.show', $asset->id)] : null,
            'href' => route('finance.capitalisations.show', $c->id),
        ];
    }

    /** @return array<string,mixed> */
    protected function capCan(Capitalisation $c, User $user): array
    {
        $edit = Gate::forUser($user)->allows('updateCapitalisation', $c);
        $decide = Gate::forUser($user)->allows('decideCapitalisation', $c) && $c->status === 'submitted' && ! in_array((int) $user->id, $c->sodExcludedIds(), true);

        return [
            'edit' => $edit && in_array($c->status, ['draft', 'rejected'], true), 'submit' => $edit && $c->status === 'draft', 'withdraw' => $edit && $c->status === 'submitted',
            'approve' => $decide, 'reject' => $decide, 'cancel' => $edit && in_array($c->status, ['draft', 'rejected'], true),
        ];
    }
}
