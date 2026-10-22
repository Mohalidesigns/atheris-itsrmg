<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Allocation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageAllocations', Allocation::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'source_type' => ['required', Rule::in(Allocation::SOURCE_TYPES)],
            'source_id' => ['required', 'integer'],
            'target_type' => ['required', Rule::in(Allocation::TARGET_TYPES)],
            'target_id' => ['nullable', 'integer'],
            'target_ref' => ['nullable', 'string', 'max:32'],
            'method' => ['required', Rule::in(Allocation::METHODS)],
            'percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'driver_id' => ['nullable', 'integer'],
            'driver_quantity' => ['nullable', 'numeric', 'gt:0'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'journal_cost_centre_code' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
