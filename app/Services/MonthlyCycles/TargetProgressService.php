<?php

namespace App\Services\MonthlyCycles;

use App\Enums\BacklinkType;
use App\Models\MonthlyCycle;
use App\Support\Targets\TargetProgress;

/**
 * Monthly target progress for one cycle.
 *
 * Targets come from the cycle's *snapshot* (monthly_cycle_targets), never
 * from the live package or overrides, so historical months stay stable.
 * Actuals are derived queries over the cycle's operational records and are
 * never stored. Blogs arrive with the content module; the aggregate
 * Monthly Target Completion arrives with the dashboards.
 */
class TargetProgressService
{
    public const PAGES_OPTIMIZED = 'pages_optimized';

    public const BACKLINKS = 'backlinks';

    public const GUEST_POSTS = 'guest_posts';

    /**
     * Distinct pages with at least one optimisation event in the cycle.
     * A page optimised twice in the month counts once.
     */
    public function pagesOptimisedActual(MonthlyCycle $cycle): int
    {
        return $cycle->pageOptimizations()->distinct()->count('page_id');
    }

    /**
     * Live backlinks of any type (a live guest post counts here too).
     */
    public function backlinksActual(MonthlyCycle $cycle): int
    {
        return $cycle->backlinks()->live()->count();
    }

    /**
     * Live guest posts only.
     */
    public function guestPostsActual(MonthlyCycle $cycle): int
    {
        return $cycle->backlinks()->live()->guestPosts()->count();
    }

    /**
     * Live backlinks per type for the cycle, in enum order, zero-filled.
     *
     * @return array<string, int> type value => count
     */
    public function liveBacklinkTypeBreakdown(MonthlyCycle $cycle): array
    {
        $counts = $cycle->backlinks()
            ->live()
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $breakdown = [];

        foreach (BacklinkType::cases() as $type) {
            $breakdown[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        return $breakdown;
    }

    /**
     * The actual for a target key, or null when that module does not exist yet.
     */
    public function actualFor(MonthlyCycle $cycle, string $targetKey): ?int
    {
        return match ($targetKey) {
            self::PAGES_OPTIMIZED => $this->pagesOptimisedActual($cycle),
            self::BACKLINKS => $this->backlinksActual($cycle),
            self::GUEST_POSTS => $this->guestPostsActual($cycle),
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

    public function backlinks(MonthlyCycle $cycle): TargetProgress
    {
        return $this->progressFor($cycle, self::BACKLINKS);
    }

    public function guestPosts(MonthlyCycle $cycle): TargetProgress
    {
        return $this->progressFor($cycle, self::GUEST_POSTS);
    }

    protected function defaultLabel(string $targetKey): string
    {
        return match ($targetKey) {
            self::PAGES_OPTIMIZED => 'Pages Optimised',
            self::BACKLINKS => 'Backlinks',
            self::GUEST_POSTS => 'Guest Posts',
            default => ucwords(str_replace('_', ' ', $targetKey)),
        };
    }
}
