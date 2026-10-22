<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ValueReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'due_on' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'], 'reviewer_id' => ['nullable', 'integer'], 'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
