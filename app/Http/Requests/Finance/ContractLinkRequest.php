<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\ContractLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(ContractLink::TYPES))],
            'id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
