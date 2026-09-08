<?php

namespace App\Support\Keywords;

/**
 * Deterministic normalisation used only for duplicate detection. The
 * user-facing keyword text is never altered.
 *
 *   " SEO  Agency London " → "seo agency london"
 */
final class KeywordNormalizer
{
    public static function keyword(string $keyword): string
    {
        return self::normalise($keyword);
    }

    /**
     * A missing location normalises to '' so it takes part in the unique index.
     */
    public static function location(?string $location): string
    {
        return $location === null ? '' : self::normalise($location);
    }

    protected static function normalise(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($collapsed);
    }
}
