<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatMaScore extends Model
{
    protected $table = 'csat_ma_scores';

    public $timestamps = false;

    protected $fillable = [
        'assessment_id', 'score_type', 'scope_code', 'scope_name',
        'baseline_score', 'evolving_score', 'intermediate_score',
        'advanced_score', 'innovative_score',
        'achieved_maturity_level', 'target_maturity_level',
        'completion_pct', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'baseline_score' => 'decimal:4',
            'evolving_score' => 'decimal:4',
            'intermediate_score' => 'decimal:4',
            'advanced_score' => 'decimal:4',
            'innovative_score' => 'decimal:4',
            'completion_pct' => 'decimal:2',
            'calculated_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }
}
