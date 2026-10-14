<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** One Annex A entry of a bank's Statement of Applicability (ISO/IEC 27001:2022 cl. 6.1.3 d). */
class StatementOfApplicability extends Model
{
    use BelongsToOrganization, LogsActivity;

    protected $table = 'statements_of_applicability';

    public const IMPLEMENTATION = ['implemented', 'partial', 'planned', 'not_started', 'not_applicable'];

    protected $guarded = [];

    protected $casts = ['is_applicable' => 'boolean'];

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(FrameworkRequirement::class, 'requirement_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['is_applicable', 'justification', 'implementation_status', 'control_id'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
