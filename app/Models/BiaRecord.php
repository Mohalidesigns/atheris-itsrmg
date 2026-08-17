<?php

namespace App\Models;

use App\Events\Core\BiaRecordSaved;
use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiaRecord extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'process_name', 'department',
        'description', 'criticality', 'rto_hours', 'rpo_hours',
        'mtpd_hours', 'financial_impact_per_hour',
        'dependencies', 'recovery_strategy', 'owner_id',
    ];

    protected function casts(): array
    {
        return [
            'financial_impact_per_hour' => 'decimal:2',
        ];
    }

    /**
     * Contract I-6 (ATH-EAR-002 §7.3/§7.5) — BIA → EA process.
     *
     * Declared on the model rather than fired from a controller so every write
     * path is covered: the BCP screens, the importer and any future API all
     * flow through here. §7.3 records the gap this closes — "EA process
     * RTO/RPO columns are seeded, not read from `bia_records`".
     */
    protected $dispatchesEvents = [
        'saved' => BiaRecordSaved::class,
    ];

    public const CRITICALITIES = ['critical', 'high', 'medium', 'low'];

    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
