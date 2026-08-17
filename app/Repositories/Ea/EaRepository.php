<?php

namespace App\Repositories\Ea;

use App\Models\Ea\Relationship;
use App\Services\Ea\AuditLogger;
use App\Services\Ea\RelationshipValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * EaRepository — generic create/update/delete wrapper used by every EA service.
 *
 * Responsibilities:
 *   - Wrap writes in a DB transaction
 *   - Strip non-permitted fields (FormRequest already validated them, but
 *     belt-and-braces)
 *   - Hand off to {@see AuditLogger} (also auto-handled by WritesAuditLog
 *     trait, but a redundant call is harmless and gives the service layer a
 *     hook for additional context)
 *
 * The repository deliberately does not extend an interface: it is meant to be
 * cheap to call from services and not over-engineered.
 */
class EaRepository
{
    public function create(string $modelClass, array $attrs): Model
    {
        return DB::transaction(function () use ($modelClass, $attrs) {
            /** @var Model $model */
            $model = new $modelClass();
            $model->fill($attrs);
            $model->save();
            AuditLogger::log('create', $model, null, $model->getAttributes(), ['source' => 'repository']);
            return $model;
        });
    }

    public function update(Model $model, array $attrs): Model
    {
        return DB::transaction(function () use ($model, $attrs) {
            $before = $model->getOriginal();
            $model->fill($attrs);
            $model->save();
            AuditLogger::log('update', $model, $before, $model->getChanges(), ['source' => 'repository']);
            return $model;
        });
    }

    public function delete(Model $model): bool
    {
        return DB::transaction(function () use ($model) {
            AuditLogger::log('delete', $model, $model->getOriginal(), null, ['source' => 'repository']);
            return (bool) $model->delete();
        });
    }

    /**
     * Add an ArchiMate-validated relationship between two entities.
     *
     * @throws \InvalidArgumentException if the (sourceType, relation, targetType) is not permitted.
     */
    public function relate(Model $source, string $relation, Model $target, array $attrs = []): Relationship
    {
        if (! RelationshipValidator::isPermitted($source::class, $relation, $target::class)) {
            $reason = RelationshipValidator::reasonIfNotPermitted($source::class, $relation, $target::class);
            throw new \InvalidArgumentException($reason);
        }
        $rel = Relationship::create([
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'target_type' => $target::class,
            'target_id' => $target->getKey(),
            'relation_type' => strtolower($relation),
            'attrs' => $attrs,
        ]);
        AuditLogger::logBare('relate', Relationship::class, $rel->id, [
            'source' => $source::class.'#'.$source->getKey(),
            'target' => $target::class.'#'.$target->getKey(),
            'relation' => $relation,
        ]);
        return $rel;
    }
}
