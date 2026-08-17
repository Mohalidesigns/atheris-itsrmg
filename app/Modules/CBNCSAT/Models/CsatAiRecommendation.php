<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatAiRecommendation extends Model
{
    protected $table = 'csat_ai_recommendations';

    public $timestamps = false;

    protected $fillable = [
        'assessment_id', 'recommendation_type', 'scope_reference',
        'recommendation_text', 'cbn_framework_ref', 'effort_estimate',
        'priority_rank', 'user_rating', 'model_used', 'prompt_version',
        'generated_at', 'is_dismissed',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'is_dismissed' => 'boolean',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }
}
