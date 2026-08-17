<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CcmTestRun extends Model
{
    protected $guarded = [];

    protected $casts = ['evidence_ids' => 'array', 'ran_at' => 'datetime'];

    public function tenantTest()
    {
        return $this->belongsTo(CcmTenantTest::class, 'tenant_test_id');
    }
}
