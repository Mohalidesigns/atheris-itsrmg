<?php

namespace App\Models;

use App\Models\Traits\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traceability from a circular to the bank's own records (circular → obligation / control / policy /
 * risk), so an examiner can follow the reasoning behind an impact assessment.
 */
class CircularImpact extends Model
{
    use BelongsToOrganization;

    /** impacted_type key => [model, code column, label column, show route]. */
    public const TYPES = [
        'obligation' => [Obligation::class, 'reference_code', 'title', 'obligations.index'],
        'control' => [Control::class, 'control_code', 'title', 'controls.show'],
        'policy' => [Policy::class, 'policy_code', 'title', 'policies.show'],
        'risk' => [Risk::class, 'risk_id_code', 'title', 'risks.show'],
    ];

    protected $guarded = [];

    protected $casts = ['impact_score' => 'float'];

    public function circular(): BelongsTo
    {
        return $this->belongsTo(RegulatoryCircular::class, 'circular_id');
    }

    /** The linked record, resolved through its own (tenant-scoped) model. */
    public function target(): ?Model
    {
        $model = self::TYPES[$this->impacted_type][0] ?? null;

        return $model ? $model::find($this->impacted_id) : null;
    }
}
