<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class VarianceExplainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'explanation' => ['required', 'string', 'min:20', 'max:4000'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
