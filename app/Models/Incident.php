<?php

namespace App\Models;

use App\Events\Core\IncidentDeclared;
use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Incident extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity, SoftDeletes;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'incident_id_code', 'title', 'description',
        'type', 'severity', 'status', 'source', 'detected_at', 'responded_at',
        'contained_at', 'resolved_at', 'closed_at', 'assigned_to',
        'lead_investigator_id', 'affected_systems', 'affected_users_count',
        'is_data_breach', 'root_cause', 'lessons_learned', 'tags',
    ];

    /**
     * Contract I-13 (ATH-EAR-002 §7.3/§7.5) — Incident → EA blast radius.
     *
     * Fires on create only. §7.3 frames the requirement around the CBN
     * 30-minute response window: the responder must find the dependency
     * picture already attached, not have to go and run an analysis in another
     * module. Firing on every save would re-run the traversal on each status
     * change for no benefit.
     */
    protected $dispatchesEvents = [
        'created' => IncidentDeclared::class,
    ];

    protected function casts(): array
    {
        return [
            'affected_systems' => 'array',
            'tags' => 'array',
            'is_data_breach' => 'boolean',
            'detected_at' => 'datetime',
            'responded_at' => 'datetime',
            'contained_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public const STATUSES = [
        'detected', 'triaged', 'investigating', 'containing', 'eradicating',
        'recovering', 'resolved', 'closed', 'post_incident_review',
    ];

    public const TYPES = [
        'malware', 'phishing', 'ransomware', 'data_breach', 'unauthorized_access',
        'denial_of_service', 'insider_threat', 'social_engineering',
        'supply_chain', 'misconfiguration', 'other',
    ];

    // Relationships
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function leadInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_investigator_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(SecurityAlert::class);
    }

    public function breach(): HasOne
    {
        return $this->hasOne(DataBreach::class);
    }

    // Helpers
    public static function generateNextCode(int $organizationId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->value('incident_id_code');

        if ($last && preg_match('/INC-(\d+)/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return 'INC-'.str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
