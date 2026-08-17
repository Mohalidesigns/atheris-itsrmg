<?php

namespace App\Http\Requests\Ea;

class StandardRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('standard')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_standards,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string'],
            'radar_status' => ['nullable', 'in:adopt,trial,assess,hold'],
            'status' => ['nullable', 'in:draft,active,retired'],
        ];
    }
}
