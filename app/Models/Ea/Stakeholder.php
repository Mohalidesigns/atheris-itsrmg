<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Stakeholder extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_stakeholders';

    protected $guarded = [];
}
