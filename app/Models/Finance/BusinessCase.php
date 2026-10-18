<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One version of an investment's business case: costs, benefits and the calculated NPV, IRR, payback and ROI. */
class BusinessCase extends Model
{
    use FinanceRecord, SoftDeletes;

    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'superseded'];

    protected $table = 'fin_business_cases';

    protected $guarded = [];

    protected $casts = [
        'costs' => 'array', 'benefits' => 'array', 'non_financial_benefits' => 'array', 'irr_multiple' => 'boolean',
        'discount_rate_pct' => 'decimal:3', 'npv' => 'decimal:2', 'irr_pct' => 'decimal:4', 'roi_pct' => 'decimal:2',
        'tco' => 'decimal:2', 'one_off_cost' => 'decimal:2', 'total_benefit' => 'decimal:2',
        'submitted_at' => 'datetime', 'approved_at' => 'datetime',
    ];

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class, 'investment_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function getIsEditableAttribute(): bool
    {
        return $this->status === 'draft';
    }
}
