<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Outcome extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_outcomes';

    protected $guarded = [];

    protected $casts = ['measured_at' => 'date'];

    public function goal()
    {
        return $this->belongsTo(Goal::class, 'goal_id');
    }
}
