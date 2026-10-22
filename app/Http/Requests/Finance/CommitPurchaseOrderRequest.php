<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Commit an approved expense request as a purchase order (FIN-03.03). */
class CommitPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'po_date' => ['nullable', 'date'], 'expected_delivery_on' => ['nullable', 'date'], 'external_reference' => ['nullable', 'string', 'max:64'], 'tax_type_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,13}(\.\d{1,4})?$/'], 'lines.*.unit_cost' => ['required', 'regex:'.ExpenseFormRequest::MONEY],
            'lines.*.category_id' => ['nullable', 'integer'], 'lines.*.asset_id' => ['nullable', 'integer'],
        ];
    }
}
