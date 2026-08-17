<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class DocIntelligenceJob extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['structured_output' => 'array'];
}
