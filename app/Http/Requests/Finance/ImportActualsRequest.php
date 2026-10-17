<?php

namespace App\Http\Requests\Finance;

use App\Models\Finance\ImportMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Upload a GL extract CSV for preview or apply (FIN-03.05, .15). */
class ImportActualsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('finance.actuals.max_kb')], 'mode' => ['required', Rule::in(['preview', 'apply'])],
            'mapping_id' => ['nullable', 'integer'], 'columns' => ['nullable', 'array'], 'columns.*' => ['nullable', 'string', 'max:120'],
            'delimiter' => ['nullable', 'string', 'size:1'], 'date_format' => ['nullable', Rule::in(config('finance.actuals.date_formats'))],
            'decimal_comma' => ['nullable', 'boolean'], 'has_header' => ['nullable', 'boolean'], 'default_currency' => ['nullable', 'string', 'size:3'],
            'skip_invalid' => ['nullable', 'boolean'], 'force' => ['nullable', 'boolean'], 'save_mapping_as' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return list<string> the keys a mapping may carry */
    public static function mappingKeys(): array
    {
        return array_merge(ImportMapping::REQUIRED, ImportMapping::OPTIONAL);
    }
}
