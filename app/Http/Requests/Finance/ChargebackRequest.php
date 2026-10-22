<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Allocation;
use App\Models\Finance\ChargebackRun;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChargebackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('exportChargeback', ChargebackRun::class);
    }

    public function rules(): array
    {
        return ['period_id' => ['required', 'integer'], 'target_type' => ['required', Rule::in(Allocation::TARGET_TYPES)]];
    }
}
