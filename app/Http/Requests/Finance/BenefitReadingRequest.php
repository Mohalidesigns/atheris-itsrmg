<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\FinanceSettings;
use Illuminate\Foundation\Http\FormRequest;

class BenefitReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_id' => ['required', 'integer'], 'value' => ['required', 'regex:'.FinanceSettings::DOA_AMOUNT],
            'note' => ['nullable', 'string', 'max:500'], 'file' => ['nullable', 'file', 'max:'.(int) config('finance.contracts.document_max_kb'), 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,msg,eml,zip'],
            'document_title' => ['nullable', 'string', 'max:200'],
        ];
    }
}
