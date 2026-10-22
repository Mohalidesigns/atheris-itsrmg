<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member of a contract's project team; the team receives renewal notifications (FIN-06.04). */
class ContractTeamMember extends Model
{
    use FinanceRecord;

    public const ROLES = ['project_lead', 'member', 'approver', 'watcher'];

    protected $table = 'fin_contract_team';

    protected $guarded = [];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
