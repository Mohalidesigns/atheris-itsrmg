<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\ContractTeamMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('organization_id', (int) $this->user()->organization_id)],
            'role' => ['required', Rule::in(ContractTeamMember::ROLES)],
        ];
    }
}
