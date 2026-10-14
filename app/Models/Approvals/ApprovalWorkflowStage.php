<?php

namespace App\Models\Approvals;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflowStage extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'condition_values' => 'array'];

    public function workflow()
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }
}
