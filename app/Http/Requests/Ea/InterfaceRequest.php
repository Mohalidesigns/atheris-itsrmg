<?php

namespace App\Http\Requests\Ea;

class InterfaceRequest extends EaBaseRequest
{
    public function rules(): array
    {
        $id = $this->route('interface')?->id;
        return [
            'code' => ['required', 'string', 'max:32', "unique:ea_interfaces,code,$id"],
            'name' => ['required', 'string', 'max:255'],
            'source_app_id' => ['nullable', 'integer', 'exists:ea_applications_ext,id'],
            'target_app_id' => ['nullable', 'integer', 'exists:ea_applications_ext,id'],
            'protocol' => ['nullable', 'string', 'max:32'],
            'pattern' => ['nullable', 'in:sync,async,batch,file,event'],
            'classification' => ['nullable', 'string', 'max:32'],
            'pii_carrying' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:proposed,active,deprecated,retired'],

            // CBN Risk-Based Cybersecurity Framework App. II §1.1(i)–(k):
            // a catalogue of all network connections to regulatory authorities,
            // switches and third parties, with the objective of each connection
            // documented and regularly reviewed. See ATH-EAR-002 Appendix B.
            'objective' => ['nullable', 'string', 'max:2000'],
            'counterparty_type' => ['nullable', 'in:internal,regulator,switch,third_party'],
            'review_cadence' => ['nullable', 'in:monthly,quarterly,semi_annual,annual'],
            'last_reviewed_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'objective.max' => 'Keep the connection objective under 2,000 characters — it is a regulatory field, not a design document.',
        ];
    }
}
