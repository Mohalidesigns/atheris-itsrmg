<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ScheduledReport extends Model
{
    use BelongsToOrganization;

    public const TYPES = [
        'executive' => 'Executive summary', 'risks' => 'Risk analytics', 'compliance' => 'Compliance posture',
        'finance' => 'IT financial position',
        'finance.budget_book' => 'IT finance: Budget book', 'finance.bva' => 'IT finance: Budget vs actual',
        'finance.variance_commentary' => 'IT finance: Variance commentary', 'finance.commitments' => 'IT finance: Commitments',
        'finance.invoice_ageing' => 'IT finance: Invoice ageing', 'finance.unbudgeted' => 'IT finance: Unbudgeted spend',
        'finance.contract_register' => 'IT finance: Contract register', 'finance.vendor_spend' => 'IT finance: Vendor spend',
    ];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    protected $guarded = [];

    protected $casts = ['recipients' => 'array', 'active' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledReportRun::class)->latest('ran_at');
    }

    public static function advance(string $frequency, $from = null)
    {
        $from = $from ? Carbon::parse($from) : now();

        return match ($frequency) {
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            default => $from->copy()->addMonth(),
        };
    }
}
