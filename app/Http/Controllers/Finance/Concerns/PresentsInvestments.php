<?php

namespace App\Http\Controllers\Finance\Concerns;

use App\Models\Finance\Benefit;
use App\Models\Finance\BusinessCase;
use App\Models\Finance\CostCentre;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\Investment;
use App\Models\Finance\InvestmentLink;
use App\Models\Finance\StageGate;
use App\Models\Finance\ValueReview;
use App\Models\User;
use App\Policies\Finance\FinancePolicy;
use App\Services\Finance\FinanceSettings;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

/**
 * Props shaping for the investment pages (plan §7). Without `view it-finance-amounts` every money value, NPV, IRR, ROI, payback, CPI,
 * EAC and band label is nulled server-side (plan P5-14); stage, status, health, score, rank and counts stay. Self-contained on purpose:
 * do not combine with the other Presents* traits, whose method names collide.
 */
trait PresentsInvestments
{
    private const INV_MONEY_KEYS = [
        'bac', 'planned_cost', 'allocated', 'funding_gap', 'ac', 'committed', 'ev', 'cv', 'cpi', 'eac', 'vac', 'eac_override', 'npv', 'irr_pct', 'roi_pct', 'payback_months',
        'discounted_payback_months', 'tco', 'one_off_cost', 'total_benefit', 'total_cost', 'cost_delta', 'current_base_amount', 'actual', 'amount', 'planned', 'realised',
        'cumulative_cost', 'envelope', 'approval_base_amount', 'amount_band_label', 'bac_total', 'ac_total', 'eac_total', 'cpi_portfolio', 'benefits_planned', 'benefits_realised',
        'default', 'used', 'x', 'y', 'z', 'base_amount', 'cost', 'benefit', 'net', 'discounted', 'cumulative', 'discounted_cumulative',
    ];

    private const INV_MONEY_LISTS = ['costs', 'benefits_plan', 'cash_flows', 'spend_by_stage', 'spend_by_year', 'bubble'];

    protected function renderInvestments(string $component, array $props)
    {
        $user = request()->user();
        $can = $user->can('view it-finance-amounts');
        $props['baseCurrency'] ??= app(FinanceSettings::class)->baseCurrency((int) $user->organization_id);
        $props['can'] = array_merge((array) ($props['can'] ?? []), ['viewAmounts' => $can]);
        if (! $can) {
            $props = $this->maskInvestments(json_decode(json_encode($props), true));
        }

        return Inertia::render($component, $props);
    }

    protected function maskInvestments(array $data): array
    {
        if (($data['unit'] ?? null) === 'currency' && array_key_exists('aggregation', $data)) {
            foreach (['baseline_value', 'target_value', 'realised_value', 'realised_pct'] as $k) {
                array_key_exists($k, $data) && $data[$k] = null;
            }
            foreach ((array) ($data['readings'] ?? []) as $n => $r) {
                $data['readings'][$n]['value'] = null;
            }
        }
        if (array_key_exists('stage', $data) && array_key_exists('count', $data) && array_key_exists('value', $data)) {
            $data['value'] = null; // a pipeline row
        }
        foreach ($data as $k => $v) {
            if (in_array($k, ['costs', 'benefits'], true) && is_array($v) && array_is_list($v) && (empty($v) || (isset($v[0]['year']) && ! isset($v[0]['id'])))) {
                $data[$k] = []; // business-case cost and benefit rows
            } elseif (is_string($k) && in_array($k, self::INV_MONEY_LISTS, true) && ! in_array($k, ['costs', 'benefits'], true)) {
                $data[$k] = [];
            } elseif (is_string($k) && in_array($k, self::INV_MONEY_KEYS, true) && ! is_array($v)) {
                $data[$k] = null;
            } elseif (is_array($v)) {
                $data[$k] = $this->maskInvestments($v);
            }
        }

        return $data;
    }

    /** @return list<array{value:string,label:string}> */
    protected function opts(array $values, array $labels = []): array
    {
        return array_map(fn ($v) => ['value' => $v, 'label' => $labels[$v] ?? ucfirst(str_replace('_', ' ', (string) $v))], $values);
    }

    /** @return array<string,mixed> */
    protected function optionsPayload(): array
    {
        return [
            'types' => $this->opts(Investment::TYPES), 'rgt' => $this->opts(Investment::RGT), 'drivers' => $this->opts(Investment::DRIVERS),
            'stages' => $this->opts(Investment::STAGES, ['business_case' => 'Business case', 'in_delivery' => 'In delivery', 'benefits_tracking' => 'Benefits tracking']),
            'statuses' => $this->opts(Investment::STATUSES), 'criteria' => $this->opts(Investment::CRITERIA, ['delivery_risk' => 'Delivery risk (5 = high risk)']),
            'benefitTypes' => $this->opts(Benefit::TYPES), 'units' => $this->opts(Benefit::UNITS), 'reviewTypes' => $this->opts(ValueReview::TYPES, ValueReview::TYPE_LABELS),
            'linkTypes' => $this->opts(array_keys(InvestmentLink::TYPES), ['ea_application' => 'EA application', 'business_service' => 'Business service']),
        ];
    }

    protected function userMini(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title] : null;
    }

    /** @return list<array<string,mixed>> */
    protected function usersMini(int $orgId): array
    {
        return User::query()->where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title'])->map(fn ($u) => $this->userMini($u))->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function fiscalYearsOpts(int $orgId): array
    {
        return FiscalYear::withoutGlobalScopes()->where('organization_id', $orgId)->whereNull('deleted_at')->orderByDesc('starts_on')->get()
            ->map(fn ($f) => ['id' => $f->id, 'year_label' => $f->year_label])->values()->all();
    }

    /** @return list<array<string,mixed>> */
    protected function costCentresOpts(User $user): array
    {
        $ids = FinancePolicy::visibleCostCentreIds($user);

        return CostCentre::withoutGlobalScopes()->where('organization_id', $user->organization_id)->whereNull('deleted_at')->where('is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('code')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values()->all();
    }

    /**
     * One register row; $fin comes from InvestmentFinancials::forInvestments, $bc is the approved (or current) business case.
     *
     * @param  array<string,mixed>|null  $fin
     * @return array<string,mixed>
     */
    protected function investmentRow(Investment $i, ?array $fin, ?BusinessCase $bc, ?string $benefitsPct, ?StageGate $pending): array
    {
        return [
            'id' => $i->id, 'investment_code' => $i->investment_code, 'title' => $i->title, 'type' => $i->type, 'rgt' => $i->rgt, 'driver' => $i->driver, 'stage' => $i->stage,
            'status' => $i->status, 'health' => $fin['health'] ?? null, 'is_regulatory' => (bool) $i->is_regulatory,
            'sponsor' => $this->userMini($i->sponsor), 'manager' => $this->userMini($i->manager),
            'cost_centre' => $i->costCentre ? ['id' => $i->costCentre->id, 'code' => $i->costCentre->code, 'name' => $i->costCentre->name] : null,
            'fiscal_year' => $i->fiscalYear ? ['id' => $i->fiscalYear->id, 'year_label' => $i->fiscalYear->year_label] : null,
            'starts_on' => $i->starts_on?->toDateString(), 'planned_ends_on' => $i->planned_ends_on?->toDateString(), 'actual_ends_on' => $i->actual_ends_on?->toDateString(),
            'priority_score' => $i->priority_score, 'priority_rank' => $i->priority_rank, 'planned_cost' => $i->planned_cost, 'bac' => $fin['bac'] ?? ($i->bac === null ? null : (string) $i->bac),
            'ac' => $fin['ac'] ?? null, 'eac' => $fin['eac'] ?? null, 'cpi' => $fin['cpi'] ?? null, 'npv' => $bc?->npv, 'benefits_realised_pct' => $benefitsPct,
            'pending_gate' => $pending ? ['id' => $pending->id, 'gate' => $pending->gate] : null, 'href' => route('finance.investments.show', $i->id),
        ];
    }

    /** @return list<array<string,mixed>> */
    protected function activityRows(Model $subject, int $limit = 100): array
    {
        return Activity::query()->where('subject_type', $subject::class)->where('subject_id', $subject->getKey())->with('causer:id,name')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($a) => ['id' => $a->id, 'event' => $a->event, 'description' => $a->description, 'causer' => $a->causer ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null,
                'at' => $a->created_at?->toIso8601String(), 'changes' => $a->event === 'updated' ? ($a->properties['attributes'] ?? null) : null])->values()->all();
    }
}
