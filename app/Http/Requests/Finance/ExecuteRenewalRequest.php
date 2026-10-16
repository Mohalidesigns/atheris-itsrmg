<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['terminated_on' => ['nullable', 'date'], 'commenced_on' => ['nullable', 'date']];
    }
}
