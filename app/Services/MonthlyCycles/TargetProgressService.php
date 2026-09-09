<?php

namespace App\Services\MonthlyCycles;

use App\Enums\BacklinkType;
use App\Models\Backlink;
use App\Models\ContentItem;
use App\Models\MonthlyCycle;
use App\Models\PageOptimization;
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

    public const BLOGS = 'blogs';

    /**
     * Target keys with an operational module that produces an actual.
     *
     * @return list<string>
     */
    public static function supportedTargetKeys(): array
    {
        return [self::PAGES_OPTIMIZED, self::BACKLINKS, self::GUEST_POSTS, self::BLOGS];
    }

    public static function supports(string $targetKey): bool
    {
        return in_array($targetKey, self::supportedTargetKeys(), true);
    }

    /**
     * The same four derivations as the single-cycle methods, computed for
     * many cycles with one grouped query per key (dashboard use). Cycles
     * without rows get 0.
     *
     * @param  list<int>  $cycleIds
     * @return array<int, array<string, int>> cycle id => [target key => actual]
     */
    public function actualsForCycles(array $cycleIds): array
    {
        $cycleIds = array_values(array_unique(array_map('intval', $cycleIds)));
        $actuals = [];

        foreach ($cycleIds as $id) {
            $actuals[$id] = array_fill_keys(self::supportedTargetKeys(), 0);
        }

        if ($cycleIds === []) {
            return $actuals;
        }

        $grouped = [
            self::PAGES_OPTIMIZED => PageOptimization::query()->whereIn('monthly_cycle_id', $cycleIds)
                ->selectRaw('monthly_cycle_id, COUNT(DISTINCT page_id) as total')->groupBy('monthly_cycle_id')->pluck('total', 'monthly_cycle_id'),
            self::BACKLINKS => Backlink::query()->live()->whereIn('monthly_cycle_id', $cycleIds)
                ->selectRaw('monthly_cycle_id, COUNT(*) as total')->groupBy('monthly_cycle_id')->pluck('total', 'monthly_cycle_id'),
            self::GUEST_POSTS => Backlink::query()->live()->guestPosts()->whereIn('monthly_cycle_id', $cycleIds)
                ->selectRaw('monthly_cycle_id, COUNT(*) as total')->groupBy('monthly_cycle_id')->pluck('total', 'monthly_cycle_id'),
            self::BLOGS => ContentItem::query()->publishedBlogs()->whereIn('monthly_cycle_id', $cycleIds)
                ->selectRaw('monthly_cycle_id, COUNT(*) as total')->groupBy('monthly_cycle_id')->pluck('total', 'monthly_cycle_id'),
        ];

        foreach ($grouped as $key => $totals) {
            foreach ($totals as $cycleId => $total) {
                $actuals[(int) $cycleId][$key] = (int) $total;
            }
        }

        return $actuals;
    }

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
     * Published blog-type content items attributed to the cycle.
     * Soft-deleted items are excluded by the model's global scope.
     */
    public function blogsActual(MonthlyCycle $cycle): int
    {
        return $cycle->contentItems()->publishedBlogs()->count();
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
            self::BLOGS => $this->blogsActual($cycle),
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

    public function blogs(MonthlyCycle $cycle): TargetProgress
    {
        return $this->progressFor($cycle, self::BLOGS);
    }

    protected function defaultLabel(string $targetKey): string
    {
        return match ($targetKey) {
            self::PAGES_OPTIMIZED => 'Pages Optimised',
            self::BACKLINKS => 'Backlinks',
            self::GUEST_POSTS => 'Guest Posts',
            self::BLOGS => 'Blogs',
            default => ucwords(str_replace('_', ' ', $targetKey)),
        };
    }
}
