<?php

namespace App\Models\Approvals;

use App\Models\Traits\BelongsToOrganization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A bank's configuration of one approval process: who initiates it and its stages (ATH-VAL-016). */
class ApprovalWorkflow extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'initiator_roles' => 'array'];

    public function stages()
    {
        return $this->hasMany(ApprovalWorkflowStage::class, 'workflow_id')->orderBy('sequence')->orderBy('id');
    }

    public function editor()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
