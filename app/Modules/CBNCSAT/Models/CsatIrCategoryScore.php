<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatIrCategoryScore extends Model
{
    protected $table = 'csat_ir_category_scores';

    public $timestamps = false;

    protected $fillable = [
        'assessment_id', 'category_code', 'total_score', 'question_count',
        'answered_count', 'average_score', 'risk_level', 'completion_pct', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_score' => 'decimal:2',
            'average_score' => 'decimal:3',
            'completion_pct' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }
}
