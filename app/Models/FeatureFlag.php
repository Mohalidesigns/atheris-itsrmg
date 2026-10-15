<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

/**
 * Not tenant-scoped: organization_id NULL rows are platform defaults; a row for a bank is that
 * bank's override.
 */
class FeatureFlag extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['enabled' => 'boolean', 'payload' => 'array'];

    /** Bank override first, else the platform default; null when the flag does not exist. */
    public static function enabledFor(string $key, ?int $orgId): ?bool
    {
        if ($orgId !== null) {
            $own = static::where('key', $key)->where('organization_id', $orgId)->value('enabled');
            if ($own !== null) {
                return (bool) $own;
            }
        }
        $global = static::where('key', $key)->whereNull('organization_id')->value('enabled');

        return $global === null ? null : (bool) $global;
    }
}
