<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Domain history that is written once and never changed: revisions and
 * audit events. Updates and deletes are refused at the model boundary so
 * no code path (Filament, tinker, a future bug) can rewrite the past.
 */
trait ImmutableRecord
{
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(static::class.' records are immutable history and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): bool
    {
        throw new LogicException(static::class.' records are immutable history and cannot be deleted.');
    }

    public function forceDelete(): bool
    {
        throw new LogicException(static::class.' records are immutable history and cannot be deleted.');
    }
}
