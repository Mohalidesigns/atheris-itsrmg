<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class ReturnRun extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'submitted_at' => 'datetime'];

    public function template()
    {
        return $this->belongsTo(ReturnTemplate::class, 'template_id');
    }
}
