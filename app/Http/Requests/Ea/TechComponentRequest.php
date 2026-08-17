<?php

namespace App\Http\Requests\Ea;

class TechComponentRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('tech')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_tech_components_ext,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'string', 'max:64'],
            'cpe' => ['nullable', 'string', 'max:128'],
            'radar_status' => ['nullable', 'in:adopt,trial,assess,hold'],
            'eol_date' => ['nullable', 'date'],
            'eos_date' => ['nullable', 'date'],
            'plateau_id' => ['nullable', 'integer', 'exists:ea_plateaux,id'],
        ];
    }
}
