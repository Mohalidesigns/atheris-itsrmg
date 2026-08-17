<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class Pattern extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_patterns';

    protected $guarded = [];

    public function solutions()
    {
        return $this->hasMany(Solution::class, 'pattern_id');
    }
}
