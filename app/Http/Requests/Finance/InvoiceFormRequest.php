<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Capture or edit a vendor invoice (FIN-03.04). */
class InvoiceFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $money = 'regex:'.ExpenseFormRequest::MONEY;

        return [
            'vendor_invoice_no' => ['required', 'string', 'max:64'], 'vendor_id' => ['nullable', 'integer'], 'po_id' => ['nullable', 'integer'],
            'expense_request_id' => ['nullable', 'integer'], 'contract_id' => ['nullable', 'integer'], 'invoice_date' => ['required', 'date'], 'due_on' => ['nullable', 'date'],
            'received_on' => ['nullable', 'date'], 'currency' => ['nullable', 'string', 'size:3'], 'subtotal' => ['nullable', $money], 'tax_type_id' => ['nullable', 'integer'],
            'wht_tax_type_id' => ['nullable', 'integer'], 'budget_line_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'gl_account_id' => ['nullable', 'integer'], 'period_id' => ['nullable', 'integer'], 'description' => ['nullable', 'string', 'max:5000'],
            'prepayment_months' => ['nullable', 'integer', 'between:2,36'], 'is_capex' => ['nullable', 'boolean'],
            'lines' => ['nullable', 'array', 'max:200'], 'lines.*.po_line_id' => ['nullable', 'integer'], 'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'regex:/^\d{1,13}(\.\d{1,4})?$/'], 'lines.*.unit_cost' => ['required_with:lines', $money],
            'lines.*.category_id' => ['nullable', 'integer'], 'lines.*.asset_id' => ['nullable', 'integer'],
        ];
    }
}
