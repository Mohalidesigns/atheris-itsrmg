<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A regulator return template. Platform reference data shared by every tenant. */
class ReturnTemplate extends Model
{
    protected $guarded = [];

    protected $casts = ['schema' => 'array', 'effective_from' => 'date:Y-m-d', 'navy_variant' => 'boolean'];

    public function runs(): HasMany
    {
        return $this->hasMany(ReturnRun::class, 'template_id');
    }
}
