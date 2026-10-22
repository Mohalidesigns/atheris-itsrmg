<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\CloudAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Create or edit a cloud account (plan P6-19). Same-bank checks of the ids are in CloudCostService. */
class CloudAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageCloud', CloudAccount::class);
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(CloudAccount::PROVIDERS)],
            'account_identifier' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:160'],
            'default_format' => ['required', Rule::in(CloudAccount::FORMATS)],
            'currency' => ['required', 'string', 'size:3'],
            'default_cost_centre_id' => ['required', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'tag_mapping' => ['nullable', 'array', 'max:50'],
            'tag_mapping.*' => ['string', Rule::in(CloudAccount::DIMENSIONS)],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
