<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatSectionAssignment extends Model
{
    protected $table = 'csat_section_assignments';

    public $timestamps = false;

    protected $fillable = [
        'assessment_id', 'scope_type', 'scope_code', 'assigned_to',
        'due_date', 'status', 'assigned_by', 'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'assigned_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
