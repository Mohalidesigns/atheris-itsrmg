<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AucsControl extends Model
{
    protected $table = 'aucs_controls';

    protected $guarded = [];

    public function mappings()
    {
        return $this->hasMany(AucsFrameworkMapping::class);
    }
}
