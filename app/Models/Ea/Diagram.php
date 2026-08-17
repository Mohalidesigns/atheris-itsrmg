<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A diagram canvas.
 *
 * Phase 4 WS 4.1 turned this from a JSON blob into a governed artefact: every
 * save snapshots a {@see DiagramVersion}, the elements that stand for
 * repository records are indexed in {@see DiagramEntity}, and `approved_at`
 * records who signed off — RBCF App. II §1.1(i) asks for an *approved* network
 * topology diagram, not merely a drawing.
 *
 * The tenancy trait and audit log were missing before Phase 4; a diagram is a
 * regulator-facing document, so both belong here.
 */
class Diagram extends Model
{
    use BelongsToTenant, WritesAuditLog;

    protected $table = 'ea_diagrams';

    protected $guarded = [];

    protected $casts = [
        'elements_json' => 'array',
        'edges_json' => 'array',
        'layout_json' => 'array',
        'validation_json' => 'array',
        'is_topology' => 'boolean',
        'approved_at' => 'datetime',
    ];

    public function versions()
    {
        return $this->hasMany(DiagramVersion::class, 'diagram_id')->orderByDesc('version');
    }

    public function entities()
    {
        return $this->hasMany(DiagramEntity::class, 'diagram_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** True when the canvas has no outstanding metamodel errors. */
    public function isValid(): bool
    {
        return ($this->validation_json['valid'] ?? null) !== false;
    }

    public function errorCount(): int
    {
        return count($this->validation_json['errors'] ?? []);
    }
}
