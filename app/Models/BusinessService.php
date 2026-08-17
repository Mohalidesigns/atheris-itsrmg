<?php

namespace App\Models;

use App\Models\Ea\BusinessService as EaBusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\Process;

/**
 * @deprecated ATH-EAR-002 WS 2.1 / D1 — EA owns business architecture.
 *
 * §7.2: "Reconcile `BusinessService` + `ServiceDependency` into EA; keep the
 * Monitoring read path." This is a projection over `ea_business_services`, kept
 * so the BusinessAssets CRUD screens and the BusinessServices pages keep
 * working while they migrate.
 *
 * New code should use App\Models\Ea\BusinessService.
 */
class BusinessService extends EaBusinessService
{
    protected $guarded = [];

    /**
     * The legacy table named recovery objectives
     * `recovery_time_objective_min` / `recovery_point_objective_min`; the
     * canonical table calls them `rto_minutes` / `rpo_minutes`. These aliases
     * keep old form payloads and Blade/JSX references working.
     */
    public function getRecoveryTimeObjectiveMinAttribute()
    {
        return $this->attributes['rto_minutes'] ?? null;
    }

    public function setRecoveryTimeObjectiveMinAttribute($value): void
    {
        $this->attributes['rto_minutes'] = $value;
    }

    public function getRecoveryPointObjectiveMinAttribute()
    {
        return $this->attributes['rpo_minutes'] ?? null;
    }

    public function setRecoveryPointObjectiveMinAttribute($value): void
    {
        $this->attributes['rpo_minutes'] = $value;
    }

    public function getTable(): string
    {
        return config('ea.canonical_business_architecture', true)
            ? 'ea_business_services'
            : 'business_services';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (config('ea.canonical_business_architecture', true)) {
                $model->code ??= static::nextCode();
            }
        });
    }

    private static function nextCode(): string
    {
        $sequence = EaBusinessService::withoutGlobalScopes()->count() + 1;

        do {
            $code = 'BS-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT);
        } while (EaBusinessService::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    public function capability()
    {
        return $this->belongsTo(Capability::class, 'capability_id');
    }

    public function processes()
    {
        return $this->hasMany(Process::class, 'service_id');
    }

    /** The serialised payload the BusinessServices React pages already expect. */
    protected $appends = ['recovery_time_objective_min', 'recovery_point_objective_min'];
}
