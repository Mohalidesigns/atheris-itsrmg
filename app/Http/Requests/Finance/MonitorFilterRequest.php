<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Allocation;
use App\Models\Finance\BudgetLine;
use App\Models\Finance\VarianceNote;
use App\Services\Finance\MonitoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Every filter of the monitoring pages, the dashboard and the reports; ids must exist in the user's bank. */
class MonitorFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function inOrg(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->user()?->organization_id);
    }

    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['nullable', 'integer', $this->inOrg('fin_fiscal_years')],
            'from_period_id' => ['nullable', 'integer', $this->inOrg('fin_periods')],
            'to_period_id' => ['nullable', 'integer', $this->inOrg('fin_periods')],
            'period_id' => ['nullable', 'integer', $this->inOrg('fin_periods')],
            'view' => ['nullable', Rule::in(MonitoringService::VIEWS)],
            'group_by' => ['nullable', Rule::in(MonitoringService::GROUP_BY)],
            'cost_centre_id' => ['nullable', 'integer', $this->inOrg('fin_cost_centres')],
            'tower_id' => ['nullable', 'integer', $this->inOrg('fin_categories')],
            'cost_pool_id' => ['nullable', 'integer', $this->inOrg('fin_categories')],
            'category_id' => ['nullable', 'integer', $this->inOrg('fin_categories')],
            'gl_account_id' => ['nullable', 'integer', $this->inOrg('fin_gl_accounts')],
            'legal_entity_id' => ['nullable', 'integer', Rule::exists('legal_entities', 'id')->where('organization_id', $this->user()?->organization_id)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->where('organization_id', $this->user()?->organization_id)],
            'rgt' => ['nullable', Rule::in(BudgetLine::RGT)],
            'capex' => ['nullable', Rule::in(['0', '1', 0, 1, true, false, 'capex', 'opex'])],
            'severity' => ['nullable', Rule::in(VarianceNote::SEVERITIES)],
            'status' => ['nullable', Rule::in(VarianceNote::STATUSES)],
            'needs_explanation' => ['nullable', 'boolean'],
            'report' => ['nullable', 'string', 'max:40'],
            'account_id' => ['nullable', 'integer', $this->inOrg('fin_cloud_accounts')],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'target_type' => ['nullable', Rule::in(Allocation::TARGET_TYPES)],
            'basis' => ['nullable', Rule::in(['actual', 'budget'])],
            'subject' => ['nullable', Rule::in(['ea_application', 'business_service'])],
            'window' => ['nullable', Rule::in(['all', '30', '60', '90'])],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'format' => ['nullable', 'string', 'max:8'],
        ];
    }
}
