<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A read-only adapter onto one of the bank's core banking or switch platforms (ATH-VAL-017). It
 * captures technical metadata only; customer data is embargoed by CoreBankingConnector.
 */
class CoreBankingIntegration extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected $casts = ['config' => 'array', 'last_sync_at' => 'datetime', 'findings_count' => 'integer'];

    public const PRODUCTS = [
        'finacle' => ['label' => 'Infosys Finacle', 'kind' => 'core'],
        'flexcube' => ['label' => 'Oracle Flexcube', 'kind' => 'core'],
        't24' => ['label' => 'Temenos T24 / Transact', 'kind' => 'core'],
        'bankone' => ['label' => 'AppZone BankOne', 'kind' => 'core'],
        'interswitch' => ['label' => 'Interswitch', 'kind' => 'switch'],
        'nibss' => ['label' => 'NIBSS NIP', 'kind' => 'switch'],
    ];

    public const ENVIRONMENTS = ['production', 'dr', 'uat'];

    public const STATUSES = ['connected', 'pending', 'disabled'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(CoreBankingSnapshot::class, 'integration_id');
    }

    public function latestSnapshot(): HasOne
    {
        return $this->hasOne(CoreBankingSnapshot::class, 'integration_id')->latestOfMany('captured_at');
    }
}
