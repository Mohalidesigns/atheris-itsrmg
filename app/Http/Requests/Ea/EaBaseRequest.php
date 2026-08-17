<?php

namespace App\Http\Requests\Ea;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base FormRequest for every EA mutation. Subclasses define `rules()`. The
 * `authorize()` default returns true so the controller-level policy gate
 * remains the single source of truth (see `app/Policies/Ea/*`).
 */
abstract class EaBaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
