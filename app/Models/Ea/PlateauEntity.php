<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WritesAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Membership of one entity in one plateau, with a disposition.
 *
 * ATH-EAR-002 §5.1 killed the old read-only Scenarios page because it "cannot
 * create a scenario". This row is what authoring a scenario writes.
 */
class PlateauEntity extends Model
{
    use BelongsToTenant, WritesAuditLog;

    /** Dispositions that mean "the entity exists in this plateau". */
    public const PRESENT = ['retain', 'introduce', 'modify', 'replace'];

    /** Dispositions that mean "the entity is gone by this plateau". */
    public const ABSENT = ['retire'];

    public const DISPOSITIONS = ['retain', 'introduce', 'modify', 'replace', 'retire'];

    protected $table = 'ea_plateau_entities';

    protected $guarded = [];

    protected $casts = [
        'target_annual_cost' => 'decimal:2',
        'one_off_cost' => 'decimal:2',
    ];

    public function plateau()
    {
        return $this->belongsTo(Plateau::class, 'plateau_id');
    }

    public function entity()
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }

    public function isPresent(): bool
    {
        return in_array($this->disposition, self::PRESENT, true);
    }
}
