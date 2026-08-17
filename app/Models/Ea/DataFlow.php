<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class DataFlow extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_data_flows';

    protected $guarded = [];

    protected $casts = ['cross_border' => 'boolean'];

    public function source()
    {
        return $this->belongsTo(LogicalEntity::class, 'source_entity_id');
    }

    public function target()
    {
        return $this->belongsTo(LogicalEntity::class, 'target_entity_id');
    }
}
