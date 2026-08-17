<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use App\Models\LegalEntity;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * Channel — ATH-EAR-002 A7, §8.1.
 *
 * §6.3: "USSD is a first-class retail channel in Nigeria with commercial and
 * regulatory fragility that has no Western analogue: a **four-year debt dispute
 * between banks and telcos**, telcos suspending service, and the FG
 * **deactivating nine banks' USSD codes**, resolved only in early 2026. Agent
 * banking (Moniepoint, OPay, PalmPay) is a major channel."
 *
 * Modelling the telco as a dependency is the point: it makes "MTN can switch
 * off this channel over a billing dispute" a visible, assessable risk rather
 * than institutional folklore.
 */
class Channel extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_channels';

    protected $guarded = [];

    protected $casts = [
        'third_party_can_suspend' => 'boolean',
        'monthly_value_ngn' => 'decimal:2',
    ];

    public const TYPES = [
        'branch' => 'Branch',
        'atm' => 'ATM',
        'pos' => 'POS',
        'agent_banking' => 'Agent banking',
        'ussd' => 'USSD',
        'mobile_app' => 'Mobile app',
        'internet_banking' => 'Internet banking',
        'api_open_banking' => 'API / open banking',
        'whatsapp' => 'WhatsApp / chat',
    ];

    public function application()
    {
        return $this->belongsTo(EaApplication::class, 'application_id');
    }

    public function legalEntity()
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
    }

    public function telco()
    {
        return $this->belongsTo(Vendor::class, 'telco_vendor_id');
    }

    public function aggregator()
    {
        return $this->belongsTo(Vendor::class, 'aggregator_vendor_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->channel_type] ?? ucfirst((string) $this->channel_type);
    }

    /**
     * A channel a third party can unilaterally switch off, carrying material
     * volume, is the specific Nigerian exposure A7 exists to surface.
     */
    public function isSuspensionExposed(): bool
    {
        return $this->third_party_can_suspend
            && in_array($this->criticality, ['critical', 'high'], true);
    }
}
