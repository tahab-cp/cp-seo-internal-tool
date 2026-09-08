<?php

namespace App\Services\Analytics;

use App\Enums\DataSource;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\Project;
use App\Models\User;
use App\Services\ActiveUserGuard;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Domain rules shared by every analytics write, independent of forms:
 *
 * - a locked cycle is immutable (Super Admin included, until Milestone 14);
 * - whoever enters data manually must be active and able to access the project;
 * - a mapped Page must belong to the cycle's project;
 * - counts are whole numbers >= 0;
 * - percentages (CTR, engagement rate) are HUMAN percentages 0–100:
 *   8.5 means 8.5%. The same convention applies to GSC and GA4;
 * - average_position is a positive decimal or NULL, never 0;
 * - Moz DA / Ahrefs DR / Ahrefs UR are 0–100 scores;
 * - detail-row identities (query, page URL, country) are normalised so
 *   the same entity cannot appear twice in one month.
 */
class AnalyticsIntegrityGuard
{
    public const URL_MAX = 500;

    public function __construct(
        protected ActiveUserGuard $activeUsers,
    ) {}

    public function ensureCycleNotLocked(MonthlyCycle $cycle, string $operation): void
    {
        if ($cycle->isLocked()) {
            throw LockedMonthlyCycleException::for($cycle, $operation);
        }
    }

    /**
     * Whoever records analytics by hand must be active and able to access
     * the cycle's project.
     */
    public function ensureEnteredBy(MonthlyCycle $cycle, User $user): void
    {
        $this->activeUsers->ensureActive([$user->getKey()], 'the analytics author');

        $project = $cycle->project;

        if (! Gate::forUser($user)->allows('view', $project)) {
            throw UnauthorizedProjectUserException::for($user, $project, 'the analytics author');
        }
    }

    /**
     * Optional Page mapping: when given, the Page must belong to the
     * cycle's project. Removed / soft-deleted pages remain valid history.
     */
    public function resolvePage(Project $project, int|string|null $pageId): ?Page
    {
        if ($pageId === null || $pageId === '') {
            return null;
        }

        $page = Page::withTrashed()->find((int) $pageId);

        if ($page === null || (int) $page->project_id !== (int) $project->getKey()) {
            throw new InvalidArgumentException(sprintf(
                'Page [%s] does not belong to project "%s".',
                $pageId,
                $project->name,
            ));
        }

        return $page;
    }

    /**
     * Only sources a person can record today are accepted for manual saves.
     */
    public function normaliseManualSource(mixed $source): DataSource
    {
        if ($source === null || $source === '') {
            return DataSource::Manual;
        }

        $source = $source instanceof DataSource
            ? $source
            : (DataSource::tryFrom((string) $source) ?? throw new InvalidArgumentException("Unknown data source [{$source}]."));

        if (! in_array($source, DataSource::implemented(), true)) {
            throw new InvalidArgumentException(sprintf('The %s source is not available yet; only manual entry is implemented.', $source->getLabel()));
        }

        return $source;
    }

    /**
     * Whole number >= 0. NULL / '' is allowed only when not required.
     */
    public function normaliseCount(mixed $value, string $field, bool $required = false): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new InvalidArgumentException("The {$field} is required.");
            }

            return null;
        }

        if (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
            throw new InvalidArgumentException("The {$field} must be a whole number of 0 or more; [{$value}] given.");
        }

        return (int) $value;
    }

    /**
     * Human percentage 0–100 with two decimals (8.5 means 8.5%).
     */
    public function normalisePercentage(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
            throw new InvalidArgumentException("The {$field} must be a percentage between 0 and 100 (8.5 means 8.5%); [{$value}] given.");
        }

        return round((float) $value, 2);
    }

    /**
     * Positive decimal position or NULL; 0 never means "unknown".
     */
    public function normalisePosition(mixed $value, string $field = 'average position'): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value <= 0) {
            throw new InvalidArgumentException("The {$field} must be a positive number or left empty; [{$value}] given.");
        }

        return round((float) $value, 2);
    }

    /**
     * Vendor score 0–100 (Moz DA whole number; Ahrefs DR / UR one decimal).
     */
    public function normaliseScore(mixed $value, string $field, bool $integer = false): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 100 || ($integer && (int) $value != $value)) {
            throw new InvalidArgumentException("The {$field} must be a score between 0 and 100; [{$value}] given.");
        }

        return $integer ? (int) $value : round((float) $value, 1);
    }

    public function normaliseUrl(mixed $url, string $field = 'page URL'): string
    {
        $url = trim((string) $url);

        if ($url === '') {
            throw new InvalidArgumentException("The {$field} is required.");
        }

        if (mb_strlen($url) > self::URL_MAX || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new InvalidArgumentException("The {$field} must be a valid http(s) URL of at most ".self::URL_MAX.' characters.');
        }

        return $url;
    }

    /**
     * Search queries are compared lower-cased with collapsed whitespace.
     */
    public function normaliseQuery(mixed $query): string
    {
        $query = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $query) ?? ''));

        if ($query === '' || mb_strlen($query) > 255) {
            throw new InvalidArgumentException('A search query needs text of at most 255 characters.');
        }

        return $query;
    }

    /**
     * Countries keep their casing but collapse whitespace; identity is
     * case-insensitive.
     */
    public function normaliseCountry(mixed $country): string
    {
        $country = trim(preg_replace('/\s+/u', ' ', (string) $country) ?? '');

        if ($country === '' || mb_strlen($country) > 100) {
            throw new InvalidArgumentException('A country needs a name of at most 100 characters.');
        }

        return $country;
    }

    public function normaliseText(mixed $text, int $max, string $field): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException("The {$field} may not exceed {$max} characters.");
        }

        return $text;
    }

    /**
     * Case-insensitive identity key for detail rows.
     */
    public function identityKey(string $value): string
    {
        return mb_strtolower($value);
    }

    /**
     * Reject the same identity twice in one submission rather than letting
     * the last row silently win.
     *
     * @param  list<string>  $keys
     */
    public function ensureDistinct(array $keys, string $entity): void
    {
        $duplicates = collect($keys)->duplicates()->unique()->values();

        if ($duplicates->isNotEmpty()) {
            throw new InvalidArgumentException(sprintf(
                'Each %s may appear only once per month; duplicated: %s.',
                $entity,
                $duplicates->implode(', '),
            ));
        }
    }
}
