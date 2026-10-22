<?php

namespace App\Models\Finance;

use App\Models\Evidence;
use App\Models\Finance\Concerns\FinanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document held in the evidence vault against a contract (FIN-06.08). */
class ContractDocument extends Model
{
    use FinanceRecord;

    public const KINDS = ['contract', 'sla', 'dpa', 'order_form', 'renewal_quote', 'termination_letter', 'other'];

    protected $table = 'fin_contract_documents';

    protected $guarded = [];

    protected $casts = ['signed_on' => 'date', 'expires_on' => 'date'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(Evidence::class, 'evidence_id');
    }
}
