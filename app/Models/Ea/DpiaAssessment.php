<?php

namespace App\Models\Ea;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class DpiaAssessment extends Model
{
    use HasTenantIdAlias;

    protected $table = 'ea_dpia_assessments';

    protected $guarded = [];

    protected $casts = [
        'answers' => 'array',
        'decided_on' => 'date',
        'risk_score' => 'decimal:2',
    ];

    public function dataFlow()
    {
        return $this->belongsTo(DataFlow::class, 'data_flow_id');
    }

    public function logicalEntity()
    {
        return $this->belongsTo(LogicalEntity::class, 'logical_entity_id');
    }
}
