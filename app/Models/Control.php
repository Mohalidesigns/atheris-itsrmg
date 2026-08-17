<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Control extends Model
{
    use BelongsToOrganization, HasFactory, LogsActivity, SoftDeletes;
    use HasTenantIdAlias;

    protected $fillable = [
        'organization_id', 'parent_id', 'control_code', 'title', 'description',
        'domain', 'category', 'type', 'nature', 'frequency', 'owner_id',
        'status', 'effectiveness', 'is_key_control', 'implementation_notes',
        'last_tested', 'next_review_date', 'tags', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_key_control' => 'boolean',
            'tags' => 'array',
            'last_tested' => 'date',
            'next_review_date' => 'date',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Control::class, 'parent_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function frameworkRequirements(): BelongsToMany
    {
        return $this->belongsToMany(FrameworkRequirement::class, 'control_framework_mappings', 'control_id', 'requirement_id')
            ->withPivot('coverage', 'notes')
            ->withTimestamps();
    }

    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(Risk::class, 'risk_controls')
            ->withPivot('effectiveness', 'notes')
            ->withTimestamps();
    }

    public function evidence(): MorphMany
    {
        return $this->morphMany(Evidence::class, 'evidenceable');
    }

    public static function generateNextCode(int $organizationId, ?string $domain = null): string
    {
        $prefix = $domain ? strtoupper(substr($domain, 0, 3)) : 'CTL';
        $last = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('control_code', 'like', "{$prefix}-%")
            ->orderByDesc('id')
            ->value('control_code');

        if ($last && preg_match("/{$prefix}-(\d+)/", $last, $matches)) {
            $next = (int) $matches[1] + 1;
        } else {
            $next = 1;
        }

        return $prefix.'-'.str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
