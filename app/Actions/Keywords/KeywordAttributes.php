<?php

namespace App\Actions\Keywords;

use App\Enums\KeywordIntent;
use App\Enums\KeywordRole;
use App\Enums\KeywordStatus;
use App\Exceptions\DuplicateKeywordException;
use App\Models\Keyword;
use App\Models\Project;
use App\Support\Keywords\KeywordNormalizer;
use InvalidArgumentException;

/**
 * Shared keyword validation/normalisation for the create/update actions.
 */
final class KeywordAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> only the keys present in $attributes, normalised
     */
    public static function normalise(array $attributes): array
    {
        $result = [];

        if (array_key_exists('keyword', $attributes)) {
            $keyword = trim((string) $attributes['keyword']);

            if ($keyword === '' || mb_strlen($keyword) > 255) {
                throw new InvalidArgumentException('A keyword needs a value of at most 255 characters.');
            }

            $result['keyword'] = $keyword;
            $result['keyword_normalized'] = KeywordNormalizer::keyword($keyword);
        }

        if (array_key_exists('location', $attributes)) {
            $location = trim((string) $attributes['location']);

            if (mb_strlen($location) > 255) {
                throw new InvalidArgumentException('The keyword location may not exceed 255 characters.');
            }

            $result['location'] = $location === '' ? null : $location;
            $result['location_normalized'] = KeywordNormalizer::location($result['location']);
        }

        foreach (['search_volume' => 'search volume', 'keyword_difficulty' => 'keyword difficulty'] as $field => $label) {
            if (array_key_exists($field, $attributes)) {
                $value = $attributes[$field];

                if ($value === null || $value === '') {
                    $result[$field] = null;
                } elseif (! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
                    throw new InvalidArgumentException("The {$label} must be a non-negative integer.");
                } else {
                    $result[$field] = (int) $value;
                }
            }
        }

        if (array_key_exists('keyword_role', $attributes)) {
            $result['keyword_role'] = self::enumOrNull($attributes['keyword_role'], KeywordRole::class, 'keyword role');
        }

        if (array_key_exists('search_intent', $attributes)) {
            $result['search_intent'] = self::enumOrNull($attributes['search_intent'], KeywordIntent::class, 'search intent');
        }

        if (array_key_exists('is_branded', $attributes)) {
            $result['is_branded'] = filter_var($attributes['is_branded'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('status', $attributes) && $attributes['status'] !== null) {
            $result['status'] = self::enumOrNull($attributes['status'], KeywordStatus::class, 'status');
        }

        return $result;
    }

    /**
     * Case- and whitespace-insensitive uniqueness of keyword + location
     * within a project (soft-deleted rows still occupy the unique index).
     */
    public static function ensureUniqueWithin(Project $project, string $keywordNormalized, string $locationNormalized, ?Keyword $ignore = null): void
    {
        $exists = Keyword::withTrashed()
            ->where('project_id', $project->getKey())
            ->where('keyword_normalized', $keywordNormalized)
            ->where('location_normalized', $locationNormalized)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw DuplicateKeywordException::for($project, $keywordNormalized, $locationNormalized === '' ? null : $locationNormalized);
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    protected static function enumOrNull(mixed $value, string $enum, string $label): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof $enum) {
            return $value;
        }

        return $enum::tryFrom((string) $value)
            ?? throw new InvalidArgumentException("Unknown {$label} [{$value}].");
    }
}
