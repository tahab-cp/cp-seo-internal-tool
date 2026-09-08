<?php

namespace App\Exceptions;

use App\Models\MonthlyCycle;
use RuntimeException;

/**
 * Thrown when a workflow tries to change monthly data inside a locked cycle.
 */
class LockedMonthlyCycleException extends RuntimeException
{
    public static function for(MonthlyCycle $cycle, string $operation): self
    {
        return new self(sprintf(
            '%s is locked (finalized); cannot %s. Only a Super Admin can unlock the month.',
            $cycle->periodLabel(),
            $operation,
        ));
    }
}
