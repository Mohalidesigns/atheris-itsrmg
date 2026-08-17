<?php

namespace App\Models\Concerns;

use App\Events\Ea\ArchitectureEntityChanged;
use App\Models\Ea\QualitySeal;
use App\Models\Ea\Subscription;

/**
 * HasQualitySeal — applied to every EA model in the EntityRegistry.
 *
 * Two jobs:
 *   1. Fire ArchitectureEntityChanged on update so the seal breaks (B3).
 *   2. Give the model convenience accessors for its seal and subscriptions, so
 *      controllers do not each grow their own lookup.
 *
 * The trait deliberately does not fire on create: a brand-new record has no
 * approval to invalidate, and its seal starts in Draft anyway.
 */
trait HasQualitySeal
{
    public static function bootHasQualitySeal(): void
    {
        static::updated(function ($model) {
            $changes = array_keys($model->getChanges());
            if (! empty($changes)) {
                ArchitectureEntityChanged::dispatch(
                    $model::class,
                    $model->getKey(),
                    $changes,
                    optional(auth()->user())->id,
                );
            }
        });

        // Deleting an entity leaves an orphaned seal and orphaned
        // subscriptions behind — both are polymorphic, so no database
        // constraint cleans them up.
        static::deleted(function ($model) {
            QualitySeal::forEntity($model::class, $model->getKey())->delete();
            Subscription::forEntity($model::class, $model->getKey())->delete();
        });
    }

    public function qualitySeal()
    {
        return $this->hasOne(QualitySeal::class, 'entity_id')
            ->where('entity_type', static::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class, 'entity_id')
            ->where('entity_type', static::class);
    }
}
