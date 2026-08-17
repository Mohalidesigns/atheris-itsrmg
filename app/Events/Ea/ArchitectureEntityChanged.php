<?php

namespace App\Events\Ea;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ArchitectureEntityChanged — §7.5's event design.
 *
 *   Ea\Events\ArchitectureEntityChanged → Ea\Listeners\BreakQualitySeal   (B3)
 *
 * §7.5 requires a small set of domain events rather than direct service calls
 * between modules, so each contract is testable in isolation and the seams stay
 * clean. This is the first of them; the Phase 2 contracts (I-1 obsolescence →
 * risk, I-2 CVE → vulnerability, and the rest) attach to the same bus.
 *
 * Every listener must be idempotent.
 */
class ArchitectureEntityChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $entityType,
        public int|string $entityId,
        public array $changedAttributes = [],
        public ?int $actorId = null,
    ) {
    }

    public static function fromModel(Model $model, array $changed = []): self
    {
        return new self(
            $model::class,
            $model->getKey(),
            $changed ?: array_keys($model->getChanges()),
            optional(auth()->user())->id,
        );
    }
}
