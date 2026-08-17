<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Patch extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'patch_id_code', 'name', 'description',
        'vendor', 'version', 'release_date', 'severity', 'status',
        'affected_systems', 'deployed_at', 'deployed_by',
    ];

    protected function casts(): array
    {
        return [
            'affected_systems' => 'array',
            'release_date' => 'date',
            'deployed_at' => 'datetime',
        ];
    }

    // Relationships
    public function deployer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deployed_by');
    }
}
