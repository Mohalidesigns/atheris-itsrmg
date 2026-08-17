<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncidentResponseProcedure extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'title', 'description', 'category',
        'incident_types', 'steps', 'severity_levels', 'escalation_contacts',
        'is_active', 'version', 'last_reviewed',
    ];

    protected function casts(): array
    {
        return [
            'incident_types' => 'array',
            'steps' => 'array',
            'severity_levels' => 'array',
            'escalation_contacts' => 'array',
            'is_active' => 'boolean',
            'last_reviewed' => 'date',
        ];
    }
}
