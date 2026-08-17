<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatMaCompensatingControl extends Model
{
    protected $table = 'csat_ma_compensating_controls';

    protected $fillable = [
        'response_id', 'control_name', 'control_description',
        'effectiveness_level', 'planned_permanent_date', 'issues_finding_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_permanent_date' => 'date',
        ];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(CsatMaResponse::class, 'response_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
