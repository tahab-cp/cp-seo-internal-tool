<?php

namespace App\Support\Targets;

/**
 * Actual versus snapshotted target for one key in one monthly cycle.
 * "No target" is a real state: it is never pretended to be zero.
 */
final readonly class TargetProgress
{
    public function __construct(
        public string $targetKey,
        public string $label,
        public ?int $actual,
        public ?int $target,
    ) {}

    public function hasTarget(): bool
    {
        return $this->target !== null;
    }

    public function hasActual(): bool
    {
        return $this->actual !== null;
    }

    /**
     * Whole-number percentage, or null when there is no positive target to
     * measure against (or no actual yet). Not capped: capping belongs to the
     * aggregate Monthly Target Completion (Milestone 15).
     */
    public function percentage(): ?int
    {
        if ($this->actual === null || $this->target === null || $this->target <= 0) {
            return null;
        }

        return (int) round($this->actual / $this->target * 100);
    }

    /**
     * e.g. "5 / 8" or "5 / No target".
     */
    public function format(): string
    {
        return ($this->actual ?? '—').' / '.($this->hasTarget() ? $this->target : 'No target');
    }

    /**
     * @return array{target_key: string, label: string, actual: int|null, target: int|null, percentage: int|null}
     */
    public function toArray(): array
    {
        return [
            'target_key' => $this->targetKey,
            'label' => $this->label,
            'actual' => $this->actual,
            'target' => $this->target,
            'percentage' => $this->percentage(),
        ];
    }
}
