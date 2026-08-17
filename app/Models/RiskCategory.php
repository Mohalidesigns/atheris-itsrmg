<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskCategory extends Model
{
    use BelongsToOrganization, HasFactory;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'parent_id', 'name', 'slug',
        'description', 'color', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(RiskCategory::class, 'parent_id');
    }

    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'category_id');
    }
}
