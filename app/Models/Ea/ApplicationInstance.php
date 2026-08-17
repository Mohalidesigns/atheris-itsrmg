<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Model;

/**
 * ApplicationInstance — ATH-EAR-002 A6, §8.1.
 *
 * "Application *instances* mapped to entities — one logical application, N
 * deployed instances, each with its own hosting, residency, criticality and
 * regulator set."
 *
 * This is the object that makes multi-jurisdiction real. GTBank Ghana's core
 * banking is the same logical application as GTBank Nigeria's; it is a
 * different instance, in a different country, answerable to a different
 * regulator, and the localisation directive applies to one and not the other.
 */
class ApplicationInstance extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_application_instances';

    protected $guarded = [];

    protected $casts = [
        'contains_nigerian_payment_data' => 'boolean',
        'data_categories' => 'array',
    ];

    public const HOSTING_MODELS = ['on_prem', 'colocation', 'private_cloud', 'public_cloud', 'saas'];

    public function application()
    {
        return $this->belongsTo(EaApplication::class, 'application_id');
    }

    public function legalEntity()
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function hostingSite()
    {
        return $this->belongsTo(Site::class, 'hosting_site_id');
    }

    public function drSite()
    {
        return $this->belongsTo(Site::class, 'dr_site_id');
    }

    /**
     * Does this instance breach the localisation directive?
     *
     * §6.3 A2: the directive "affects **primary and disaster recovery
     * infrastructure**", so a compliant primary with an offshore DR site is
     * still a breach — the trap that makes this worth computing rather than
     * eyeballing.
     */
    public function breachesLocalisation(): bool
    {
        if (! $this->contains_nigerian_payment_data) {
            return false;
        }

        return $this->hosting_country !== 'NG' || ($this->dr_country !== null && $this->dr_country !== 'NG');
    }

    /** Which half is offshore, for the gap register's remediation column. */
    public function localisationBreachReason(): ?string
    {
        if (! $this->breachesLocalisation()) {
            return null;
        }

        $primaryOffshore = $this->hosting_country !== 'NG';
        $drOffshore = $this->dr_country !== null && $this->dr_country !== 'NG';

        return match (true) {
            $primaryOffshore && $drOffshore => 'Primary and DR are both outside Nigeria',
            $primaryOffshore => 'Primary is outside Nigeria ('.($this->hosting_country ?: 'unknown').')',
            default => 'DR is outside Nigeria ('.($this->dr_country ?: 'unknown').')',
        };
    }

    /** Both sites in the same city is a single-event failure, not a DR plan. */
    public function drIsCollocated(): bool
    {
        if (! $this->hostingSite || ! $this->drSite) {
            return false;
        }

        return $this->hostingSite->city !== null
            && $this->hostingSite->city === $this->drSite->city;
    }
}
