<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * An immutable snapshot of a diagram canvas. Written on every save so §10's
 * "rollback for accidental bulk changes" applies to diagrams too.
 */
class DiagramVersion extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_diagram_versions';

    protected $guarded = [];

    protected $casts = [
        'elements_json' => 'array',
        'edges_json' => 'array',
        'layout_json' => 'array',
    ];

    public function diagram()
    {
        return $this->belongsTo(Diagram::class, 'diagram_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
