<?php

namespace App\Support\Rankings;

/**
 * Movement between two ranking observations, derived on demand.
 * Lower positions are better, so 24 → 8 is an improvement of 16.
 */
final readonly class RankingMovement
{
    public const IMPROVED = 'improved';

    public const DECLINED = 'declined';

    public const UNCHANGED = 'unchanged';

    public const ENTERED = 'entered';

    public const DROPPED = 'dropped';

    public const NOT_RANKING = 'not_ranking';

    private function __construct(
        public ?int $previousPosition,
        public ?int $currentPosition,
        public string $direction,
        public ?int $absoluteChange,
    ) {}

    public static function between(?int $previous, ?int $current): self
    {
        if ($previous === null && $current === null) {
            return new self(null, null, self::NOT_RANKING, null);
        }

        if ($previous === null) {
            return new self(null, $current, self::ENTERED, null);
        }

        if ($current === null) {
            return new self($previous, null, self::DROPPED, null);
        }

        $change = abs($previous - $current);

        return new self($previous, $current, match (true) {
            $current < $previous => self::IMPROVED,
            $current > $previous => self::DECLINED,
            default => self::UNCHANGED,
        }, $change);
    }

    public function isImprovement(): bool
    {
        return $this->direction === self::IMPROVED;
    }

    public function isDecline(): bool
    {
        return $this->direction === self::DECLINED;
    }

    /**
     * Human-readable, never a bare signed number.
     */
    public function label(): string
    {
        return match ($this->direction) {
            self::IMPROVED => sprintf('Improved %d position%s', $this->absoluteChange, $this->absoluteChange === 1 ? '' : 's'),
            self::DECLINED => sprintf('Declined %d position%s', $this->absoluteChange, $this->absoluteChange === 1 ? '' : 's'),
            self::UNCHANGED => 'Unchanged',
            self::ENTERED => sprintf('Now ranking at %d', $this->currentPosition),
            self::DROPPED => 'No longer ranking',
            default => 'Not ranking',
        };
    }

    /**
     * e.g. "24 → 8" or "Not Ranking → 20".
     */
    public function transition(): string
    {
        return self::positionLabel($this->previousPosition).' → '.self::positionLabel($this->currentPosition);
    }

    public static function positionLabel(?int $position): string
    {
        return $position === null ? 'Not Ranking' : (string) $position;
    }

    /**
     * @return array{previous_position: int|null, current_position: int|null, direction: string, absolute_change: int|null, label: string}
     */
    public function toArray(): array
    {
        return [
            'previous_position' => $this->previousPosition,
            'current_position' => $this->currentPosition,
            'direction' => $this->direction,
            'absolute_change' => $this->absoluteChange,
            'label' => $this->label(),
        ];
    }
}
