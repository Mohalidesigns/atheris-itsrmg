<?php

namespace App\Models\Finance;

use App\Models\Asset;
use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A contract's link to an asset, EA application, business service or risk (investments join in P5, plan C53). */
class ContractLink extends Model
{
    use FinanceRecord;

    public const TYPES = ['asset' => Asset::class, 'ea_application' => EaApplication::class, 'business_service' => BusinessService::class, 'risk' => Risk::class];

    protected $table = 'fin_contract_links';

    protected $guarded = [];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeKeyAttribute(): ?string
    {
        return array_search($this->linkable_type, self::TYPES, true) ?: null;
    }
}
