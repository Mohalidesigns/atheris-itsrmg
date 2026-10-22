<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Category;
use App\Models\Finance\CostCentre;
use App\Models\Finance\Driver;
use App\Models\Finance\GlAccount;
use App\Models\Finance\Period;
use App\Services\Finance\FinanceCsvImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/** Cost centres, TBM categories, GL accounts and cost drivers (ATH-FIN-001 FIN-01.02 to .04, .10). */
class FinanceDimensionsController extends Controller
{
    private const CODE_RULE = '/^[A-Z0-9][A-Z0-9._-]*$/';

    private function done(string $tab, string $message)
    {
        return redirect()->route('finance.settings', ['tab' => $tab])->with('success', $message);
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }

    private function inOrg(string $table, int $orgId, array $extra = []): Exists
    {
        $rule = Rule::exists($table, 'id')->where('organization_id', $orgId);
        if (in_array($table, ['fin_cost_centres', 'fin_gl_accounts', 'fin_categories'], true)) {
            $rule = $rule->whereNull('deleted_at');
        }
        foreach ($extra as $col => $vals) {
            $rule = $rule->whereIn($col, (array) $vals);
        }

        return $rule;
    }

    /* ------------------------------ cost centres ------------------------------ */

    private function costCentreData(Request $request, ?CostCentre $existing = null): array
    {
        $orgId = $request->user()->organization_id;
        $unique = Rule::unique('fin_cost_centres', 'code')->where('organization_id', $orgId);
        if ($existing) {
            $unique = $unique->ignore($existing->id);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:'.self::CODE_RULE, $unique],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', $this->inOrg('fin_cost_centres', $orgId)],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $orgId)],
            'legal_entity_id' => ['nullable', 'integer', Rule::exists('legal_entities', 'id')->where(fn ($q) => $q->where('organization_id', $orgId)->orWhereNull('organization_id'))],
            'business_unit' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    public function costCentreStore(Request $request)
    {
        Gate::authorize('create', CostCentre::class);
        CostCentre::create($this->costCentreData($request));

        return $this->done('cost-centres', 'Cost centre created.');
    }

    public function costCentreUpdate(Request $request, CostCentre $costCentre)
    {
        Gate::authorize('update', $costCentre);
        $data = $this->costCentreData($request, $costCentre);
        $parent = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parent && ($parent === $costCentre->id || in_array($parent, $costCentre->descendantIds(), true))) {
            $this->refuse('A cost centre cannot be its own parent or sit under one of its descendants.');
        }
        $costCentre->update($data);

        return $this->done('cost-centres', 'Cost centre updated.');
    }

    public function costCentreDestroy(CostCentre $costCentre)
    {
        Gate::authorize('delete', $costCentre);
        if ($costCentre->children()->exists()) {
            $this->refuse('Move or delete the child cost centres first.');
        }
        $costCentre->delete();

        return $this->done('cost-centres', 'Cost centre deleted.');
    }

    public function costCentreImport(Request $request, FinanceCsvImporter $importer)
    {
        Gate::authorize('create', CostCentre::class);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $report = $importer->costCentres($request->user()->organization_id, $request->file('file'));

        return $this->done('cost-centres', "Imported {$report['accepted_count']} of {$report['row_count']} rows.")->with('import_report', $report);
    }

    /* -------------------------------- categories -------------------------------- */

    public function categoryStore(Request $request)
    {
        Gate::authorize('create', Category::class);
        $orgId = $request->user()->organization_id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:'.self::CODE_RULE, Rule::unique('fin_categories', 'code')->where('organization_id', $orgId)],
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['required', 'integer', $this->inOrg('fin_categories', $orgId, ['kind' => ['cost_pool', 'tower']])],
            'default_capex' => ['sometimes', 'boolean'],
            'default_gl_account_id' => ['nullable', 'integer', $this->inOrg('fin_gl_accounts', $orgId)],
            'tbm_reference' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        Category::create($data + ['kind' => 'sub_category', 'is_system' => false, 'sort_order' => 1000]);

        return $this->done('categories', 'Category created.');
    }

    public function categoryUpdate(Request $request, Category $category)
    {
        Gate::authorize('update', $category);
        $orgId = $category->organization_id;

        if ($category->is_system) {
            // Locked keys (code, kind, parent_id, tbm_reference) are silently ignored.
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'],
                'default_capex' => ['sometimes', 'boolean'],
                'default_gl_account_id' => ['nullable', 'integer', $this->inOrg('fin_gl_accounts', $orgId)],
                'is_active' => ['sometimes', 'boolean'],
                'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            ]);
        } else {
            $data = $request->validate([
                'code' => ['required', 'string', 'max:32', 'regex:'.self::CODE_RULE, Rule::unique('fin_categories', 'code')->where('organization_id', $orgId)->ignore($category->id)],
                'name' => ['required', 'string', 'max:120'],
                'parent_id' => ['required', 'integer', $this->inOrg('fin_categories', $orgId, ['kind' => ['cost_pool', 'tower']])],
                'default_capex' => ['sometimes', 'boolean'],
                'default_gl_account_id' => ['nullable', 'integer', $this->inOrg('fin_gl_accounts', $orgId)],
                'tbm_reference' => ['nullable', 'string', 'max:64'],
                'is_active' => ['sometimes', 'boolean'],
                'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            ]);
        }
        $category->update($data);

        return $this->done('categories', 'Category updated.');
    }

    public function categoryDestroy(Category $category)
    {
        Gate::authorize('delete', $category);
        if ($category->is_system) {
            $this->refuse('System categories cannot be deleted; deactivate them instead.');
        }
        if ($category->children()->exists()) {
            $this->refuse('Delete or move the sub-categories first.');
        }
        $category->delete();

        return $this->done('categories', 'Category deleted.');
    }

    /* ------------------------------- GL accounts ------------------------------- */

    private function glData(Request $request, ?GlAccount $existing = null): array
    {
        $unique = Rule::unique('fin_gl_accounts', 'code')->where('organization_id', $request->user()->organization_id);
        if ($existing) {
            $unique = $unique->ignore($existing->id);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:32', $unique],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(GlAccount::TYPES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    public function glAccountStore(Request $request)
    {
        Gate::authorize('create', GlAccount::class);
        GlAccount::create($this->glData($request));

        return $this->done('gl-accounts', 'GL account created.');
    }

    public function glAccountUpdate(Request $request, GlAccount $glAccount)
    {
        Gate::authorize('update', $glAccount);
        $glAccount->update($this->glData($request, $glAccount));

        return $this->done('gl-accounts', 'GL account updated.');
    }

    public function glAccountDestroy(GlAccount $glAccount)
    {
        Gate::authorize('delete', $glAccount);
        if ($glAccount->categories()->exists()) {
            $this->refuse('This GL account is the default for one or more categories; reassign them first.');
        }
        $glAccount->delete();

        return $this->done('gl-accounts', 'GL account deleted.');
    }

    /* --------------------------------- drivers --------------------------------- */

    private function driverData(Request $request, ?Driver $existing = null): array
    {
        $unique = Rule::unique('fin_drivers', 'code')->where('organization_id', $request->user()->organization_id);
        if ($existing) {
            $unique = $unique->ignore($existing->id);
        }

        return $request->validate([
            'code' => ['required', 'string', 'max:32', $unique],
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['required', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    public function driverStore(Request $request)
    {
        Gate::authorize('create', Driver::class);
        Driver::create($this->driverData($request));

        return $this->done('drivers', 'Driver created.');
    }

    public function driverUpdate(Request $request, Driver $driver)
    {
        Gate::authorize('update', $driver);
        $driver->update($this->driverData($request, $driver));

        return $this->done('drivers', 'Driver updated.');
    }

    public function driverDestroy(Driver $driver)
    {
        Gate::authorize('delete', $driver);
        DB::transaction(function () use ($driver) {
            $driver->values()->delete();
            $driver->delete();
        });

        return $this->done('drivers', 'Driver deleted.');
    }

    public function driverValuesUpdate(Request $request, Driver $driver)
    {
        Gate::authorize('update', $driver);
        $orgId = $driver->organization_id;
        $data = $request->validate([
            'values' => ['required', 'array', 'max:400'],
            'values.*.period_id' => ['required', 'integer', Rule::exists('fin_periods', 'id')->where('organization_id', $orgId)],
            'values.*.value' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999999999999'],
        ]);
        $notOpen = Period::withoutGlobalScopes()->where('organization_id', $orgId)
            ->whereIn('id', collect($data['values'])->pluck('period_id'))->where('status', '!=', 'open')->exists();
        if ($notOpen) {
            $this->refuse('Driver values can only be changed in open periods.');
        }

        DB::transaction(function () use ($data, $driver, $orgId) {
            foreach ($data['values'] as $v) {
                $existing = $driver->values()->where('period_id', $v['period_id'])->first();
                if ($v['value'] === null) {
                    $existing?->delete();
                } elseif ($existing) {
                    $existing->update(['value' => $v['value']]);
                } else {
                    $driver->values()->create(['organization_id' => $orgId, 'period_id' => $v['period_id'], 'value' => $v['value']]);
                }
            }
        });

        return $this->done('drivers', 'Driver values saved.');
    }
}
