<?php

namespace App\Models;

use App\Models\Ea\BusinessService as EaBusinessService;
use App\Models\Ea\Process;

/**
 * @deprecated ATH-EAR-002 WS 2.1 / D1 — EA owns business architecture.
 *
 * §7.2: "**Retire `BusinessProcess`**; BIA writes RTO/RPO *into* the EA
 * process." Contract I-6 depends on there being exactly one process object to
 * write into, which is why this had to be resolved before the contracts land.
 *
 * A projection over `ea_processes`. New code should use App\Models\Ea\Process.
 */
class BusinessProcess extends Process
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('ea.canonical_business_architecture', true)
            ? 'ea_processes'
            : 'business_processes';
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (! config('ea.canonical_business_architecture', true)) {
                return;
            }

            $model->code ??= static::nextCode();
            $model->level ??= 2;
            $model->criticality ??= 'medium';
        });
    }

    private static function nextCode(): string
    {
        $sequence = Process::withoutGlobalScopes()->count() + 1;

        do {
            $code = 'BP-'.str_pad((string) $sequence++, 3, '0', STR_PAD_LEFT);
        } while (Process::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }

    public function service()
    {
        return $this->belongsTo(EaBusinessService::class, 'service_id');
    }
}
