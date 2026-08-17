<?php

namespace App\Modules\CBNCSAT\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CsatApprovalStage extends Model
{
    protected $table = 'csat_approval_stages';

    protected $fillable = [
        'organization_id', 'stage_number', 'stage_name', 'role_required', 'is_mandatory',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
