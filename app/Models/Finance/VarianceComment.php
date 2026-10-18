<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a variance note's commentary thread (FIN-04.10). */
class VarianceComment extends Model
{
    use FinanceRecord;

    protected $table = 'fin_variance_comments';

    protected $guarded = [];

    public function note(): BelongsTo
    {
        return $this->belongsTo(VarianceNote::class, 'variance_note_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
