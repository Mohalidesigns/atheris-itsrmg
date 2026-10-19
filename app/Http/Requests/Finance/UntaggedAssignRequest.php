<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\CloudAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UntaggedAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageCloud', CloudAccount::class);
    }

    public function rules(): array
    {
        return [
            'cloud_account_id' => ['required', 'integer'],
            'resource_id' => ['nullable', 'string', 'max:512'],
            'service' => ['nullable', 'string', 'max:160'],
            'dimension' => ['required', Rule::in(CloudAccount::DIMENSIONS)],
            'target_id' => ['required', 'integer'],
            'create_rule' => ['sometimes', 'boolean'],
            'tag_key' => ['nullable', 'string', 'max:128'],
        ];
    }
}
