<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class InvestmentChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:5000'], 'reason' => ['required', 'string', 'max:5000'],
            'cost_delta' => ['nullable', 'regex:/^-?\d{1,16}(\.\d{1,2})?$/'], 'schedule_delta_days' => ['nullable', 'integer', 'between:-3650,3650'],
        ];
    }
}
