<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Benefit;
use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BenefitFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:200'],
            'type' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(Benefit::TYPES)],
            'unit' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(Benefit::UNITS)],
            'aggregation' => ['nullable', Rule::in(Benefit::AGGREGATIONS)],
            'description' => ['nullable', 'string', 'max:5000'], 'measurement_method' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer'], 'baseline_value' => ['nullable', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'target_value' => [$this->isMethod('post') ? 'required' : 'sometimes', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'due_on' => ['nullable', 'date'],
        ];
    }
}
