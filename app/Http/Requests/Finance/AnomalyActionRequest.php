<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Acknowledge (note optional) / resolve / dismiss (note of 10 characters or more, enforced by the service). */
class AnomalyActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:2000']];
    }
}
