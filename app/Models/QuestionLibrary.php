<?php

namespace App\Models;

use App\Models\Concerns\HasTenantIdAlias;
use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Reusable assessment question (risk, compliance, vendor or ISMS questionnaires).
 */
class QuestionLibrary extends Model
{
    use BelongsToOrganization, HasTenantIdAlias, LogsActivity;

    protected $fillable = [
        'organization_id', 'title', 'question_text', 'category', 'response_type',
        'response_options', 'weight', 'module', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'response_options' => 'array',
            'is_active' => 'boolean',
            'weight' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public const RESPONSE_TYPES = ['scale', 'yes_no', 'multiple_choice', 'text'];

    public const MODULES = ['risk', 'compliance', 'vendor', 'isms'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
