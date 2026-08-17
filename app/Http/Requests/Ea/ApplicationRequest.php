<?php

namespace App\Http\Requests\Ea;

class ApplicationRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('application')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_applications_ext,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'time_score' => ['nullable', 'in:Tolerate,Invest,Migrate,Eliminate'],
            'six_r_score' => ['nullable', 'in:Rehost,Replatform,Repurchase,Refactor,Retire,Retain'],
            'business_fit' => ['nullable', 'integer', 'between:1,5'],
            'technical_fit' => ['nullable', 'integer', 'between:1,5'],
            'criticality' => ['nullable', 'in:critical,high,medium,low'],
            'lifecycle' => ['nullable', 'in:plan,build,live,sunset,retired'],
            'annual_cost_ngn' => ['nullable', 'numeric', 'min:0'],
            'user_count' => ['nullable', 'integer', 'min:0'],
            'owner_role' => ['nullable', 'string', 'max:64'],
            'capability_ids' => ['nullable', 'array'],
            'capability_ids.*' => ['integer', 'exists:ea_capabilities,id'],
            'plateau_id' => ['nullable', 'integer', 'exists:ea_plateaux,id'],
            'vendor_id' => ['nullable', 'integer'],
            'tco_annual_ngn' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
