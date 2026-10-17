<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Record a goods receipt against a purchase order (FIN-03.03). */
class ReceiveGoodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.po_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,13}(\.\d{1,4})?$/'], 'lines.*.accepted' => ['nullable', 'boolean'], 'lines.*.rejection_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
