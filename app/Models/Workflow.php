<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['definition_json' => 'array'];

    public function instances()
    {
        return $this->hasMany(WorkflowInstance::class);
    }
}
