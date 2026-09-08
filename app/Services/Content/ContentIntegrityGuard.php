<?php

namespace App\Services\Content;

use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\ActiveUserGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Domain rules every content workflow must respect, independent of forms:
 *
 * - a cycle or target keyword must belong to the item's project;
 * - locked cycles are immutable;
 * - a *new* assignee must be an active user who can access the project;
 * - published items need a reporting month, a published date and a valid URL.
 */
class ContentIntegrityGuard
{
    public const URL_MAX = 500;

    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    /**
     * Resolve a cycle id to a cycle of *this* project (null = project-level).
     */
    public function resolveCycle(Project $project, int|string|null $cycleId): ?MonthlyCycle
    {
        if ($cycleId === null || $cycleId === '') {
            return null;
        }

        $cycle = MonthlyCycle::query()->find((int) $cycleId);

        if ($cycle === null || (int) $cycle->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Monthly cycle [%s] does not belong to project "%s".',
                $cycleId,
                $project->name,
            ));
        }

        return $cycle;
    }

    /**
     * Resolve a keyword id to a keyword of *this* project (null = none).
     * Archived/paused keywords stay valid for existing history.
     */
    public function resolveKeyword(Project $project, int|string|null $keywordId): ?Keyword
    {
        if ($keywordId === null || $keywordId === '') {
            return null;
        }

        $keyword = Keyword::withTrashed()->find((int) $keywordId);

        if ($keyword === null || (int) $keyword->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Keyword [%s] does not belong to project "%s".',
                $keywordId,
                $project->name,
            ));
        }

        return $keyword;
    }

    public function ensureCycleNotLocked(?MonthlyCycle $cycle, string $operation): void
    {
        if ($cycle?->isLocked()) {
            throw LockedMonthlyCycleException::for($cycle, $operation);
        }
    }

    public function ensureItemNotLocked(ContentItem $item, string $operation): void
    {
        if ($item->isLocked()) {
            throw LockedMonthlyCycleException::for($item->monthlyCycle, $operation);
        }
    }

    /**
     * A newly assigned user must be active and able to access the project.
     * Existing historical assignments are never re-validated.
     */
    public function ensureAssignable(Project $project, int|string|null $userId): void
    {
        if ($userId === null || $userId === '') {
            return;
        }

        $this->activeUsers->ensureActive([$userId], 'content assignees');

        $user = User::query()->findOrFail((int) $userId);

        if (! Gate::forUser($user)->allows('view', $project)) {
            throw UnauthorizedProjectUserException::for($user, $project, 'a content assignee');
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function assignableUsers(Project $project): Collection
    {
        return User::query()
            ->active()
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => Gate::forUser($user)->allows('view', $project))
            ->values();
    }

    /**
     * Published items must be attributed to a reporting month and carry
     * their publish details; the cycle is never inferred from the date.
     */
    public function ensurePublishable(ContentItem $item): void
    {
        if (! $item->status->isPublished()) {
            return;
        }

        if ($item->monthly_cycle_id === null) {
            throw new InvalidArgumentException('Published content must be attributed to a reporting month.');
        }

        if ($item->published_at === null) {
            throw new InvalidArgumentException('Published content needs a published date.');
        }

        if (blank($item->published_url)) {
            throw new InvalidArgumentException('Published content needs a published URL.');
        }
    }

    public function normaliseTitle(mixed $title): string
    {
        $title = trim((string) $title);

        if ($title === '' || mb_strlen($title) > 255) {
            throw new InvalidArgumentException('Content needs a title of at most 255 characters.');
        }

        return $title;
    }

    public function normaliseType(mixed $type): ContentType
    {
        if ($type instanceof ContentType) {
            return $type;
        }

        return ContentType::tryFrom((string) $type)
            ?? throw new InvalidArgumentException("Unknown content type [{$type}].");
    }

    public function normaliseStatus(mixed $status): ContentStatus
    {
        if ($status instanceof ContentStatus) {
            return $status;
        }

        return ContentStatus::tryFrom((string) $status)
            ?? throw new InvalidArgumentException("Unknown content status [{$status}].");
    }

    public function normaliseUrl(mixed $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (mb_strlen($url) > self::URL_MAX || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException('The published URL must be a valid http(s) URL of at most '.self::URL_MAX.' characters.');
        }

        return $url;
    }

    public function normaliseDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return CarbonImmutable::parse($date)->toDateString();
    }

    public function normaliseDateTime(mixed $dateTime): ?CarbonImmutable
    {
        if ($dateTime === null || $dateTime === '') {
            return null;
        }

        return CarbonImmutable::parse($dateTime);
    }

    public function normaliseNotes(mixed $notes): ?string
    {
        $notes = trim((string) $notes);

        if ($notes === '') {
            return null;
        }

        if (mb_strlen($notes) > 5000) {
            throw new InvalidArgumentException('Content notes may not exceed 5000 characters.');
        }

        return $notes;
    }
}
