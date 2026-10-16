<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Renewal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecommendRenewalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recommendation' => ['required', Rule::in(Renewal::DECISIONS)],
            'proposed_term_months' => ['nullable', 'integer', 'between:1,120'],
            'proposed_commences_on' => ['nullable', 'date'],
            'proposed_value' => ['nullable', 'regex:'.StoreContractRequest::MONEY],
            'proposed_currency' => ['nullable', 'string', 'size:3'],
            'justification' => ['nullable', 'string', 'max:10000'],
            'benchmark_notes' => ['nullable', 'string', 'max:10000'],
            'alternatives_considered' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
