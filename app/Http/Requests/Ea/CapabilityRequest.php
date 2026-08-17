<?php

namespace App\Http\Requests\Ea;

class CapabilityRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('capability')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_capabilities,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:ea_capabilities,id'],
            'level' => ['nullable', 'integer', 'between:1,5'],
            'owner_role' => ['nullable', 'string', 'max:64'],
            'criticality' => ['nullable', 'in:critical,high,medium,low'],
            'maturity' => ['nullable', 'integer', 'between:1,5'],
            'source' => ['nullable', 'in:bian,custom'],
            'plateau_id' => ['nullable', 'integer', 'exists:ea_plateaux,id'],
            'last_verified_at' => ['nullable', 'date'],
        ];
    }
}
