<?php

namespace App\Http\Requests\Ea;

class ArbRequest extends EaBaseRequest
{
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'impact_blast_radius' => ['nullable', 'array'],
            'impacted_principles' => ['nullable', 'array'],
            'impacted_standards' => ['nullable', 'array'],
            'meeting_date' => ['nullable', 'date'],
            'voters' => ['nullable', 'array'],
            'risk_band' => ['nullable', 'in:low,medium,high,critical'],
        ];
    }
}
