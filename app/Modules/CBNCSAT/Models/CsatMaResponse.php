<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CsatMaResponse extends Model
{
    protected $table = 'csat_ma_responses';

    protected $fillable = [
        'assessment_id', 'statement_id', 'response', 'has_compensating_control',
        'comment', 'responded_by', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'has_compensating_control' => 'boolean',
            'responded_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CsatAssessment::class, 'assessment_id');
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CsatMaStatement::class, 'statement_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function compensatingControl(): HasOne
    {
        return $this->hasOne(CsatMaCompensatingControl::class, 'response_id');
    }
}
