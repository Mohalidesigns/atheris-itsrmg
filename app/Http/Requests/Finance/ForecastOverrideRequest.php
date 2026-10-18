<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;

class ForecastOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'reason' => ['required', 'string', 'min:'.(int) config('finance.monitoring.override_reason_min', 10), 'max:2000'],
        ];
    }
}
