<?php

namespace App\Http\Requests\Ea;

class DiagramRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('diagram')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_diagrams,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'viewpoint' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'elements_json' => ['nullable', 'array'],
            'edges_json' => ['nullable', 'array'],
            'status' => ['nullable', 'in:draft,review,approved,archived'],
        ];
    }
}
