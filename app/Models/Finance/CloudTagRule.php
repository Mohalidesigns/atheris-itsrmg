<?php

namespace App\Models\Finance;

use App\Models\Ea\BusinessService;
use App\Models\Ea\EaApplication;
use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maps one tag value onto a cost centre, EA application or business service (plan P6-3). A null account means the whole bank. */
class CloudTagRule extends Model
{
    use FinanceRecord;

    protected $table = 'fin_cloud_tag_rules';

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean', 'last_hit_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CloudAccount::class, 'cloud_account_id');
    }

    /** The code and name of the mapped record, or null when it is gone. */
    public function targetLabel(): ?string
    {
        $m = match ($this->dimension) {
            'cost_centre' => CostCentre::withoutGlobalScopes()->where('organization_id', $this->organization_id)->find($this->target_id),
            'ea_application' => EaApplication::withoutGlobalScopes()->find($this->target_id),
            'business_service' => BusinessService::withoutGlobalScopes()->find($this->target_id),
            default => null,
        };

        return $m ? trim(($m->code ?? '').' '.($m->name ?? '')) : null;
    }
}
