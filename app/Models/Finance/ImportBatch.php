<?php

namespace App\Models\Finance;

use App\Models\Finance\Concerns\FinanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use FinanceRecord;

    public const TYPES = ['actuals', 'expenses', 'invoices', 'cloud'];

    public const STATUSES = ['completed', 'completed_with_errors', 'failed'];

    protected $table = 'fin_import_batches';

    protected $guarded = [];

    protected $casts = ['report' => 'array', 'completed_at' => 'datetime'];

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_id');
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(ImportMapping::class, 'mapping_id');
    }

    public function actuals(): HasMany
    {
        return $this->hasMany(Actual::class, 'import_batch_id');
    }
}
