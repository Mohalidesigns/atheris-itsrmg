<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class InfoDomain extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_info_domains';

    protected $guarded = [];

    public function entities()
    {
        return $this->hasMany(LogicalEntity::class, 'domain_id');
    }
}
