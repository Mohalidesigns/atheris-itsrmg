<?php

namespace App\Models\Returns;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Ea\EntityRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * ReturnCitation — ATH-EAR-002 §8.1:
 *   `(return_id, question_ref, entity_type, entity_id, seal_state_at_capture)`
 *
 * The point of the last column is that it is *at capture*. A return signed in
 * February must show the evidence quality as it stood in February — if the seal
 * later expires or breaks, the signed return does not retrospectively become
 * less trustworthy, and equally it must not silently appear more trustworthy
 * than it was. §10's evidence-integrity gate depends on this being a snapshot,
 * not a live lookup.
 */
class ReturnCitation extends Model
{
    use BelongsToTenant;

    protected $table = 'regulatory_return_citations';

    protected $guarded = [];

    public function return()
    {
        return $this->belongsTo(RegulatoryReturn::class, 'return_id');
    }

    public function label(): string
    {
        return $this->entity_label
            ?: EntityRegistry::describe($this->entity_type, $this->entity_id);
    }

    /** Was this citation backed by approved data when the return was compiled? */
    public function wasApproved(): bool
    {
        return $this->seal_state_at_capture === 'approved';
    }
}
