<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Allocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AllocationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageAllocations', Allocation::class);
    }

    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['required', 'integer'],
            'period_ids' => ['nullable', 'array', 'max:13'],
            'period_ids.*' => ['integer'],
            'amount_types' => ['required', 'array', 'min:1'],
            'amount_types.*' => [Rule::in(Allocation::AMOUNT_TYPES)],
        ];
    }
}
