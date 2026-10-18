<?php

namespace App\Models\Finance;

use App\Models\Change\ChangeRequest;
use App\Models\Ea\BusinessService;
use App\Models\Ea\Capability;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** An investment's link to an EA application or capability, a business service, a risk or a change request (FIN-05.08). */
class InvestmentLink extends Model
{
    use FinanceRecord;

    public const TYPES = [
        'ea_application' => EaApplication::class, 'capability' => Capability::class, 'business_service' => BusinessService::class,
        'risk' => Risk::class, 'change' => ChangeRequest::class,
    ];

    protected $table = 'fin_investment_links';

    protected $guarded = [];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
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
