<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class CustomField extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['options' => 'array', 'required' => 'boolean'];
}
