<?php

namespace App\Models\Change;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** One step of a bank's change approval policy (ATH-CHG-001 §2.2). */
class ChangeApprovalStage extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = [
        'applies_to_types' => 'array',
        'sequence' => 'integer',
        'quorum' => 'integer',
        'sla_hours' => 'integer',
        'is_active' => 'boolean',
    ];

    public const KEYS = ['technical_review', 'security_review', 'business_approval', 'cab', 'ecab', 'change_manager', 'custom'];

    public const APPROVER_TYPES = ['role', 'job_title', 'user', 'owner', 'board'];

    public function board()
    {
        return $this->belongsTo(ChangeCabBoard::class, 'approver_ref');
    }
}
