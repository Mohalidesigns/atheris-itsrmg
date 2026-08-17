<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasQualitySeal;
use App\Models\Concerns\WritesAuditLog;
use App\Models\LegalEntity;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

class EaApplication extends Model
{
    use BelongsToTenant, HasQualitySeal, WritesAuditLog;

    protected $table = 'ea_applications_ext';

    protected $guarded = [];

    protected $casts = [
        'capability_ids' => 'array',
        'annual_cost_ngn' => 'decimal:2',
        'tco_annual_ngn' => 'decimal:2',
    ];

    public function plateau()
    {
        return $this->belongsTo(Plateau::class, 'plateau_id');
    }

    /* ---- Phase 3 (ATH-EAR-002 §6.3 A2/A5/A6) — residency and siting ---- */

    public function hostingSite()
    {
        return $this->belongsTo(Site::class, 'hosting_site_id');
    }

    public function drSite()
    {
        return $this->belongsTo(Site::class, 'dr_site_id');
    }

    public function legalEntity()
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function instances()
    {
        return $this->hasMany(ApplicationInstance::class, 'application_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function zoneAssignment()
    {
        return $this->hasOne(ZoneAssignment::class, 'application_id');
    }

    public function vulnerabilities()
    {
        return $this->hasManyThrough(TechVulnerability::class, TechComponent::class, 'id', 'component_id');
    }
}
