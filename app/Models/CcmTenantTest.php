<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** A CCM test enabled for one bank (ATH-VAL-013): its control, owner, schedule and last result. */
class CcmTenantTest extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['overrides' => 'array', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];

    public const STATUSES = ['enabled', 'paused', 'disabled'];

    public function test()
    {
        return $this->belongsTo(CcmTest::class, 'ccm_test_id');
    }

    public function runs()
    {
        return $this->hasMany(CcmTestRun::class, 'tenant_test_id');
    }

    public function control()
    {
        return $this->belongsTo(Control::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }

    /** Issue::SOURCES reads a code and a title from the source record. */
    public function getCodeAttribute(): ?string
    {
        return $this->test?->code;
    }

    public function getTitleAttribute(): ?string
    {
        return $this->test?->title;
    }
}
