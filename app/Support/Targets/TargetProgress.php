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
     * Target minus actual, floored at zero; null when there is no target or
     * no actual to compare.
     */
    public function remaining(): ?int
    {
        if ($this->actual === null || $this->target === null) {
            return null;
        }

        return max(0, $this->target - $this->actual);
    }

    public function isOverTarget(): bool
    {
        return $this->actual !== null && $this->target !== null && $this->actual > $this->target;
    }

    public function isComplete(): bool
    {
        return $this->actual !== null && $this->target !== null && $this->actual >= $this->target;
    }

    /**
     * e.g. "5 / 8" or "5 / No target".
     */
    public function format(): string
    {
        return ($this->actual ?? '—').' / '.($this->hasTarget() ? $this->target : 'No target');
    }

    /**
     * e.g. "18 remaining", "Target met", "5 over target"; null without a target.
     */
    public function remainingLabel(): ?string
    {
        if ($this->remaining() === null) {
            return null;
        }

        if ($this->isOverTarget()) {
            return ($this->actual - $this->target).' over target';
        }

        return $this->remaining() === 0 ? 'Target met' : $this->remaining().' remaining';
    }

    /**
     * @return array{target_key: string, label: string, actual: int|null, target: int|null, percentage: int|null, remaining: int|null}
     */
    public function toArray(): array
    {
        return [
            'target_key' => $this->targetKey,
            'label' => $this->label,
            'actual' => $this->actual,
            'target' => $this->target,
            'percentage' => $this->percentage(),
            'remaining' => $this->remaining(),
        ];
    }
}
