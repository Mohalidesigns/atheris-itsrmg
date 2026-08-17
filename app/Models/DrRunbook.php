<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class DrRunbook extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['steps' => 'array'];

    public function exercises()
    {
        return $this->hasMany(DrExercise::class, 'runbook_id');
    }
}
