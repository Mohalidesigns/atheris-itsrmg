<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ControlFramework extends Model
{
    protected $fillable = [
        'name', 'slug', 'short_name', 'description', 'version',
        'issuing_body', 'category', 'jurisdiction', 'is_system',
        'is_active', 'logo_path', 'url', 'effective_date', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'effective_date' => 'date',
        ];
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(FrameworkRequirement::class, 'framework_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(ComplianceAssessment::class, 'framework_id');
    }

    public function topLevelRequirements(): HasMany
    {
        return $this->hasMany(FrameworkRequirement::class, 'framework_id')
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }
}
