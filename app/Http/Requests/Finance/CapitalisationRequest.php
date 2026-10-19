<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Capitalisation;
use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CapitalisationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'source_type' => [$creating ? 'required' : 'sometimes', Rule::in(Capitalisation::SOURCE_TYPES)],
            'source_id' => [$creating ? 'required' : 'sometimes', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'standard' => ['required', Rule::in(Capitalisation::STANDARDS)],
            'available_for_use_on' => ['required', 'date'],
            'recognition_date' => ['nullable', 'date'],
            'amount' => ['required', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'excluded_amount' => ['nullable', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'excluded_reason' => ['nullable', 'string', 'max:2000'],
            'residual_value' => ['nullable', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:'.(int) config('finance.capitalisation.max_life_months', 600)],
            'method' => ['required', Rule::in(Capitalisation::METHODS)],
            'declining_rate_pct' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'create_asset' => ['sometimes', 'boolean'],
            'asset_id' => ['nullable', 'integer'],
            'asset_name' => ['nullable', 'string', 'max:200'],
            'asset_type' => ['nullable', Rule::in(Capitalisation::ASSET_TYPES)],
            'asset_owner_id' => ['nullable', 'integer'],
            'criteria_checklist' => ['sometimes', 'array', 'max:20'],
            'criteria_checklist.*.key' => ['required', 'string', 'max:64'],
            'criteria_checklist.*.met' => ['sometimes', 'boolean'],
            'criteria_checklist.*.note' => ['nullable', 'string', 'max:1000'],
            'criteria_checklist.*.document_id' => ['nullable', 'integer'],
        ];
    }
}
