<?php

namespace App\Http\Requests\Finance;

/** Edit a contract: every field of the create rules is optional (`sometimes`). */
class UpdateContractRequest extends StoreContractRequest
{
    public function rules(): array
    {
        $rules = [];
        foreach (parent::rules() as $field => $rule) {
            if ($field === 'activate') {
                continue;
            }
            if ($field === 'ends_on' && ! $this->has('commenced_on')) {
                $rule = array_values(array_filter($rule, fn ($r) => ! (is_string($r) && str_starts_with($r, 'after_or_equal'))));
            }
            $rules[$field] = in_array('nullable', $rule, true) || str_contains((string) $field, '.') ? $rule : array_merge(['sometimes'], $rule);
        }

        return $rules;
    }
}
