<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class BusinessCaseFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'horizon_years' => ['sometimes', 'integer', 'between:1,'.(int) config('finance.investments.max_horizon_years')],
            'discount_rate_pct' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'costs' => ['sometimes', 'array', 'max:40'], 'costs.*.year' => ['required', 'integer', 'between:0,30'],
            'costs.*.capex' => ['nullable', 'numeric', 'between:-1e13,1e13'], 'costs.*.opex' => ['nullable', 'numeric', 'between:-1e13,1e13'], 'costs.*.run' => ['nullable', 'numeric', 'between:-1e13,1e13'],
            'benefits' => ['sometimes', 'array', 'max:40'], 'benefits.*.year' => ['required', 'integer', 'between:0,30'], 'benefits.*.amount' => ['nullable', 'numeric', 'between:-1e13,1e13'],
            'non_financial_benefits' => ['sometimes', 'nullable', 'array', 'max:30'],
            'non_financial_benefits.*.label' => ['required', 'string', 'max:200'], 'non_financial_benefits.*.score' => ['required', 'integer', 'between:1,5'],
            'non_financial_benefits.*.note' => ['nullable', 'string', 'max:500'],
            'option_summary' => ['sometimes', 'nullable', 'string', 'max:10000'], 'recommended_option' => ['sometimes', 'nullable', 'string', 'max:200'],
            'assumptions' => ['sometimes', 'nullable', 'string', 'max:10000'], 'risks_summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ];
    }
}
