<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Create or edit an expense request (ATH-FIN-001 FIN-03.01). Authorisation is by gate in the controller. */
class ExpenseFormRequest extends FormRequest
{
    public const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'fiscal_year_id' => ['required', 'integer'], 'budget_line_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'], 'gl_account_id' => ['nullable', 'integer'], 'legal_entity_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'integer'], 'asset_id' => ['nullable', 'integer'],
            'is_capex' => ['nullable', 'boolean'], 'is_emergency' => ['nullable', 'boolean'], 'needed_by' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'], 'amount' => ['required', 'regex:'.self::MONEY], 'tax_type_id' => ['nullable', 'integer'],
            'justification' => ['nullable', 'string', 'max:5000'], 'overbudget_justification' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
