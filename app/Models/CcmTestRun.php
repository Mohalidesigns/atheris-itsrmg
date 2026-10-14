<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class CcmTestRun extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['evidence_ids' => 'array', 'findings' => 'array', 'ran_at' => 'datetime', 'measured_value' => 'float'];

    public function tenantTest()
    {
        return $this->belongsTo(CcmTenantTest::class, 'tenant_test_id');
    }

    public function trigger()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
