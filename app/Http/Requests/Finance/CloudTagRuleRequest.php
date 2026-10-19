<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\CloudAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CloudTagRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageCloud', CloudAccount::class);
    }

    public function rules(): array
    {
        return [
            'cloud_account_id' => ['nullable', 'integer'],
            'tag_key' => ['required', 'string', 'max:128'],
            'tag_value' => ['required', 'string', 'max:255'],
            'dimension' => ['required', Rule::in(CloudAccount::DIMENSIONS)],
            'target_id' => ['required', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
