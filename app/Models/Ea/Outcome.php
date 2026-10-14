<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Outcome extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_outcomes';

    protected $guarded = [];

    protected $casts = ['measured_at' => 'date'];

    public function goal()
    {
        return $this->belongsTo(Goal::class, 'goal_id');
    }
}
