<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class LogicalEntity extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_logical_entities';

    protected $guarded = [];

    protected $casts = ['attributes' => 'array', 'pii_flag' => 'boolean'];

    public function domain()
    {
        return $this->belongsTo(InfoDomain::class, 'domain_id');
    }
}
