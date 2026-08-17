<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Kri extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    public function readings()
    {
        return $this->hasMany(KriReading::class);
    }

    public function breaches()
    {
        return $this->hasMany(KriBreach::class);
    }
}
