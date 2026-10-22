<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;

class InvestmentProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'percent_complete' => ['required', 'numeric', 'between:0,100'], 'as_of' => ['nullable', 'date'],
            'eac' => ['nullable', 'regex:'.FinanceSettings::DOA_AMOUNT], 'eac_reason' => ['nullable', 'string', 'max:2000'], 'clear_eac' => ['sometimes', 'boolean'],
        ];
    }
}
