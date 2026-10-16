<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Currency;
use App\Models\Finance\DoaBandVersion;
use App\Models\Finance\Driver;
use App\Models\Finance\FiscalYear;
use App\Models\Finance\FxRate;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Period;
use App\Models\Finance\Setting;
use App\Models\Finance\TaxType;
use App\Models\LegalEntity;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\DoaBandService;
use App\Services\Finance\FinanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Finance Settings page and the four settings sections (ATH-FIN-001 §3.1, §9). */
class FinanceSettingsController extends Controller
{
    public const TABS = ['general', 'calendar', 'cost-centres', 'categories', 'gl-accounts', 'currencies', 'taxes', 'drivers', 'doa', 'variance', 'renewal'];

    public function __construct(private readonly FinanceSettings $settings, private readonly CurrencyService $currency, private readonly DoaBandService $doa) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $orgId = $user->organization_id;
        $setting = $this->settings->for($orgId);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'general';

        $fiscalYears = FiscalYear::query()->with(['periods.closedBy:id,name', 'periods.lockedBy:id,name'])
            ->orderByDesc('starts_on')->get()->map(fn (FiscalYear $fy) => [
                'id' => $fy->id, 'year_label' => $fy->year_label, 'starts_on' => $fy->starts_on->toDateString(), 'ends_on' => $fy->ends_on->toDateString(),
                'period_scheme' => $fy->period_scheme, 'status' => $fy->status, 'is_current' => $fy->is_current,
                'periods' => $fy->periods->map(fn (Period $p) => [
                    'id' => $p->id, 'sequence' => $p->sequence, 'name' => $p->name, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(),
                    'status' => $p->status, 'closed_at' => $p->closed_at?->toIso8601String(),
                    'closed_by' => $p->closedBy ? ['id' => $p->closedBy->id, 'name' => $p->closedBy->name] : null,
                    'locked_at' => $p->locked_at?->toIso8601String(),
                    'locked_by' => $p->lockedBy ? ['id' => $p->lockedBy->id, 'name' => $p->lockedBy->name] : null,
                ])->values(),
            ])->values();

        $costCentres = CostCentre::query()->with(['owner:id,name,job_title', 'legalEntity:id,code,name'])->orderBy('code')->get()->map(fn (CostCentre $c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'parent_id' => $c->parent_id, 'owner_id' => $c->owner_id,
            'owner' => $c->owner ? ['id' => $c->owner->id, 'name' => $c->owner->name, 'job_title' => $c->owner->job_title] : null,
            'legal_entity_id' => $c->legal_entity_id,
            'legal_entity' => $c->legalEntity ? ['id' => $c->legalEntity->id, 'code' => $c->legalEntity->code, 'name' => $c->legalEntity->name] : null,
            'business_unit' => $c->business_unit, 'is_active' => $c->is_active,
        ])->values();

        $fxRates = FxRate::query()->orderByDesc('rate_date')->orderByDesc('id')->limit(200)->get()->map(fn (FxRate $r) => $this->fx($r))->values();
        $latest = FxRate::query()->orderByDesc('rate_date')->orderByDesc('id')->get()->unique(fn ($r) => $r->from_currency.'>'.$r->to_currency.'@'.$r->rate_type)
            ->map(fn (FxRate $r) => ['from_currency' => $r->from_currency, 'to_currency' => $r->to_currency, 'rate' => (string) $r->rate, 'rate_date' => $r->rate_date->toDateString(), 'rate_type' => $r->rate_type])->values();
        $this->doa->active($orgId); // make sure version 1 exists
        $doaVersions = DoaBandVersion::query()->with(['proposedBy:id,name', 'decidedBy:id,name'])->orderByDesc('version_no')->get()->map(fn (DoaBandVersion $v) => [
            'id' => $v->id, 'version_no' => $v->version_no, 'status' => $v->status, 'effective_from' => $v->effective_from->toDateString(), 'bands' => $v->bands, 'reason' => $v->reason,
            'proposed_by' => $v->proposedBy ? ['id' => $v->proposedBy->id, 'name' => $v->proposedBy->name] : null, 'proposed_at' => $v->proposed_at?->toIso8601String(),
            'decided_by' => $v->decidedBy ? ['id' => $v->decidedBy->id, 'name' => $v->decidedBy->name] : null, 'decided_at' => $v->decided_at?->toIso8601String(),
            'decision_note' => $v->decision_note, 'activated_at' => $v->activated_at?->toIso8601String(),
        ])->values();

        $drivers = Driver::query()->with('values')->orderBy('code')->get()->map(fn (Driver $d) => [
            'id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'unit' => $d->unit, 'is_active' => $d->is_active,
            'values' => $d->values->map(fn ($v) => ['period_id' => $v->period_id, 'value' => (string) $v->value])->values(),
        ])->values();

        return Inertia::render('Finance/Settings', [
            'tab' => $tab,
            'baseCurrency' => $this->settings->baseCurrency($orgId),
            'settings' => $this->settingProps($setting),
            'fiscalYears' => $fiscalYears,
            'costCentres' => $costCentres,
            'categories' => Category::query()->orderBy('kind')->orderBy('sort_order')->orderBy('code')->get()->map(fn (Category $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'parent_id' => $c->parent_id, 'tbm_reference' => $c->tbm_reference,
                'default_capex' => $c->default_capex, 'default_gl_account_id' => $c->default_gl_account_id, 'is_system' => $c->is_system,
                'is_active' => $c->is_active, 'sort_order' => $c->sort_order,
            ])->values(),
            'glAccounts' => GlAccount::query()->orderBy('code')->get()->map(fn (GlAccount $g) => ['id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'type' => $g->type, 'is_active' => $g->is_active])->values(),
            'currencies' => Currency::query()->orderByDesc('is_base')->orderBy('code')->get()->map(fn (Currency $c) => [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'symbol' => $c->symbol, 'decimals' => $c->decimals, 'is_base' => $c->is_base, 'is_active' => $c->is_active,
            ])->values(),
            'fxRates' => $fxRates,
            'latestRates' => $latest,
            'doaVersions' => $doaVersions,
            'baseLockedReason' => $this->currency->baseLockedReason($orgId),
            'taxTypes' => TaxType::query()->orderBy('code')->get()->map(fn (TaxType $t) => [
                'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'kind' => $t->kind, 'rate' => (string) $t->rate,
                'is_inclusive' => $t->is_inclusive, 'applies_to' => $t->applies_to ?? [], 'is_active' => $t->is_active,
            ])->values(),
            'drivers' => $drivers,
            'users' => User::query()->where('organization_id', $orgId)->orderBy('name')->get(['id', 'name', 'job_title'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title])->values(),
            'legalEntities' => LegalEntity::query()->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => $e->name])->values(),
            'jobTitles' => User::query()->where('organization_id', $orgId)->whereNotNull('job_title')->where('job_title', '!=', '')->distinct()->orderBy('job_title')->pluck('job_title')->values(),
            'options' => [
                'months' => collect(range(1, 12))->map(fn ($m) => ['value' => $m, 'label' => date('F', mktime(0, 0, 0, $m, 1))])->all(),
                'periodSchemes' => FiscalYear::PERIOD_SCHEMES, 'fiscalYearStatuses' => FiscalYear::STATUSES, 'periodStatuses' => Period::STATUSES,
                'categoryKinds' => Category::KINDS, 'glTypes' => GlAccount::TYPES, 'fxSources' => FxRate::SOURCES, 'fxRateTypes' => FxRate::RATE_TYPES,
                'taxKinds' => TaxType::KINDS, 'taxAppliesTo' => TaxType::APPLIES_TO,
                'overbudgetModes' => Setting::OVERBUDGET_MODES, 'eacMethods' => Setting::EAC_METHODS,
                'currencyCatalogue' => collect(config('finance.currencies'))->map(fn ($v, $code) => ['code' => $code, 'name' => $v[0], 'symbol' => $v[1], 'decimals' => $v[2]])->values()->all(),
            ],
            'can' => [
                'manage' => $user->can('manage it-finance-settings'),
                'closePeriods' => $user->can('close it-finance-periods'),
                'import' => $user->can('import it-finance'),
                'approveBands' => $user->can('approve it-finance') || $user->can('manage it-finance-settings'),
                'changeBase' => $this->currency->baseLockedReason($orgId) === null,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $setting = $this->settings->for($request->user()->organization_id);
        Gate::authorize('update', $setting);

        $section = $request->validate(['section' => ['required', Rule::in(Setting::SECTIONS)]])['section'];
        if ($section === 'doa') {
            $this->settings->update($setting, 'doa', []); // refuses: bands change through a proposal and a second approver
        }
        $rules = match ($section) {
            'general' => [
                'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
                'period_scheme' => ['required', Rule::in(FiscalYear::PERIOD_SCHEMES)],
                'overbudget_mode' => ['required', Rule::in(Setting::OVERBUDGET_MODES)],
                'match_tolerance_pct' => ['required', 'numeric', 'between:0,100'],
                'match_tolerance_abs' => ['required', 'numeric', 'min:0'],
                'eac_method' => ['required', Rule::in(Setting::EAC_METHODS)],
                'showback_enabled' => ['required', 'boolean'],
                'digest_hour' => ['required', 'integer', 'between:0,23'],
            ],
            'doa' => [
                'doa_bands' => ['required', 'array', 'min:1', 'max:8'],
                'doa_bands.*.label' => ['required', 'string', 'max:40'],
                'doa_bands.*.min' => ['required', 'regex:'.FinanceSettings::DOA_AMOUNT],
                'doa_bands.*.max' => ['nullable', 'regex:'.FinanceSettings::DOA_AMOUNT],
            ],
            'variance' => [
                'variance_amber_pct' => ['required', 'numeric', 'between:0.01,100'],
                'variance_red_pct' => ['required', 'numeric', 'gt:variance_amber_pct', 'max:1000'],
                'variance_abs_floor' => ['required', 'numeric', 'min:0'],
            ],
            'renewal' => [
                'renewal_lead_days' => ['required', 'integer', 'between:1,730'],
                'renewal_notice_offsets' => ['required', 'array', 'min:1', 'max:12'],
                'renewal_notice_offsets.*' => ['integer', 'between:0,730', 'distinct'],
                'escalation_role' => ['nullable', 'string', 'max:64'],
                'renewal_cfo_from' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
                'renewal_cfo_uplift_pct' => ['nullable', 'numeric', 'between:0,1000'],
            ],
        };
        $validated = $request->validate($rules);
        $this->settings->update($setting, $section, $validated);

        return redirect()->route('finance.settings', ['tab' => $section])->with('success', 'Settings saved.');
    }

    private function settingProps(Setting $s): array
    {
        return [
            'id' => $s->id, 'fiscal_year_start_month' => $s->fiscal_year_start_month, 'period_scheme' => $s->period_scheme,
            'variance_amber_pct' => (string) $s->variance_amber_pct, 'variance_red_pct' => (string) $s->variance_red_pct, 'variance_abs_floor' => (string) $s->variance_abs_floor,
            'renewal_lead_days' => $s->renewal_lead_days, 'renewal_notice_offsets' => $s->renewal_notice_offsets ?? [], 'escalation_role' => $s->escalation_role,
            'renewal_cfo_from' => $s->renewal_cfo_from === null ? null : (string) $s->renewal_cfo_from,
            'renewal_cfo_uplift_pct' => $s->renewal_cfo_uplift_pct === null ? null : (string) $s->renewal_cfo_uplift_pct,
            'renewal_cfo_defaults' => ['from' => (string) config('finance.approval_defaults.renewal_cfo_from'), 'uplift_pct' => (string) config('finance.approval_defaults.renewal_cfo_uplift_pct')],
            'overbudget_mode' => $s->overbudget_mode, 'match_tolerance_pct' => (string) $s->match_tolerance_pct, 'match_tolerance_abs' => (string) $s->match_tolerance_abs,
            'eac_method' => $s->eac_method, 'showback_enabled' => $s->showback_enabled, 'digest_hour' => $s->digest_hour,
            'doa_bands' => $s->doa_bands ?? [], 'investment_score_weights' => $s->investment_score_weights ?? [],
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    private function fx(FxRate $r): array
    {
        return ['id' => $r->id, 'from_currency' => $r->from_currency, 'to_currency' => $r->to_currency, 'rate' => (string) $r->rate, 'rate_date' => $r->rate_date->toDateString(), 'source' => $r->source, 'rate_type' => $r->rate_type];
    }
}
