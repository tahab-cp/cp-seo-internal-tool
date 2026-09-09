<?php

namespace App\Services\Imports;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * CSV-specific value normalisation, deliberately strict:
 *
 *   booleans   1/0, true/false, yes/no (case-insensitive); anything else is rejected
 *   dates      YYYY-MM-DD; datetimes also YYYY-MM-DD HH:MM[:SS] and ISO 8601
 *              "T" forms with optional offset. Slashed or dotted forms such
 *              as 01/02/2026 are rejected as ambiguous rather than guessed.
 *
 * Numbers, enums, URLs and percentages are NOT normalised here: they go
 * through the existing domain guards so the rules stay in one place.
 */
class CsvValueNormalizer
{
    public const DATE_FORMATS = ['Y-m-d'];

    public const DATETIME_FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s\Z', 'Y-m-d'];

    public function isBlank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }

    public function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function boolean(string $value): bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => throw new InvalidArgumentException("\"{$value}\" is not a recognised yes/no value; use 1/0, true/false or yes/no."),
        };
    }

    /**
     * @return string YYYY-MM-DD
     */
    public function date(string $value): string
    {
        return $this->parse($value, self::DATE_FORMATS, 'a date in YYYY-MM-DD form')->format('Y-m-d');
    }

    /**
     * @return string YYYY-MM-DD HH:MM:SS in the application timezone
     */
    public function dateTime(string $value): string
    {
        return $this->parse($value, self::DATETIME_FORMATS, 'a date/time in YYYY-MM-DD or YYYY-MM-DD HH:MM:SS form')
            ->setTimezone(new DateTimeZone(config('app.timezone')))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @param  list<string>  $formats
     */
    protected function parse(string $value, array $formats, string $expectation): DateTimeImmutable
    {
        $value = trim($value);

        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone(config('app.timezone')));
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            // Round-trip so overflowed values (2026-02-30) are rejected too.
            if ($parsed->format($format) !== $value && ! $this->equivalentWithoutSeconds($parsed, $format, $value)) {
                continue;
            }

            return $parsed;
        }

        throw new InvalidArgumentException("\"{$value}\" is not {$expectation}; slashed dates such as 01/02/2026 are ambiguous and not accepted.");
    }

    protected function equivalentWithoutSeconds(DateTimeImmutable $parsed, string $format, string $value): bool
    {
        // Offsets may be written +05:00 or Z and still round-trip differently.
        return in_array($format, ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s\Z'], true)
            && $parsed->format('Y-m-d\TH:i:s') === substr($value, 0, 19);
    }
}
