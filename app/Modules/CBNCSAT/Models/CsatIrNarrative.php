<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatIrNarrative extends Model
{
    protected $table = 'csat_ir_narratives';

    protected $fillable = [
        'assessment_id', 'category_code', 'narrative_key', 'narrative_label', 'narrative_value',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }
}
