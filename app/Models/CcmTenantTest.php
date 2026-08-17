<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use Illuminate\Database\Eloquent\Model;

class CcmTenantTest extends Model
{
    use HasTenantIdAlias;

    protected $guarded = [];

    protected $casts = ['overrides' => 'array', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime'];

    public function test()
    {
        return $this->belongsTo(CcmTest::class, 'ccm_test_id');
    }

    public function runs()
    {
        return $this->hasMany(CcmTestRun::class, 'tenant_test_id');
    }
}
