<?php

namespace App\Services\MonthlyCycles;

use App\Models\MonthlyCycle;
use App\Support\Targets\TargetProgress;

/**
 * Monthly target progress for one cycle.
 *
 * Targets come from the cycle's *snapshot* (monthly_cycle_targets), never
 * from the live package or overrides, so historical months stay stable.
 * Actuals come from the cycle's operational records. Only pages_optimized
 * is implemented so far; backlinks, guest posts and blogs arrive with
 * their own modules, and the aggregate Monthly Target Completion with the
 * dashboards.
 */
class TargetProgressService
{
    public const PAGES_OPTIMIZED = 'pages_optimized';

    /**
     * Distinct pages with at least one optimisation event in the cycle.
     * A page optimised twice in the month counts once.
     */
    public function pagesOptimisedActual(MonthlyCycle $cycle): int
    {
        return $cycle->pageOptimizations()->distinct()->count('page_id');
    }

    /**
     * The actual for a target key, or null when that module does not exist yet.
     */
    public function actualFor(MonthlyCycle $cycle, string $targetKey): ?int
    {
        return match ($targetKey) {
            self::PAGES_OPTIMIZED => $this->pagesOptimisedActual($cycle),
            default => null,
        };
    }

    public function progressFor(MonthlyCycle $cycle, string $targetKey): TargetProgress
    {
        $snapshot = $cycle->targets()->where('target_key', $targetKey)->first();

        return new TargetProgress(
            targetKey: $targetKey,
            label: $snapshot?->label ?? $this->defaultLabel($targetKey),
            actual: $this->actualFor($cycle, $targetKey),
            target: $snapshot?->target_value,
        );
    }

    public function pagesOptimised(MonthlyCycle $cycle): TargetProgress
    {
        return $this->progressFor($cycle, self::PAGES_OPTIMIZED);
    }

    protected function defaultLabel(string $targetKey): string
    {
        return match ($targetKey) {
            self::PAGES_OPTIMIZED => 'Pages Optimised',
            default => ucwords(str_replace('_', ' ', $targetKey)),
        };
    }
}
