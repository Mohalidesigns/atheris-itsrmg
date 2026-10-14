<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_drivers';

    protected $guarded = [];
}
