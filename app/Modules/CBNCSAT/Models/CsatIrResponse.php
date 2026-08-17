<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatIrResponse extends Model
{
    protected $table = 'csat_ir_responses';

    protected $fillable = [
        'assessment_id', 'question_id', 'selected_level', 'comment',
        'completed_by', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_level' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CsatIrQuestion::class, 'question_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
