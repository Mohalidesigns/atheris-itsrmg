<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A comment on a budget or one of its lines; return/reject decisions leave one per line (plan C38). */
class BudgetComment extends Model
{
    use FinanceRecord;

    public const KINDS = ['comment', 'return', 'reject', 'approve'];

    protected $table = 'fin_budget_comments';

    protected $guarded = [];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
