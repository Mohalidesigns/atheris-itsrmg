<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Standard extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_standards';

    protected $guarded = [];

    public function exceptions()
    {
        return $this->hasMany(Exception::class, 'standard_id');
    }
}
