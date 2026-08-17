<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use App\Models\Ea\ApplicationInstance;
use App\Models\Ea\EaApplication;
use Illuminate\Database\Eloquent\Model;

/**
 * LegalEntity — ATH-EAR-002 A6, §8.1.
 *
 * Platform-level, not EA-level: §7.2 lists it as "New — Platform … every module
 * becomes entity-aware". A Nigerian banking group's UK subsidiary answers to
 * the PRA and FCA while the group answers to CBN, and a risk, a control, an
 * incident and a return all need to know which entity they belong to.
 *
 * §6.3 A6: "A global tool models 'regions'. It does not model *'this
 * subsidiary's core banking instance must simultaneously satisfy CBN
 * localisation, the Ghanaian directive and UK operational resilience.'* That is
 * genuinely hard, it is table stakes for GTCO/Access/UBA, and it is worth
 * building properly."
 */
class LegalEntity extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'legal_entities';

    protected $guarded = [];

    protected $casts = [
        'regulators' => 'array',
        'is_group_parent' => 'boolean',
        'capital_base_ngn' => 'decimal:2',
    ];

    /**
     * CBN licence classes with their recapitalisation thresholds.
     *
     * §6.1 Finding 3: recapitalisation concluded 31 March 2026, raising
     * ₦4.65trn; 33 banks met the thresholds (₦500bn international / ₦200bn
     * regional / ₦50bn national).
     */
    public const LICENCE_CLASSES = [
        'international' => ['label' => 'International commercial bank', 'threshold_ngn' => 500_000_000_000],
        'national' => ['label' => 'National commercial bank', 'threshold_ngn' => 200_000_000_000],
        'regional' => ['label' => 'Regional commercial bank', 'threshold_ngn' => 50_000_000_000],
        'merchant' => ['label' => 'Merchant bank', 'threshold_ngn' => 50_000_000_000],
        'non_interest' => ['label' => 'Non-interest bank', 'threshold_ngn' => 20_000_000_000],
        'psb' => ['label' => 'Payment service bank', 'threshold_ngn' => 5_000_000_000],
        'psp' => ['label' => 'Payment service provider', 'threshold_ngn' => null],
        'mfb' => ['label' => 'Microfinance bank', 'threshold_ngn' => null],
        'holding' => ['label' => 'Holding company', 'threshold_ngn' => null],
        'foreign_subsidiary' => ['label' => 'Foreign subsidiary', 'threshold_ngn' => null],
    ];

    /**
     * §6.1: the ITSB sets target architecture maturity by institution
     * category — Level 3 "Defined" for Category One (international commercial
     * banks; established commercial and merchant banks), Level 2 "Repeatable"
     * for Category Two (banks operating ≤18 months; payment system providers).
     */
    public function itsbCategory(): ?string
    {
        if ($this->jurisdiction !== 'NG') {
            return null;
        }

        return match ($this->licence_class) {
            'international', 'national', 'regional', 'merchant' => 'one',
            'psp', 'psb', 'mfb', 'non_interest' => 'two',
            default => null,
        };
    }

    public function itsbTargetLevel(): ?int
    {
        return match ($this->itsbCategory()) {
            'one' => 3,
            'two' => 2,
            default => null,
        };
    }

    public function meetsCapitalThreshold(): ?bool
    {
        $threshold = self::LICENCE_CLASSES[$this->licence_class]['threshold_ngn'] ?? null;

        if ($threshold === null || $this->capital_base_ngn === null) {
            return null;
        }

        return (float) $this->capital_base_ngn >= $threshold;
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function applications()
    {
        return $this->hasMany(EaApplication::class, 'legal_entity_id');
    }

    public function instances()
    {
        return $this->hasMany(ApplicationInstance::class, 'legal_entity_id');
    }

    /** Every regulator this entity answers to, including inherited group ones. */
    public function effectiveRegulators(): array
    {
        $own = $this->regulators ?? [];
        $parent = $this->parent?->regulators ?? [];

        return array_values(array_unique(array_merge($own, $parent)));
    }
}
