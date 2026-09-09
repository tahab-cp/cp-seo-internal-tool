<?php

namespace App\Services\MonthlyCycles\Concerns;

use App\Models\MonthlyCycle;
use App\Services\MonthlyCycles\MonthlyCycleMutationGuard;

/**
 * Gives a domain guard the shared cycle row-lock convention. Every
 * `ensure…NotLocked` check in a writer becomes a real row lock plus a
 * fresh re-check, so a MonthlyCycle model loaded before the transaction
 * can never be trusted over the database.
 */
trait LocksMonthlyCycles
{
    protected function cycleLock(): MonthlyCycleMutationGuard
    {
        return app(MonthlyCycleMutationGuard::class);
    }

    /**
     * Lock the cycle row (null = project-level, nothing to lock) and refuse
     * when it is locked. Returns the fresh row.
     */
    public function lockCycle(MonthlyCycle|int|string|null $cycle, string $operation): ?MonthlyCycle
    {
        return $this->cycleLock()->lockForWrite($cycle, $operation);
    }

    /**
     * Lock source and destination cycles of a move in deterministic order.
     *
     * @return array{from: ?MonthlyCycle, to: ?MonthlyCycle}
     */
    public function lockCyclesForMove(MonthlyCycle|int|string|null $from, MonthlyCycle|int|string|null $to, string $fromOperation, string $toOperation): array
    {
        return $this->cycleLock()->lockForMove($from, $to, $fromOperation, $toOperation);
    }
}
