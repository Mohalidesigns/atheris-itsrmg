<?php

namespace App\Models\Ea;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Ea\EntityRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * SealPolicy — the per-entity-type configuration behind the quality seal.
 *
 * LeanIX lets an administrator set an automatic renewal interval of 30 or 90
 * days; §5.4 B3 asks for 30/60/90. The interval breaks the seal on schedule
 * whether or not the record changed, which is the whole point: a repository
 * goes stale silently, and only a scheduled challenge surfaces it.
 *
 * `mandatory_attributes` does double duty — it gates approval and it weights
 * the completeness score.
 */
class SealPolicy extends Model
{
    use BelongsToTenant;

    protected $table = 'ea_seal_policies';

    protected $guarded = [];

    protected $casts = [
        'mandatory_attributes' => 'array',
        'optional_attributes' => 'array',
        'auto_expiry_enabled' => 'boolean',
        'break_on_edit' => 'boolean',
    ];

    public const INTERVALS = [30, 60, 90];

    /**
     * The effective policy for a type: the stored row if the tenant has
     * configured one, otherwise a sensible default derived from the registry.
     * Returning an unsaved model keeps callers from having to null-check.
     */
    public static function effectiveFor(string $entityType): self
    {
        $stored = static::where('entity_type', $entityType)->first();
        if ($stored) {
            return $stored;
        }

        $definition = EntityRegistry::definition($entityType) ?? [];

        return new self([
            'entity_type' => $entityType,
            'renewal_interval_days' => 90,
            'auto_expiry_enabled' => true,
            'break_on_edit' => true,
            'mandatory_attributes' => array_keys($definition['mandatory'] ?? []),
            'optional_attributes' => array_keys($definition['optional'] ?? []),
        ]);
    }

    public function mandatory(): array
    {
        if (! empty($this->mandatory_attributes)) {
            return $this->mandatory_attributes;
        }

        return array_keys(EntityRegistry::definition($this->entity_type)['mandatory'] ?? []);
    }

    public function optional(): array
    {
        if (! empty($this->optional_attributes)) {
            return $this->optional_attributes;
        }

        return array_keys(EntityRegistry::definition($this->entity_type)['optional'] ?? []);
    }
}
