<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;

/**
 * The result of one live readiness evaluation for a monthly report.
 * Readiness is about having enough data to render every enabled+required
 * section; it is unrelated to whether monthly targets were met.
 */
final readonly class ReportReadiness
{
    /**
     * @param  Collection<int, SectionReadiness>  $sections
     */
    public function __construct(
        public Collection $sections,
    ) {}

    public function requiredCount(): int
    {
        return $this->sections->filter(fn (SectionReadiness $s): bool => $s->counts())->count();
    }

    public function completedRequiredCount(): int
    {
        return $this->sections->filter(fn (SectionReadiness $s): bool => $s->counts() && $s->complete)->count();
    }

    /**
     * Ready when nothing enabled+required is missing. A configuration with
     * zero required sections has nothing to block it and is ready.
     */
    public function isReady(): bool
    {
        return $this->sections->doesntContain(fn (SectionReadiness $s): bool => $s->blocksReadiness());
    }

    /**
     * Whole-number percentage of enabled+required sections complete. With
     * zero required sections there is nothing to measure: 100.
     */
    public function percentage(): int
    {
        $required = $this->requiredCount();

        if ($required === 0) {
            return 100;
        }

        return (int) floor($this->completedRequiredCount() / $required * 100);
    }

    /**
     * @return Collection<int, SectionReadiness>
     */
    public function missing(): Collection
    {
        return $this->sections->filter(fn (SectionReadiness $s): bool => $s->blocksReadiness())->values();
    }

    public function section(string $key): ?SectionReadiness
    {
        return $this->sections->first(fn (SectionReadiness $s): bool => $s->key->value === $key);
    }

    /**
     * "6 / 8 required sections".
     */
    public function label(): string
    {
        return sprintf('%d / %d required sections', $this->completedRequiredCount(), $this->requiredCount());
    }

    /**
     * @return array{ready: bool, required_count: int, completed_required_count: int, percentage: int, sections: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->isReady(),
            'required_count' => $this->requiredCount(),
            'completed_required_count' => $this->completedRequiredCount(),
            'percentage' => $this->percentage(),
            'sections' => $this->sections->map(fn (SectionReadiness $s): array => $s->toArray())->values()->all(),
        ];
    }
}
