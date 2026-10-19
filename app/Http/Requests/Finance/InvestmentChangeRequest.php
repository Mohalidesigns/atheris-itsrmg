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
            'kind' => ['nullable', 'in:scope,reassign'],
            'description' => [$this->input('kind') === 'reassign' ? 'nullable' : 'required', 'string', 'max:5000'], 'reason' => ['required', 'string', 'max:5000'],
            'cost_delta' => ['nullable', 'regex:/^-?\d{1,16}(\.\d{1,2})?$/'], 'schedule_delta_days' => ['nullable', 'integer', 'between:-3650,3650'],
            'field_changes' => ['nullable', 'array'], 'field_changes.sponsor_id' => ['nullable', 'integer'], 'field_changes.manager_id' => ['nullable', 'integer'],
            'field_changes.cost_centre_id' => ['nullable', 'integer'], 'field_changes.rgt' => ['nullable', 'in:run,grow,transform'], 'field_changes.is_regulatory' => ['nullable', 'boolean'],
        ];
    }
}
