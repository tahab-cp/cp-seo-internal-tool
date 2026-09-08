<?php

namespace App\Services\Backlinks;

use App\Enums\BacklinkStatus;
use App\Enums\BacklinkType;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Services\ActiveUserGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Domain rules every backlink workflow must respect, independent of forms:
 *
 * - the cycle must belong to the backlink's project;
 * - locked cycles are immutable;
 * - the creator must be an active user who can access the project;
 * - URLs must be valid; metrics are null or integers on a 0–100 scale.
 */
class BacklinkGuard
{
    public const URL_MAX = 500;

    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    public function resolveCycle(Project $project, int|string|null $cycleId): MonthlyCycle
    {
        $cycle = filled($cycleId) ? MonthlyCycle::query()->find((int) $cycleId) : null;

        if ($cycle === null || (int) $cycle->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Monthly cycle [%s] does not belong to project "%s".',
                $cycleId ?? 'none',
                $project->name,
            ));
        }

        return $cycle;
    }

    public function ensureCycleNotLocked(MonthlyCycle $cycle, string $operation): void
    {
        if ($cycle->isLocked()) {
            throw LockedMonthlyCycleException::for($cycle, $operation);
        }
    }

    public function ensureBacklinkNotLocked(Backlink $backlink, string $operation): void
    {
        if ($backlink->isLocked()) {
            throw LockedMonthlyCycleException::for($backlink->monthlyCycle, $operation);
        }
    }

    /**
     * The creator must be an active user who can access the project.
     */
    public function ensureCreator(Project $project, User $creator): void
    {
        $this->activeUsers->ensureActive([$creator->getKey()], 'the backlink creator');

        if (! Gate::forUser($creator)->allows('view', $project)) {
            throw UnauthorizedProjectUserException::for($creator, $project, 'the backlink creator');
        }
    }

    public function normaliseUrl(mixed $url, bool $required, string $field): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            if ($required) {
                throw new InvalidArgumentException("A backlink needs a {$field}.");
            }

            return null;
        }

        if (mb_strlen($url) > self::URL_MAX || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException("The backlink {$field} must be a valid http(s) URL of at most ".self::URL_MAX.' characters.');
        }

        return $url;
    }

    /**
     * Null / '' → null; otherwise an integer between 0 and 100.
     */
    public function normaliseMetric(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0 || (int) $value > 100) {
            throw new InvalidArgumentException("The backlink {$field} must be a whole number between 0 and 100; [{$value}] given.");
        }

        return (int) $value;
    }

    public function normaliseType(mixed $type): BacklinkType
    {
        if ($type instanceof BacklinkType) {
            return $type;
        }

        return BacklinkType::tryFrom((string) $type)
            ?? throw new InvalidArgumentException("Unknown backlink type [{$type}].");
    }

    public function normaliseStatus(mixed $status): BacklinkStatus
    {
        if ($status instanceof BacklinkStatus) {
            return $status;
        }

        return BacklinkStatus::tryFrom((string) $status)
            ?? throw new InvalidArgumentException("Unknown backlink status [{$status}].");
    }

    public function normaliseDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return CarbonImmutable::parse($date)->toDateString();
    }

    public function normaliseText(mixed $value, int $max, string $field): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("The backlink {$field} may not exceed {$max} characters.");
        }

        return $value;
    }
}
