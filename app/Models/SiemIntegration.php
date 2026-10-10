<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class SiemIntegration extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    public const PROVIDERS = [
        'sentinel' => 'Microsoft Sentinel',
        'splunk_es' => 'Splunk ES',
        'qradar' => 'IBM QRadar',
        'wazuh' => 'Wazuh',
        'defender' => 'Microsoft Defender XDR',
    ];

    protected $guarded = [];

    protected $hidden = ['webhook_secret'];

    protected $casts = [
        'config' => 'array',
        'last_signal_at' => 'datetime',
        'webhook_secret' => 'encrypted',
    ];
}
