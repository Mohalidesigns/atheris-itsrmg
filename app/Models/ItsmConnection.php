<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank's connector to its IT service-management tool. Issues are pushed out as tickets and the
 * tool mirrors ticket status back through an HMAC-signed webhook. "sandbox" mode records tickets
 * locally without calling the tool, for demos and for banks still onboarding.
 */
class ItsmConnection extends Model
{
    use BelongsToOrganization, HasTenantIdAlias;

    public const SYSTEMS = [
        'jira' => 'Atlassian Jira',
        'servicenow' => 'ServiceNow ITSM',
        'freshservice' => 'Freshservice',
    ];

    public const MODES = ['sandbox', 'live'];

    public const STATUSES = ['active', 'error', 'disabled'];

    protected $guarded = [];

    protected $hidden = ['api_token', 'webhook_secret'];

    protected $casts = [
        'api_token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'last_test_ok' => 'boolean',
        'last_tested_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function label(): string
    {
        return self::SYSTEMS[$this->system] ?? $this->system;
    }
}
