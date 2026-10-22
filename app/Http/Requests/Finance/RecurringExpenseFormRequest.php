<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\RecurringExpense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create or edit a recurring expense (FIN-03.07). */
class RecurringExpenseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'], 'contract_id' => ['nullable', 'integer'], 'budget_line_id' => ['nullable', 'integer'], 'vendor_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'], 'gl_account_id' => ['nullable', 'integer'], 'tax_type_id' => ['nullable', 'integer'], 'cost_centre_id' => ['required', 'integer'],
            'frequency' => ['required', Rule::in(array_keys(RecurringExpense::FREQUENCIES))], 'amount' => ['required', 'regex:'.ExpenseFormRequest::MONEY],
            'currency' => ['nullable', 'string', 'size:3'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date'],
            'auto_generate' => ['nullable', Rule::in(RecurringExpense::AUTO_GENERATE)], 'is_active' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
