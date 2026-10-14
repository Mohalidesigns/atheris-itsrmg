<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A recovery runbook; strictly per bank (the table had no scope). */
class DrRunbook extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['steps' => 'array'];

    public function exercises()
    {
        return $this->hasMany(DrExercise::class, 'runbook_id');
    }
}
