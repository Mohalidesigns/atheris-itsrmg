<?php

namespace App\Http\Requests\Ea;

class PrincipleRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('principle')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_principles,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'statement' => ['nullable', 'string'],
            'rationale' => ['nullable', 'string'],
            'implications' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,draft,retired'],
        ];
    }
}
