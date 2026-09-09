<?php

namespace App\Services\LegacyMigration;

use InvalidArgumentException;

/**
 * The explicit office mapping: config/legacy-migration.php merged with an
 * optional JSON file passed to the command. Lookups are case-insensitive
 * on trimmed keys. Nothing is guessed: a missing key returns null and the
 * caller decides (create, warn or conflict).
 */
class LegacyMapping
{
    public const VERSION = '1.0';

    /**
     * @param  array<string, mixed>  $mapping
     */
    public function __construct(
        protected array $mapping,
        public readonly ?string $mappingFile = null,
    ) {}

    public static function fromConfig(?string $jsonPath = null): self
    {
        $mapping = (array) config('legacy-migration', []);

        if ($jsonPath !== null) {
            if (! is_file($jsonPath) || ! is_readable($jsonPath)) {
                throw new InvalidArgumentException("Mapping file [{$jsonPath}] does not exist or is not readable.");
            }

            $override = json_decode((string) file_get_contents($jsonPath), true);

            if (! is_array($override)) {
                throw new InvalidArgumentException("Mapping file [{$jsonPath}] is not a JSON object.");
            }

            $mapping = array_replace_recursive($mapping, $override);
        }

        return new self($mapping, $jsonPath);
    }

    public function clientId(string $legacyKey): ?int
    {
        $id = $this->lookup('clients', $legacyKey);

        return is_numeric($id) ? (int) $id : null;
    }

    public function projectId(string $legacyKey): ?int
    {
        $id = $this->lookup('projects', $legacyKey);

        return is_numeric($id) ? (int) $id : null;
    }

    public function userEmail(string $legacyText): ?string
    {
        $email = $this->lookup('users', $legacyText);

        return is_string($email) && $email !== '' ? strtolower(trim($email)) : null;
    }

    public function packageId(string $legacyLabel): ?int
    {
        $id = $this->lookup('packages', $legacyLabel);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Legacy wording => application enum value for a value group such as
     * task_status or backlink_type.
     */
    public function value(string $group, string $legacy): ?string
    {
        $value = $this->lookup("values.{$group}", $legacy);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string> lower-cased tokens
     */
    public function notRankingTokens(): array
    {
        return array_map(fn ($t): string => strtolower(trim((string) $t)), (array) ($this->mapping['not_ranking'] ?? []));
    }

    public function rankingYear(): ?int
    {
        $year = $this->mapping['ranking_year'] ?? null;

        return is_numeric($year) ? (int) $year : null;
    }

    public function toArray(): array
    {
        return $this->mapping;
    }

    protected function lookup(string $path, string $key): mixed
    {
        $table = data_get($this->mapping, $path, []);

        if (! is_array($table)) {
            return null;
        }

        $wanted = self::normaliseKey($key);

        foreach ($table as $legacy => $value) {
            if (self::normaliseKey((string) $legacy) === $wanted) {
                return $value;
            }
        }

        return null;
    }

    public static function normaliseKey(string $key): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $key) ?? $key));
    }
}
