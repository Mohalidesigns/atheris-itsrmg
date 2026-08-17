<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatInstitutionProfile extends Model
{
    protected $table = 'csat_institution_profiles';

    protected $fillable = [
        'assessment_id', 'institution_name', 'cbn_licence_type', 'head_office_address',
        'ciso_name', 'ciso_email', 'ciso_phone', 'ciso_grade',
        'ciso_reporting_line', 'parent_bank_name',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }
}
