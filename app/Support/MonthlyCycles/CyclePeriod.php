<?php

namespace App\Support\MonthlyCycles;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A validated year/month pair identifying one monthly cycle.
 */
final readonly class CyclePeriod
{
    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    public function __construct(
        public int $year,
        public int $month,
    ) {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month must be between 1 and 12, [{$month}] given.");
        }

        if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
            throw new InvalidArgumentException(sprintf('Year must be between %d and %d, [%d] given.', self::MIN_YEAR, self::MAX_YEAR, $year));
        }
    }

    public static function current(): self
    {
        return self::fromDate(CarbonImmutable::now());
    }

    public static function fromDate(CarbonInterface $date): self
    {
        return new self((int) $date->format('Y'), (int) $date->format('n'));
    }

    /**
     * Parse "YYYY-MM".
     */
    public static function fromString(string $value): self
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($value), $matches) !== 1) {
            throw new InvalidArgumentException("Period must be formatted as YYYY-MM, [{$value}] given.");
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function startOfMonth(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1)->startOfDay();
    }

    /**
     * e.g. "September 2026".
     */
    public function label(): string
    {
        return $this->startOfMonth()->format('F Y');
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }
}
