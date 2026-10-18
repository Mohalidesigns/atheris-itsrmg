<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Investment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvanceGateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_stage' => ['required', Rule::in(Investment::STAGES)],
            'ticked' => ['nullable', 'array'], 'ticked.*' => ['string', 'max:64'], 'note' => ['nullable', 'string', 'max:2000'],
            'payload' => ['nullable', 'array'], 'payload.actual_ends_on' => ['nullable', 'date'],
        ];
    }
}
