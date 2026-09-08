<?php

namespace App\Support\Rankings;

use App\Models\RankingSnapshot;

/**
 * A keyword's ranking picture for one monthly cycle: the earliest and
 * latest observations in that month and the movement between them.
 * Movement is only derived when two distinct observations exist.
 */
final readonly class MonthlyRankingSummary
{
    public function __construct(
        public ?RankingSnapshot $earliest,
        public ?RankingSnapshot $latest,
        public int $snapshotCount,
    ) {}

    public function monthStartPosition(): ?int
    {
        return $this->earliest?->position;
    }

    public function latestPosition(): ?int
    {
        return $this->latest?->position;
    }

    public function hasComparison(): bool
    {
        return $this->snapshotCount >= 2;
    }

    public function movement(): ?RankingMovement
    {
        if (! $this->hasComparison()) {
            return null;
        }

        return RankingMovement::between($this->monthStartPosition(), $this->latestPosition());
    }

    public function monthStartLabel(): string
    {
        return $this->earliest ? $this->earliest->positionLabel() : '—';
    }

    public function latestLabel(): string
    {
        return $this->latest ? $this->latest->positionLabel() : '—';
    }

    public function movementLabel(): string
    {
        return $this->movement()?->label() ?? ($this->snapshotCount === 1 ? 'No comparison yet' : 'No data');
    }
}
