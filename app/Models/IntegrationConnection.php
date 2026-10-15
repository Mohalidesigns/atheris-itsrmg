<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class IntegrationConnection extends Model
{
    use BelongsToOrganization;

    /** Fields stored encrypted and never returned to the browser. */
    public const SECRET_FIELDS = ['access_key', 'secret_key'];

    protected $guarded = [];

    protected $casts = ['config' => 'encrypted:array', 'last_tested_at' => 'datetime'];

    /** Config safe to send to the page: secrets are replaced by a set/unset flag. */
    public function maskedConfig(): array
    {
        $out = [];
        foreach (($this->config ?? []) as $k => $v) {
            $out[$k] = in_array($k, self::SECRET_FIELDS, true) ? null : $v;
            if (in_array($k, self::SECRET_FIELDS, true)) {
                $out[$k.'_set'] = filled($v);
            }
        }

        return $out;
    }
}
