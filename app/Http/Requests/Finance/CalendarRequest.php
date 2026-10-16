<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\Contract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'view' => ['nullable', Rule::in(['month', 'quarter', 'year', 'timeline'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'vendor_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'member' => ['nullable', Rule::in(['me'])],
            'cost_centre_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(Contract::TYPES)],
            'value_band' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(Contract::STATUSES)],
            'sources' => ['nullable', 'array'],
            'sources.*' => ['string', Rule::in(['contracts', 'budget', 'periods'])],
            'format' => ['nullable', 'string', 'max:8'],
        ];
    }
}
