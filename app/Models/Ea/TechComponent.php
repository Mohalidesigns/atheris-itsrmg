<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

class TechComponent extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_tech_components_ext';

    protected $guarded = [];

    protected $casts = [
        'eol_date' => 'date',
        'eos_date' => 'date',
        'application_ids' => 'array',
        'obsolescence_flag' => 'boolean',
        'tech_debt_score' => 'decimal:2',
    ];

    public function vulnerabilities()
    {
        return $this->hasMany(TechVulnerability::class, 'component_id');
    }
}
