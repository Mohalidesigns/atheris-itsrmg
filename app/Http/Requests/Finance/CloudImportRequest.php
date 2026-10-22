<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\CloudAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CloudImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('manageCloud', CloudAccount::class);
    }

    public function rules(): array
    {
        return [
            'cloud_account_id' => ['required', 'integer'],
            'format' => ['nullable', Rule::in(CloudAccount::FORMATS)],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('finance.cloud.max_kb', 51200)],
        ];
    }
}
