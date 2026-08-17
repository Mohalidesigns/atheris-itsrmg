<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FrameworkRequirement extends Model
{
    protected $fillable = [
        'framework_id', 'parent_id', 'requirement_code', 'title',
        'description', 'section', 'level', 'is_mandatory', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean'];
    }

    public function framework(): BelongsTo
    {
        return $this->belongsTo(ControlFramework::class, 'framework_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FrameworkRequirement::class, 'parent_id')
            ->orderBy('sort_order');
    }

    public function controls(): BelongsToMany
    {
        return $this->belongsToMany(Control::class, 'control_framework_mappings', 'requirement_id', 'control_id')
            ->withPivot('coverage', 'notes')
            ->withTimestamps();
    }
}
