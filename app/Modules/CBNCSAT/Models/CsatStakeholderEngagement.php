<?php

namespace App\Modules\CBNCSAT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatStakeholderEngagement extends Model
{
    protected $table = 'csat_stakeholder_engagement';

    protected $fillable = [
        'assessment_id', 'role_key', 'role_label', 'engagement_status',
        'comment', 'name_of_person',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public const ROLES = [
        'md_ceo' => 'MD/CEO',
        'coo' => 'COO',
        'cio' => 'CIO',
        'cto' => 'CTO',
        'head_channels' => 'Head of Electronic Channels',
        'head_hr' => 'Head of HR',
        'head_treasury' => 'Head of Treasury',
        'head_trade' => 'Head of Trade Services',
        'head_compliance' => 'Head of Compliance',
        'ciso' => 'CISO',
    ];
}
