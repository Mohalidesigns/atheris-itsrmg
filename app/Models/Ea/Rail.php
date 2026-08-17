<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;

/**
 * Rail — ATH-EAR-002 A7, §8.1: "NIBSS NIP, NQR, BVN, NCS, switches, card
 * schemes."
 *
 * §6.3: "NIBSS NIP is the national rail; 17 licensed switches operate, subject
 * to mandatory 24/7 operation, PCI DSS/EMV compliance and **seven-year
 * transaction logging**."
 *
 * Carrying the licence conditions as attributes rather than prose is what makes
 * the constraint set inspectable — and answerable in a return.
 */
class Rail extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_rails';

    protected $guarded = [];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'requires_24_7' => 'boolean',
        'requires_pci_dss' => 'boolean',
        'requires_emv' => 'boolean',
        'licence_conditions' => 'array',
    ];

    public const TYPES = [
        'rail' => 'Payment rail',
        'switch' => 'Licensed switch',
        'scheme' => 'Card scheme',
        'registry' => 'Registry / directory',
    ];

    public function operatorVendor()
    {
        return $this->belongsTo(Vendor::class, 'operator_vendor_id');
    }

    public function interfaces()
    {
        return $this->hasMany(EaInterface::class, 'rail_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->rail_type] ?? ucfirst((string) $this->rail_type);
    }

    /** The licence conditions, as a list a return can quote. */
    public function conditionSummary(): array
    {
        $conditions = [];

        if ($this->requires_24_7) {
            $conditions[] = '24/7 availability';
        }
        if ($this->requires_pci_dss) {
            $conditions[] = 'PCI DSS compliance';
        }
        if ($this->requires_emv) {
            $conditions[] = 'EMV compliance';
        }
        if ($this->log_retention_years) {
            $conditions[] = "{$this->log_retention_years}-year transaction logging";
        }

        return array_merge($conditions, $this->licence_conditions ?? []);
    }
}
