<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Investment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvestmentFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:200'],
            'type' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(Investment::TYPES)],
            'rgt' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(Investment::RGT)],
            'driver' => [$this->isMethod('post') ? 'required' : 'sometimes', Rule::in(Investment::DRIVERS)],
            'sponsor_id' => ['nullable', 'integer'], 'manager_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'],
            'legal_entity_id' => ['nullable', 'integer'], 'fiscal_year_id' => ['nullable', 'integer'],
            'starts_on' => ['nullable', 'date'], 'planned_ends_on' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:10000'], 'strategic_objective' => ['nullable', 'string', 'max:2000'],
            'is_regulatory' => ['sometimes', 'boolean'], 'regulatory_reference' => ['nullable', 'string', 'max:200'],
            'cost_tolerance_pct' => ['nullable', 'numeric', 'between:0,100'], 'external_reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
