<?php

namespace App\Support\Targets;

use Illuminate\Support\Collection;

/**
 * Monthly Target Completion for one MonthlyCycle: the equal-weight average
 * of the capped contributions of every participating target. This is an
 * operational deliverable measure, never an SEO or performance score.
 */
final readonly class MonthlyTargetCompletion
{
    /**
     * @param  Collection<int, TargetCompletionRow>  $rows
     */
    public function __construct(
        public int $cycleId,
        public Collection $rows,
    ) {}

    /**
     * @return Collection<int, TargetCompletionRow>
     */
    public function participating(): Collection
    {
        return $this->rows->filter(fn (TargetCompletionRow $row): bool => $row->participates())->values();
    }

    public function hasParticipatingTargets(): bool
    {
        return $this->participating()->isNotEmpty();
    }

    /**
     * Full-precision average of capped contributions (81.25 for
     * 100/50/75/100). Null when no target participates.
     */
    public function overall(): ?float
    {
        $participating = $this->participating();

        if ($participating->isEmpty()) {
            return null;
        }

        return $participating->sum(fn (TargetCompletionRow $row): float => $row->contribution()) / $participating->count();
    }

    public function overallPercentage(): ?int
    {
        $overall = $this->overall();

        return $overall === null ? null : (int) round($overall);
    }

    public function label(): string
    {
        $overall = $this->overallPercentage();

        return $overall === null ? 'No targets' : $overall.'%';
    }

    public function metCount(): int
    {
        return $this->participating()->filter(fn (TargetCompletionRow $row): bool => $row->isMet())->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cycle_id' => $this->cycleId,
            'overall' => $this->overall(),
            'overall_percentage' => $this->overallPercentage(),
            'rows' => $this->rows->map(fn (TargetCompletionRow $row): array => $row->toArray())->values()->all(),
        ];
    }
}
