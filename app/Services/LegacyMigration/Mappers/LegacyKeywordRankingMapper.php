<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Keywords\CreateKeywordAction;
use App\Actions\Pages\CreatePageAction;
use App\Actions\Rankings\RecordRankingSnapshotAction;
use App\Enums\RankingSource;
use App\Models\Keyword;
use App\Models\Page;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Services\Rankings\RankingSnapshotGuard;
use App\Support\Keywords\KeywordNormalizer;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use App\Support\MonthlyCycles\CyclePeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * "Rankings" sheet in the office layout:
 *
 *   project | keyword | location | target page | Jul 01 | Jul 08 | ...
 *
 * Every date column cell becomes ONE RankingSnapshot (source: manual, the
 * legacy sheets were kept by hand). Date columns are never stored.
 *
 *   - keyword identity: normalised keyword + location within the project
 *     (existing reused, else created through CreateKeywordAction)
 *   - target page: Project + URL identity (existing reused, else created)
 *   - blank / documented not-ranking tokens → NULL (Not Ranking); 0 is
 *     never stored
 *   - each observation goes to the historical cycle of its own month; an
 *     optional "period" column must agree with every date column
 *   - existing identical observation → SKIP; differing → CONFLICT
 */
class LegacyKeywordRankingMapper extends LegacySheetMapper
{
    /**
     * @var array<string, CarbonImmutable> header => date
     */
    protected array $dateColumns = [];

    public function __construct(
        protected CreateKeywordAction $createKeyword,
        protected CreatePageAction $createPage,
        protected RecordRankingSnapshotAction $recordSnapshot,
        protected RankingSnapshotGuard $guard,
    ) {}

    public function sheet(): string
    {
        return 'Rankings';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'keyword' => ['aliases' => ['keywords', 'query', 'term'], 'required' => true],
            'location' => ['aliases' => ['geo', 'target location']],
            'target_page_url' => ['aliases' => ['target page', 'target url', 'landing page', 'page']],
            'period' => ['aliases' => ['month', 'reporting month']],
        ];
    }

    public function acceptsDynamicColumn(string $header, MigrationContext $context): bool
    {
        $date = $context->values->rankingDate($header);

        if ($date === null) {
            return false;
        }

        $this->dateColumns[$header] = $date;

        return true;
    }

    public function withColumns(array $columns): static
    {
        $clone = parent::withColumns($columns);
        $clone->dateColumns = $this->dateColumns;

        return $clone;
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'keyword');

                $project = $this->project($context, $row);
                $keyword = $this->resolveKeyword($context, $sheet, $rowNumber, $row, $project);

                if ($this->dateColumns === []) {
                    $context->warning($sheet->name, $rowNumber, 'ranking_snapshot', 'The Rankings sheet has no date columns; keywords were migrated but no observations.');

                    continue;
                }

                $explicitPeriod = $this->has('period') && ! $this->blank($row, 'period') ? $context->values->period($this->value($row, 'period')) : null;

                foreach ($this->dateColumns as $header => $date) {
                    $this->migrateObservation($context, $sheet, $rowNumber, $row, $project, $keyword, $header, $date, $explicitPeriod);
                }
            } catch (InvalidArgumentException $exception) {
                $context->tally('keywords', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'keyword', $exception->getMessage(), $row);
            }
        }
    }

    protected function migrateObservation(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row, Project $project, Keyword $keyword, string $header, CarbonImmutable $date, ?CyclePeriod $explicitPeriod): void
    {
        [$raw, $translated] = $context->values->rankingCell((string) ($row[$header] ?? ''));

        try {
            $position = $this->guard->normalisePosition($raw);
        } catch (InvalidArgumentException $exception) {
            $context->tally('ranking_snapshots', MigrationOutcome::CONFLICT);
            $context->error($sheet->name, $rowNumber, 'ranking_snapshot', sprintf('Column "%s": %s', $header, $exception->getMessage()));

            return;
        }

        if ($translated) {
            $context->warning($sheet->name, $rowNumber, 'ranking_snapshot', sprintf('Column "%s": legacy value "%s" recorded as Not Ranking (NULL), never as 0.', $header, trim((string) $row[$header])));
        }

        $period = CyclePeriod::fromDate($date);

        if ($explicitPeriod !== null && ! $explicitPeriod->equals($period)) {
            $context->tally('ranking_snapshots', MigrationOutcome::CONFLICT);
            $context->error($sheet->name, $rowNumber, 'ranking_snapshot', sprintf('Column "%s" (%s) belongs to %s but the row says period %s; the observation was not migrated.', $header, $date->toDateString(), $period->label(), $explicitPeriod->label()));

            return;
        }

        $cycle = $this->cycle($context, $project, $period, $sheet, $rowNumber);

        if ($cycle === null) {
            $context->tally('ranking_snapshots', MigrationOutcome::CONFLICT);

            return;
        }

        $checkedAt = $date->format('Y-m-d H:i:s');

        $existing = RankingSnapshot::query()
            ->where('keyword_id', $keyword->getKey())
            ->where('checked_at', $checkedAt)
            ->where('source', RankingSource::Manual->value)
            ->first();

        if ($existing !== null) {
            if ($existing->position === $position) {
                $context->tally('ranking_snapshots', MigrationOutcome::SKIP);
            } else {
                $context->tally('ranking_snapshots', MigrationOutcome::CONFLICT);
                $context->warning($sheet->name, $rowNumber, 'ranking_snapshot', sprintf('Column "%s": an observation for "%s" at that moment already exists with position %s (legacy %s); left unchanged.', $header, $keyword->keyword, $existing->positionLabel(), $position ?? 'Not Ranking'));
            }

            return;
        }

        $this->recordSnapshot->handle($project, [
            'keyword_id' => $keyword->getKey(),
            'monthly_cycle_id' => $cycle->getKey(),
            'checked_at' => $checkedAt,
            'position' => $position,
            'source' => RankingSource::Manual,
        ]);

        $context->tally('ranking_snapshots', MigrationOutcome::CREATE);
    }

    protected function resolveKeyword(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row, Project $project): Keyword
    {
        $text = $this->value($row, 'keyword');
        $location = $this->optional($row, 'location');

        $existing = Keyword::withTrashed()
            ->where('project_id', $project->getKey())
            ->where('keyword_normalized', KeywordNormalizer::keyword($text))
            ->where('location_normalized', KeywordNormalizer::location($location))
            ->first();

        if ($existing !== null) {
            $context->tally('keywords', MigrationOutcome::SKIP);

            return $existing;
        }

        $page = $this->blank($row, 'target_page_url') ? null : $this->resolvePage($context, $sheet, $rowNumber, $project, $this->value($row, 'target_page_url'));

        $keyword = $this->createKeyword->handle($project, [
            'keyword' => $text,
            'location' => $location,
            'target_page_id' => $page?->getKey(),
        ]);

        $context->tally('keywords', MigrationOutcome::CREATE);

        return $keyword;
    }

    protected function resolvePage(MigrationContext $context, LegacySheet $sheet, int $rowNumber, Project $project, string $url): ?Page
    {
        $existing = Page::withTrashed()->where('project_id', $project->getKey())->where('url', trim($url))->first();

        if ($existing !== null) {
            $context->tally('pages', MigrationOutcome::SKIP);

            return $existing;
        }

        try {
            $page = $this->createPage->handle($project, ['url' => $url]);
        } catch (InvalidArgumentException $exception) {
            $context->warning($sheet->name, $rowNumber, 'page', sprintf('Target page "%s" not linked: %s', $url, $exception->getMessage()));

            return null;
        }

        $context->tally('pages', MigrationOutcome::CREATE);

        return $page;
    }
}
