<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Which repository entity a canvas element stands for.
 *
 * A diagram whose boxes are only labels is a picture; a diagram whose boxes
 * resolve to records is architecture. RBCF App. II §1.1(i) wants the second
 * kind — "approved network topology diagram" the bank can tie to its catalogue
 * of connections.
 */
class DiagramEntity extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_diagram_entities';

    protected $guarded = [];

    public function diagram()
    {
        return $this->belongsTo(Diagram::class, 'diagram_id');
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }
}
