<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Finance\Budget;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\Period;
use App\Services\Finance\BudgetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Lines of a budget version: add, edit, delete, reorder, bulk grid save and phasing (FIN-02.04, FIN-02.13). */
class BudgetLineController extends Controller
{
    public function __construct(private readonly BudgetService $budgets) {}

    /** Validation shared by single-line and grid saves; nullable so a partial update keeps other fields. */
    public static function lineRules(string $prefix = ''): array
    {
        $r = [
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'cost_pool_id' => ['sometimes', 'required', 'integer'], 'tower_id' => ['sometimes', 'required', 'integer'], 'category_id' => ['nullable', 'integer'],
            'gl_account_id' => ['nullable', 'integer'], 'legal_entity_id' => ['nullable', 'integer'],
            'is_capex' => ['sometimes', 'boolean'], 'is_recurring' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', Rule::in(BudgetLine::PRIORITIES)], 'rgt' => ['sometimes', Rule::in(BudgetLine::RGT)],
            'vendor_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'integer'], 'asset_id' => ['nullable', 'integer'],
            'ea_application_id' => ['nullable', 'integer'], 'business_service_id' => ['nullable', 'integer'], 'investment_id' => ['nullable', 'integer'],
            'risk_id' => ['nullable', 'integer'], 'driver_id' => ['nullable', 'integer'], 'driver_rate' => ['nullable', 'numeric'],
            'quantity' => ['nullable', 'numeric'], 'unit_cost' => ['nullable', 'numeric'], 'currency' => ['sometimes', 'string', 'size:3'],
            'annual_amount' => ['nullable', 'numeric'], 'phasing_method' => ['sometimes', Rule::in(BudgetLine::PHASING)],
            'justification' => ['nullable', 'string', 'max:5000'], 'variance_amber_pct' => ['nullable', 'numeric'], 'variance_red_pct' => ['nullable', 'numeric'],
            'amounts' => ['nullable', 'array'], 'amounts.*' => ['nullable', 'numeric'], 'line_no' => ['nullable', 'integer', 'min:1'], 'sort_order' => ['nullable', 'integer'],
        ];

        return $prefix === '' ? $r : collect($r)->mapWithKeys(fn ($v, $k) => ["{$prefix}.{$k}" => $v])->all();
    }

    private function data(Request $request, bool $creating): array
    {
        $rules = self::lineRules();
        if ($creating) {
            $rules['description'] = ['required', 'string', 'max:255'];
            $rules['cost_pool_id'] = ['required', 'integer'];
            $rules['tower_id'] = ['required', 'integer'];
        }
        $request->validate($rules);

        return $request->only(array_keys($rules));
    }

    private function revisionNotice(Budget $old, Budget $draft)
    {
        return redirect()->route('finance.budgets.edit', $draft)
            ->with('warning', "Version {$old->version_no} is {$old->status} and immutable; your change was saved as version {$draft->version_no}.");
    }

    public function store(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $data = $this->data($request, true);
        if ($budget->is_locked) {
            $draft = $this->budgets->editLocked($budget, $request->user(), fn (Budget $d) => $this->budgets->addLine($d, $data, $request->user()));

            return $this->revisionNotice($budget, $draft);
        }
        $line = $this->budgets->addLine($budget, $data, $request->user());

        return back()->with('success', "Line {$line->line_no} added.");
    }

    public function update(Request $request, Budget $budget, BudgetLine $line)
    {
        Gate::authorize('updatePlan', $budget);
        $data = $this->data($request, false);
        if ($budget->is_locked) {
            $draft = $this->budgets->editLocked($budget, $request->user(), fn (Budget $d) => $this->budgets->updateLine($this->budgets->matchingLine($d, $line), $data, $request->user()));

            return $this->revisionNotice($budget, $draft);
        }
        $this->budgets->updateLine($line, $data, $request->user());

        return back()->with('success', "Line {$line->line_no} saved.");
    }

    public function destroy(Request $request, Budget $budget, BudgetLine $line)
    {
        Gate::authorize('updatePlan', $budget);
        if ($budget->is_locked) {
            $draft = $this->budgets->editLocked($budget, $request->user(), fn (Budget $d) => $this->budgets->deleteLine($this->budgets->matchingLine($d, $line)));

            return $this->revisionNotice($budget, $draft);
        }
        $this->budgets->deleteLine($line);

        return back()->with('success', 'Line deleted.');
    }

    public function reorder(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $ids = $request->validate(['ids' => ['required', 'array', 'max:2000'], 'ids.*' => ['integer']])['ids'];
        $this->budgets->reorder($budget, $ids);

        return back();
    }

    public function bulk(Request $request, Budget $budget)
    {
        Gate::authorize('updatePlan', $budget);
        $rules = ['lines' => ['present', 'array', 'max:'.config('finance.workbook.max_rows')], 'lines.*.id' => ['nullable', 'integer'],
            'deleted_ids' => ['nullable', 'array'], 'deleted_ids.*' => ['integer']] + self::lineRules('lines.*');
        $data = $request->validate($rules);
        $result = $this->budgets->bulkSave($budget, $data['lines'], $data['deleted_ids'] ?? [], $request->user());
        if ($result->id !== $budget->id) {
            return $this->revisionNotice($budget, $result);
        }

        return back()->with('success', 'Workbook saved.');
    }

    public function phase(Request $request, Budget $budget, BudgetLine $line)
    {
        Gate::authorize('updatePlan', $budget);
        $data = $request->validate(['phasing_method' => ['required', Rule::in(BudgetLine::PHASING)], 'amounts' => ['nullable', 'array'], 'amounts.*' => ['nullable', 'numeric']]);
        if (! empty($data['amounts'])) {
            $valid = Period::query()->where('fiscal_year_id', $budget->fiscal_year_id)->pluck('id')->all();
            foreach (array_keys($data['amounts']) as $pid) {
                abort_unless(in_array((int) $pid, $valid, true), 422, 'Unknown period.');
            }
        }
        if ($budget->is_locked) {
            $draft = $this->budgets->editLocked($budget, $request->user(), fn (Budget $d) => $this->budgets->phase($this->budgets->matchingLine($d, $line), $data['phasing_method'], $this->remap($d, $data['amounts'] ?? null)));

            return $this->revisionNotice($budget, $draft);
        }
        $this->budgets->phase($line, $data['phasing_method'], $data['amounts'] ?? null);

        return back()->with('success', "Line {$line->line_no} re-phased.");
    }

    /** Period ids are shared by every version of a fiscal year, so the amounts map applies unchanged. */
    private function remap(Budget $draft, ?array $amounts): ?array
    {
        return $amounts;
    }
}
