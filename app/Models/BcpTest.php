<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BcpTest extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'plan_id', 'title', 'test_type',
        'status', 'scheduled_date', 'completed_date',
        'conducted_by', 'results', 'findings',
        'recommendations', 'pass_fail',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'completed_date' => 'date',
        ];
    }

    public const TEST_TYPES = ['tabletop', 'walkthrough', 'simulation', 'full'];

    public const STATUSES = ['planned', 'in_progress', 'completed', 'cancelled'];

    public const PASS_FAIL = ['pass', 'partial', 'fail'];

    // Relationships
    public function plan(): BelongsTo
    {
        return $this->belongsTo(BcpPlan::class, 'plan_id');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
