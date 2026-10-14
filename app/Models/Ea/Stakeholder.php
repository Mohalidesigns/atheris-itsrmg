<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Stakeholder extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_stakeholders';

    protected $guarded = [];
}
