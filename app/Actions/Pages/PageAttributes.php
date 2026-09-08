<?php

namespace App\Actions\Pages;

use App\Enums\PageStatus;
use App\Models\Page;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Shared page validation for the create/update actions (kept out of
 * Filament so every caller gets the same rules).
 */
final class PageAttributes
{
    public const URL_MAX = 500;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> only the keys present in $attributes, normalised
     */
    public static function normalise(array $attributes): array
    {
        $result = [];

        if (array_key_exists('url', $attributes)) {
            $url = trim((string) $attributes['url']);

            if ($url === '' || mb_strlen($url) > self::URL_MAX || filter_var($url, FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw new InvalidArgumentException('A page needs a valid http(s) URL of at most '.self::URL_MAX.' characters.');
            }

            $result['url'] = $url;
        }

        $path = array_key_exists('path', $attributes) ? trim((string) $attributes['path']) : '';

        if ($path !== '') {
            $result['path'] = self::limit($path, self::URL_MAX, 'path');
        } elseif (isset($result['url'])) {
            // A blank path is derived from the URL, as the form promises.
            $result['path'] = parse_url($result['url'], PHP_URL_PATH) ?: '/';
        } elseif (array_key_exists('path', $attributes)) {
            $result['path'] = null;
        }

        if (array_key_exists('title', $attributes)) {
            $title = trim((string) $attributes['title']);
            $result['title'] = $title === '' ? null : self::limit($title, 255, 'title');
        }

        if (array_key_exists('page_type', $attributes)) {
            $type = trim((string) $attributes['page_type']);
            $result['page_type'] = $type === '' ? null : self::limit($type, 100, 'page type');
        }

        if (array_key_exists('status', $attributes) && $attributes['status'] !== null) {
            $status = $attributes['status'];
            $result['status'] = $status instanceof PageStatus ? $status : PageStatus::from((string) $status);
        }

        return $result;
    }

    /**
     * URLs are unique within a project (including soft-deleted rows, which
     * still occupy the unique index) but may repeat across projects.
     */
    public static function ensureUrlUniqueWithin(Project $project, string $url, ?Page $ignore = null): void
    {
        $exists = Page::withTrashed()
            ->where('project_id', $project->getKey())
            ->where('url', $url)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("The URL [{$url}] already exists in project \"{$project->name}\".");
        }
    }

    protected static function limit(string $value, int $max, string $field): string
    {
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("The page {$field} may not exceed {$max} characters.");
        }

        return $value;
    }
}
