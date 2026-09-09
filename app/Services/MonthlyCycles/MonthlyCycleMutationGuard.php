<?php

namespace App\Services\MonthlyCycles;

use App\Exceptions\LockedMonthlyCycleException;
use App\Models\MonthlyCycle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single convention every monthly-data writer and the finalization
 * commit share: inside a transaction, take SELECT … FOR UPDATE on the
 * MonthlyCycle row(s) involved, re-read them fresh, and re-check the lock.
 *
 * Because finalization holds the same row lock while it rebuilds the
 * final snapshot and locks the month, no monthly writer can commit a
 * change between that final comparison and the lock; it blocks on the
 * row and then sees the cycle as locked. Multiple cycles are always
 * locked in ascending id order to avoid deadlocks.
 *
 * Row locks are short-lived: never call this around Chromium rendering
 * or remote uploads.
 */
class MonthlyCycleMutationGuard
{
    /**
     * Lock and re-read one cycle for writing; null (project-level data)
     * needs no cycle lock. Throws if the fresh row is locked.
     */
    public function lockForWrite(MonthlyCycle|int|string|null $cycle, string $operation): ?MonthlyCycle
    {
        if ($cycle === null || $cycle === '') {
            return null;
        }

        $locked = $this->lockRows([$this->id($cycle)])->first();

        $this->ensureUnlocked($locked, $operation);

        return $locked;
    }

    /**
     * Lock the source and destination cycles of a move together (ascending
     * id order), then verify neither is locked. Either side may be null
     * for project-level records.
     *
     * @return array{from: ?MonthlyCycle, to: ?MonthlyCycle}
     */
    public function lockForMove(MonthlyCycle|int|string|null $from, MonthlyCycle|int|string|null $to, string $fromOperation, string $toOperation): array
    {
        $fromId = $from === null || $from === '' ? null : $this->id($from);
        $toId = $to === null || $to === '' ? null : $this->id($to);

        $rows = $this->lockRows(array_values(array_unique(array_filter([$fromId, $toId]))));

        $fromCycle = $fromId !== null ? $rows->get($fromId) : null;
        $toCycle = $toId !== null ? $rows->get($toId) : null;

        if ($fromCycle !== null) {
            $this->ensureUnlocked($fromCycle, $fromOperation);
        }

        if ($toCycle !== null && $toId !== $fromId) {
            $this->ensureUnlocked($toCycle, $toOperation);
        }

        return ['from' => $fromCycle, 'to' => $toCycle];
    }

    /**
     * SELECT … FOR UPDATE on the given cycle ids in ascending order and
     * return the fresh rows keyed by id. Must run inside a transaction:
     * a row lock outside one would be released immediately and protect
     * nothing.
     *
     * @param  list<int>  $ids
     * @return Collection<int, MonthlyCycle>
     */
    public function lockRows(array $ids): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('MonthlyCycleMutationGuard must be used inside a database transaction.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return new Collection;
        }

        return MonthlyCycle::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (MonthlyCycle $cycle): int => (int) $cycle->getKey());
    }

    public function ensureUnlocked(?MonthlyCycle $cycle, string $operation): void
    {
        if ($cycle === null) {
            throw new LogicException('The monthly cycle to lock no longer exists.');
        }

        if ($cycle->isLocked()) {
            throw LockedMonthlyCycleException::for($cycle, $operation);
        }
    }

    protected function id(MonthlyCycle|int|string $cycle): int
    {
        return (int) ($cycle instanceof MonthlyCycle ? $cycle->getKey() : $cycle);
    }
}
