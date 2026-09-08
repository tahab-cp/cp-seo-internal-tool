<?php

namespace App\Services\Rankings;

use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\RankingSnapshot;
use App\Support\Rankings\MonthlyRankingSummary;
use App\Support\Rankings\RankingMovement;
use Carbon\CarbonInterface;

/**
 * Derives ranking movement from snapshots. Nothing here is persisted.
 */
class RankingMovementService
{
    public function between(?int $previous, ?int $current): RankingMovement
    {
        return RankingMovement::between($previous, $current);
    }

    /**
     * Earliest and latest observation of the keyword within the cycle.
     */
    public function monthlySummary(Keyword $keyword, MonthlyCycle $cycle): MonthlyRankingSummary
    {
        $base = $keyword->rankingSnapshots()
            ->getQuery()
            ->reorder()
            ->where('monthly_cycle_id', $cycle->getKey());

        return new MonthlyRankingSummary(
            earliest: (clone $base)->orderBy('checked_at')->orderBy('id')->first(),
            latest: (clone $base)->orderByDesc('checked_at')->orderByDesc('id')->first(),
            snapshotCount: (clone $base)->count(),
        );
    }

    /**
     * The most recent observation strictly before a moment (any source).
     * Used as the read-only "Previous" in bulk entry.
     */
    public function latestSnapshotBefore(Keyword $keyword, CarbonInterface $moment): ?RankingSnapshot
    {
        return $keyword->rankingSnapshots()
            ->getQuery()
            ->reorder()
            ->where('checked_at', '<', $moment)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Movement from the previous observation to the given one.
     */
    public function movementFor(RankingSnapshot $snapshot): RankingMovement
    {
        $previous = $this->latestSnapshotBefore($snapshot->keyword, $snapshot->checked_at);

        return RankingMovement::between($previous?->position, $snapshot->position);
    }
}
