<?php

namespace App\Models;

use App\Models\Ea\BusinessService as EaBusinessService;
use App\Models\Ea\Capability;

/**
 * @deprecated ATH-EAR-002 WS 2.1 / D1 — EA owns business architecture.
 *
 * §2.5 (RC-5): "The platform ships two business-architecture models … Two
 * capability trees in one product, shown to a bank, is the kind of finding a
 * Big-4 assessor writes up." §7.2 resolves it: `ea_capabilities` is canonical
 * and `BusinessCapability` is retired.
 *
 * This class survives only so existing callers — PlatformController,
 * BusinessAssetController, the BusinessServices pages and the platform seeder —
 * keep working while they are migrated. It is a projection over the canonical
 * table, not a second model: reads and writes both land in `ea_capabilities`.
 *
 * New code should use App\Models\Ea\Capability directly.
 *
 * Set EA_CANONICAL_BUSINESS_ARCHITECTURE=false to fall back to the original
 * `business_capabilities` table (risk R4's escape hatch — the WS 2.1 migration
 * deliberately did not drop it).
 */
class BusinessCapability extends Capability
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ea.canonical_business_architecture', true)
            ? 'ea_capabilities'
            : 'business_capabilities';
    }

    /**
     * The legacy surface required `name` only. `ea_capabilities.code`,
     * `level`, `criticality`, `maturity` and `source` are NOT NULL, so a
     * legacy-style create with just a name would fail without these defaults.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! config('ea.canonical_business_architecture', true)) {
                return;
            }

            $model->code ??= static::nextCode();
            $model->level ??= $model->parent_id ? 2 : 1;
            $model->criticality ??= 'medium';
            $model->maturity ??= 1;
            $model->source ??= 'custom';
            $model->version_no ??= 1;
        });
    }

    private static function nextCode(): string
    {
        $sequence = Capability::withoutGlobalScopes()->count() + 1;

        do {
            $code = 'BC-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT);
        } while (Capability::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    /** Legacy relationship name, re-pointed at the canonical service table. */
    public function services()
    {
        return $this->hasMany(EaBusinessService::class, 'capability_id');
    }
}
