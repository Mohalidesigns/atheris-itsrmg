<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Create a contract (ATH-FIN-001 §3.6). Authorisation is by gate in the controller. */
class StoreContractRequest extends FormRequest
{
    public const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    protected function inBank(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', (int) $this->user()->organization_id);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::in(Contract::TYPES)],
            'vendor_id' => ['nullable', 'integer', $this->inBank('vendors')],
            'legal_entity_id' => ['nullable', 'integer', $this->inBank('legal_entities')],
            'cost_centre_id' => ['nullable', 'integer', $this->inBank('fin_cost_centres')],
            'budget_line_id' => ['nullable', 'integer', $this->inBank('fin_budget_lines')],
            'category_id' => ['nullable', 'integer', $this->inBank('fin_categories')],
            'owner_id' => ['nullable', 'integer', $this->inBank('users')],
            'technical_owner_id' => ['nullable', 'integer', $this->inBank('users')],
            'procurement_contact_id' => ['nullable', 'integer', $this->inBank('users')],
            'description' => ['nullable', 'string', 'max:10000'],
            'commenced_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:commenced_on'],
            'term_months' => ['nullable', 'integer', 'between:1,600'],
            'notice_period_days' => ['nullable', 'integer', 'between:0,3650'],
            'renewal_type' => ['nullable', Rule::in(Contract::RENEWAL_TYPES)],
            'auto_renew' => ['nullable', 'boolean'],
            'renewal_lead_days' => ['nullable', 'integer', 'between:0,3650'],
            'notification_offsets' => ['nullable', 'array', 'max:12'],
            'notification_offsets.*' => ['integer', 'between:0,730'],
            'currency' => ['nullable', 'string', 'size:3'],
            'contract_value' => ['nullable', 'regex:'.self::MONEY],
            'billing_frequency' => ['nullable', Rule::in(Contract::BILLING)],
            'amount_per_billing' => ['nullable', 'regex:'.self::MONEY],
            'licence_metric' => ['nullable', Rule::in(Contract::LICENCE_METRICS)],
            'licence_quantity' => ['nullable', 'regex:'.self::MONEY],
            'deployed_quantity' => ['nullable', 'regex:'.self::MONEY],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,365'],
            'price_escalation_pct' => ['nullable', 'numeric', 'between:0,100'],
            'data_processing_role' => ['nullable', Rule::in(Contract::DP_ROLES)],
            'dpa_status' => ['nullable', Rule::in(Contract::DPA)],
            'sla_summary' => ['nullable', 'string', 'max:10000'],
            'exit_terms' => ['nullable', 'string', 'max:10000'],
            'key_clauses' => ['nullable', 'array', 'max:20'],
            'key_clauses.*.key' => ['required', 'string', 'max:40'],
            'key_clauses.*.label' => ['required', 'string', 'max:80'],
            'key_clauses.*.value' => ['nullable', 'string', 'max:500'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['contract_value.regex' => 'The contract value must be an amount with up to 2 decimals.'];
    }
}
