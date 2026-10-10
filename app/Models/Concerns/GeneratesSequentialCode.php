<?php

namespace App\Models\Concerns;

/**
 * Continues a bank's own record-code series: after "FBN-ALR-050" comes "FBN-ALR-051", keeping the
 * prefix and zero-padding of the most recent code. A bank with no records yet starts at
 * "{$defaultPrefix}0001". Soft-deleted rows count, so a code is never reissued.
 */
trait GeneratesSequentialCode
{
    protected static function nextSequentialCode(int $organizationId, string $column, string $defaultPrefix): string
    {
        $query = fn () => static::withoutGlobalScopes()->where('organization_id', $organizationId);
        $last = $query()->orderByDesc('id')->value($column);

        [$prefix, $number, $width] = $last && preg_match('/^(.*?)(\d+)$/', $last, $m)
            ? [$m[1], (int) $m[2], strlen($m[2])]
            : [$defaultPrefix, 0, 4];

        do {
            $code = $prefix.str_pad((string) ++$number, $width, '0', STR_PAD_LEFT);
        } while ($query()->where($column, $code)->exists());

        return $code;
    }
}
