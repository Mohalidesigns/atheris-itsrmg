<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Goal extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_goals';

    protected $guarded = [];

    public function outcomes()
    {
        return $this->hasMany(Outcome::class, 'goal_id');
    }
}
