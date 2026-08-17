<?php

namespace App\Http\Requests\Ea;

class DpiaRequest extends EaBaseRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:32'],
            'subject' => ['required', 'string', 'max:255'],
            'data_flow_id' => ['nullable', 'integer', 'exists:ea_data_flows,id'],
            'logical_entity_id' => ['nullable', 'integer', 'exists:ea_logical_entities,id'],
            'answers' => ['nullable', 'array'],
            'risk_score' => ['nullable', 'numeric'],
            'risk_band' => ['nullable', 'in:low,medium,high'],
            'mitigation_plan' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,in_review,approved,rejected'],
        ];
    }
}
