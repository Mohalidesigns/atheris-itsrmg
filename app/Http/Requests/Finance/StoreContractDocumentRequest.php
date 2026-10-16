<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\ContractDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContractDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(ContractDocument::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'file' => ['nullable', 'file', 'max:'.(int) config('finance.contracts.document_max_kb'), 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,msg,eml,zip'],
            'signed_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:signed_on'],
        ];
    }
}
