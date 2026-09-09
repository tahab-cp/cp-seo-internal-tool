<?php

namespace App\Services\LegacyMigration;

use App\Models\User;
use App\Services\Imports\CsvValueNormalizer;
use App\Support\MonthlyCycles\CyclePeriod;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Filament\Support\Contracts\HasLabel;
use InvalidArgumentException;

/**
 * Turns legacy cell text into application values WITHOUT weakening any
 * enum: a value resolves only when it is the enum value, the enum label,
 * or an explicit entry in the mapping. Dates follow the Milestone 16 rules
 * (ISO forms only) plus the spreadsheet header forms documented for
 * ranking date columns.
 */
class LegacyValueMapper
{
    public function __construct(
        protected LegacyMapping $mapping,
        protected CsvValueNormalizer $normalizer,
    ) {}

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    public function enum(string $group, string $legacy, string $enum): BackedEnum
    {
        $value = trim($legacy);

        if ($value === '') {
            throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $group)).' is blank.');
        }

        $mapped = $this->mapping->value($group, $value) ?? $value;

        foreach ($enum::cases() as $case) {
            if (strcasecmp((string) $case->value, $mapped) === 0) {
                return $case;
            }

            if ($case instanceof HasLabel && strcasecmp((string) $case->getLabel(), $mapped) === 0) {
                return $case;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown %s "%s"; add it to the "values.%s" mapping (allowed: %s).',
            str_replace('_', ' ', $group),
            $value,
            $group,
            implode(', ', array_map(fn (BackedEnum $c): string => (string) $c->value, $enum::cases())),
        ));
    }

    /**
     * A user by email, or by a mapped legacy name. Never fuzzy; never created.
     */
    public function user(string $legacy): ?User
    {
        $text = trim($legacy);

        if ($text === '') {
            return null;
        }

        $email = filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? strtolower($text) : $this->mapping->userEmail($text);

        if ($email === null) {
            return null;
        }

        return User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    public function isBlank(?string $value): bool
    {
        return $this->normalizer->isBlank($value);
    }

    public function text(?string $value): ?string
    {
        return $this->normalizer->text($value);
    }

    public function date(string $value): string
    {
        return $this->normalizer->date($value);
    }

    public function dateTime(string $value): string
    {
        return $this->normalizer->dateTime($value);
    }

    public function boolean(string $value): bool
    {
        return $this->normalizer->boolean($value);
    }

    /**
     * YYYY-MM (the documented period form), or a date whose month is used.
     */
    public function period(string $value): CyclePeriod
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return CyclePeriod::fromString($value);
        }

        return CyclePeriod::fromDate(CarbonImmutable::parse($this->dateTime($value)));
    }

    /**
     * Ranking date column headers: ISO dates, XLSX date cells rendered as
     * YYYY-MM-DD, and "Jul 01" / "01 Jul" / "Jul 01 2026" / "01 Jul 2026".
     * Month-day forms without a year need the mapping's ranking_year.
     * Anything else is not a date column.
     */
    public function rankingDate(string $header): ?CarbonImmutable
    {
        $value = trim(preg_replace('/\s+/', ' ', $header) ?? $header);
        $timezone = new DateTimeZone(config('app.timezone'));

        foreach (['Y-m-d', 'Y-m-d H:i:s', 'M d Y', 'd M Y', 'M j Y', 'j M Y', 'F d Y', 'd F Y', 'F j Y', 'j F Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return CarbonImmutable::instance($parsed);
            }
        }

        foreach (['M d', 'd M', 'M j', 'j M', 'F d', 'd F', 'F j', 'j F'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!'.$format.' Y', $value.' '.($this->mapping->rankingYear() ?? 2000), $timezone);
            $errors = DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                if ($this->mapping->rankingYear() === null) {
                    throw new InvalidArgumentException("Ranking column \"{$header}\" has no year; set \"ranking_year\" in the mapping.");
                }

                return CarbonImmutable::instance($parsed);
            }
        }

        return null;
    }

    /**
     * A ranking cell: NULL for blank / documented not-ranking tokens,
     * otherwise the raw text for RankingSnapshotGuard::normalisePosition.
     *
     * @return array{0: ?string, 1: bool} value, whether a legacy token was translated
     */
    public function rankingCell(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [null, false];
        }

        if (in_array(strtolower($value), $this->mapping->notRankingTokens(), true)) {
            return [null, true];
        }

        return [$value, false];
    }
}
