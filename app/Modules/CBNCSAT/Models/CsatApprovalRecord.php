<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatApprovalRecord extends Model
{
    protected $table = 'csat_approval_records';

    public $timestamps = false;

    protected $fillable = [
        'assessment_id', 'stage_number', 'action', 'approver_id',
        'approver_name', 'approver_role', 'digital_signature_token',
        'comments', 'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'actioned_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
