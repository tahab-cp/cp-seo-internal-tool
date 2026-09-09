<?php

namespace App\Support\LegacyMigration;

/**
 * Deterministic tally of a migration run: per entity type, how many items
 * were created, updated, skipped or conflicted, plus every issue in source
 * order. Rendered to the console and optionally to JSON.
 */
final class MigrationReport
{
    public const ENTITIES = [
        'clients', 'projects', 'cycles', 'targets', 'pages', 'page_optimizations', 'keywords', 'ranking_snapshots',
        'backlinks', 'content_items', 'tasks', 'analytics', 'notes',
    ];

    /**
     * @var array<string, array<string, int>>
     */
    private array $counts = [];

    /**
     * @var list<MigrationIssue>
     */
    private array $issues = [];

    /**
     * @var list<string>
     */
    private array $unmapped = [];

    public function __construct(
        public readonly string $sourceFile,
        public readonly string $checksum,
        public readonly string $mapperVersion,
        public readonly string $mode,
    ) {
        foreach (self::ENTITIES as $entity) {
            $this->counts[$entity] = array_fill_keys(MigrationOutcome::ALL, 0);
        }
    }

    public function tally(string $entity, string $outcome, int $times = 1): void
    {
        $this->counts[$entity] ??= array_fill_keys(MigrationOutcome::ALL, 0);
        $this->counts[$entity][$outcome] += $times;
    }

    public function issue(MigrationIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    public function unmappedColumn(string $sheet, string $column): void
    {
        $label = "{$sheet}: \"{$column}\"";

        if (! in_array($label, $this->unmapped, true)) {
            $this->unmapped[] = $label;
            $this->issues[] = MigrationIssue::warning($sheet, null, null, "Unmapped source column \"{$column}\": ignored by the current migration mapping.");
        }
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    public function count(string $entity, string $outcome): int
    {
        return $this->counts[$entity][$outcome] ?? 0;
    }

    /**
     * @return list<MigrationIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function warnings(): int
    {
        return count(array_filter($this->issues, fn (MigrationIssue $i): bool => $i->isWarning()));
    }

    public function errors(): int
    {
        return count(array_filter($this->issues, fn (MigrationIssue $i): bool => $i->isError()));
    }

    /**
     * @return list<string>
     */
    public function unmapped(): array
    {
        return $this->unmapped;
    }

    public function total(string $outcome): int
    {
        return array_sum(array_map(fn (array $c): int => $c[$outcome] ?? 0, $this->counts));
    }

    public function totalItems(): int
    {
        return array_sum(array_map(fn (string $o): int => $this->total($o), MigrationOutcome::ALL));
    }

    public function toArray(): array
    {
        return [
            'source_file' => $this->sourceFile,
            'checksum' => $this->checksum,
            'mapper_version' => $this->mapperVersion,
            'mode' => $this->mode,
            'totals' => [
                'items' => $this->totalItems(),
                'create' => $this->total(MigrationOutcome::CREATE),
                'update' => $this->total(MigrationOutcome::UPDATE),
                'skip' => $this->total(MigrationOutcome::SKIP),
                'conflict' => $this->total(MigrationOutcome::CONFLICT),
                'warnings' => $this->warnings(),
                'errors' => $this->errors(),
            ],
            'entities' => $this->counts,
            'unmapped_columns' => $this->unmapped,
            'issues' => array_map(fn (MigrationIssue $i): array => $i->toArray(), $this->issues),
        ];
    }
}
