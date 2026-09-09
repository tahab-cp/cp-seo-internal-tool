<?php

namespace App\Support\Targets;

/**
 * One target's completion inside a Monthly Target Completion figure.
 *
 * - `percentage` is the uncapped actual/target figure shown to people
 *   (150% is a real over-delivery);
 * - `contribution` is what the aggregate uses: capped at 100 so one
 *   over-delivered target can never compensate for another that was
 *   missed;
 * - `participates` is false for unsupported keys (no operational module
 *   produces an actual) and for targets <= 0 (no positive deliverable
 *   requirement, and no division by zero).
 */
final readonly class TargetCompletionRow
{
    public function __construct(
        public string $targetKey,
        public string $label,
        public int $target,
        public ?int $actual,
        public bool $supported,
    ) {}

    public function participates(): bool
    {
        return $this->supported && $this->target > 0 && $this->actual !== null;
    }

    /**
     * Uncapped, full precision (150.0 for 75 / 50). Null when the row
     * does not participate.
     */
    public function percentage(): ?float
    {
        return $this->participates() ? $this->actual / $this->target * 100 : null;
    }

    /**
     * Capped at 100.0 for the aggregate. Null when not participating.
     */
    public function contribution(): ?float
    {
        $percentage = $this->percentage();

        return $percentage === null ? null : min(100.0, $percentage);
    }

    public function isMet(): bool
    {
        return $this->participates() && $this->actual >= $this->target;
    }

    public function display(): string
    {
        return ($this->actual ?? '—').' / '.$this->target;
    }

    public function percentageLabel(): string
    {
        $percentage = $this->percentage();

        return $percentage === null ? '—' : round($percentage).'%';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'target_key' => $this->targetKey,
            'label' => $this->label,
            'target' => $this->target,
            'actual' => $this->actual,
            'supported' => $this->supported,
            'participates' => $this->participates(),
            'percentage' => $this->percentage(),
            'contribution' => $this->contribution(),
        ];
    }
}
