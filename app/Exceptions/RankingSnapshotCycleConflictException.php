<?php

namespace App\Exceptions;

use App\Models\MonthlyCycle;
use App\Models\RankingSnapshot;
use InvalidArgumentException;

/**
 * Thrown when an observation with the same identity (keyword + checked_at +
 * source) already exists in a different reporting month. The upsert path
 * never moves history between months; use the deliberate correction
 * workflow instead.
 */
class RankingSnapshotCycleConflictException extends InvalidArgumentException
{
    public static function for(RankingSnapshot $existing, MonthlyCycle $requested): self
    {
        return new self(sprintf(
            'An observation for "%s" at %s (%s) already exists in %s; it cannot be re-recorded against %s. Correct the existing observation instead.',
            $existing->keyword->keyword,
            $existing->checked_at->format('Y-m-d H:i'),
            $existing->source->getLabel(),
            $existing->monthlyCycle->periodLabel(),
            $requested->periodLabel(),
        ));
    }
}
