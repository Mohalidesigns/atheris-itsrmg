<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ClassifyLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'line_ids' => ['required', 'array', 'min:1', 'max:500'],
            'line_ids.*' => ['integer'],
            'ea_application_id' => ['sometimes', 'nullable', 'integer'],
            'business_service_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
