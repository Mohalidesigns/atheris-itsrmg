<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatMaNarrative extends Model
{
    protected $table = 'csat_ma_narratives';

    protected $fillable = [
        'assessment_id', 'domain_code', 'question_number', 'question_text',
        'response_text', 'ai_draft_text', 'is_mandatory', 'min_characters', 'responded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }
}
