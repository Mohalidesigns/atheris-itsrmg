<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CsatThreat extends Model
{
    use SoftDeletes;

    protected $table = 'csat_threats';

    protected $fillable = [
        'assessment_id', 'threat_name', 'catalogue_threat_id', 'description',
        'threat_source', 'threat_category', 'likelihood', 'impact',
        'mitigating_controls_desc', 'controls_register_id', 'residual_risk_score',
        'comment', 'created_by',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function catalogueThreat(): BelongsTo
    {
        return $this->belongsTo(CsatThreatCatalogue::class, 'catalogue_threat_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
