<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Analytics\SaveGa4CountryMetricsAction;
use App\Actions\Analytics\SaveGscPageMetricsAction;
use App\Actions\Analytics\SaveGscQueryMetricsAction;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Services\Analytics\AnalyticsIntegrityGuard;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use InvalidArgumentException;

/**
 * The three analytics DETAIL sheets (GscQueries, GscPages, Ga4Countries):
 * rows for one project-month are handed as one dataset to the existing
 * Save*MetricsAction. A month that already has rows is a SKIP when the
 * dataset is identical and a CONFLICT otherwise (never replaced).
 */
class LegacyAnalyticsDetailMapper extends LegacySheetMapper
{
    public const GSC_QUERIES = 'GscQueries';

    public const GSC_PAGES = 'GscPages';

    public const GA4_COUNTRIES = 'Ga4Countries';

    public function __construct(
        protected string $kind,
        protected SaveGscQueryMetricsAction $saveQueries,
        protected SaveGscPageMetricsAction $savePages,
        protected SaveGa4CountryMetricsAction $saveCountries,
        protected AnalyticsIntegrityGuard $guard,
    ) {}

    public function sheet(): string
    {
        return $this->kind;
    }

    public function fields(): array
    {
        $common = [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'period' => ['aliases' => ['month', 'reporting month'], 'required' => true],
        ];

        return $common + match ($this->kind) {
            self::GSC_QUERIES => [
                'query' => ['aliases' => ['top queries', 'search query', 'keyword'], 'required' => true],
                'clicks' => ['required' => true],
                'impressions' => ['required' => true],
                'ctr' => [],
                'average_position' => ['aliases' => ['position', 'avg position']],
            ],
            self::GSC_PAGES => [
                'page_url' => ['aliases' => ['top pages', 'page', 'url', 'landing page'], 'required' => true],
                'clicks' => ['required' => true],
                'impressions' => ['required' => true],
                'ctr' => [],
                'average_position' => ['aliases' => ['position', 'avg position']],
            ],
            self::GA4_COUNTRIES => [
                'country' => ['aliases' => ['country name'], 'required' => true],
                'active_users' => ['aliases' => ['users']],
                'new_users' => [],
                'sessions' => [],
                'engaged_sessions' => [],
                'engagement_rate' => [],
                'event_count' => ['aliases' => ['events']],
                'key_events' => ['aliases' => ['conversions']],
            ],
            default => throw new InvalidArgumentException("Unknown analytics detail sheet [{$this->kind}]."),
        };
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        $byPeriod = [];

        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, ...array_keys(array_filter($this->fields(), fn (array $f): bool => $f['required'] ?? false)));

                $project = $this->project($context, $row);
                $period = $context->values->period($this->value($row, 'period'));
                $key = $project->getKey().'|'.sprintf('%04d-%02d', $period->year, $period->month);

                $byPeriod[$key]['project'] = $project;
                $byPeriod[$key]['period'] = $period;
                $byPeriod[$key]['first'] ??= $rowNumber;
                $byPeriod[$key]['rows'][$rowNumber] = $this->payload($context, $project, $row);
            } catch (InvalidArgumentException $exception) {
                $context->tally('analytics', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'analytics', $exception->getMessage(), $row);
            }
        }

        foreach ($byPeriod as $entry) {
            $cycle = $this->cycle($context, $entry['project'], $entry['period'], $sheet, $entry['first']);
            $count = count($entry['rows']);

            if ($cycle === null) {
                $context->tally('analytics', MigrationOutcome::CONFLICT);

                continue;
            }

            $existing = $this->existing($cycle);

            if ($existing !== []) {
                $same = $this->identityKeys(array_values($entry['rows'])) === $existing;
                $context->tally('analytics', $same ? MigrationOutcome::SKIP : MigrationOutcome::CONFLICT);

                if (! $same) {
                    $context->warning($sheet->name, $entry['first'], 'analytics', sprintf('%s of "%s" already has %s rows that differ from the %d legacy row(s); the month\'s dataset was NOT replaced.', $cycle->periodLabel(), $entry['project']->name, $this->kind, $count));
                }

                continue;
            }

            try {
                $this->save($cycle, array_values($entry['rows']), $context);
                $context->tally('analytics', MigrationOutcome::CREATE);
                $context->info($sheet->name, $entry['first'], 'analytics', sprintf('%s of "%s": %d %s row(s) migrated.', $cycle->periodLabel(), $entry['project']->name, $count, $this->kind));
            } catch (InvalidArgumentException $exception) {
                $context->tally('analytics', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $entry['first'], 'analytics', sprintf('%s of "%s": %s', $cycle->periodLabel(), $entry['project']->name, $exception->getMessage()));
            }
        }
    }

    protected function payload(MigrationContext $context, $project, array $row): array
    {
        $percent = fn (string $field): ?string => $this->blank($row, $field) ? null : rtrim($this->value($row, $field), '%');

        return match ($this->kind) {
            self::GSC_QUERIES => [
                'query' => $this->value($row, 'query'),
                'clicks' => $this->value($row, 'clicks'),
                'impressions' => $this->value($row, 'impressions'),
                'ctr' => $percent('ctr'),
                'average_position' => $this->optional($row, 'average_position'),
            ],
            self::GSC_PAGES => [
                'page_url' => $this->value($row, 'page_url'),
                'page_id' => Page::query()->where('project_id', $project->getKey())->where('url', $this->value($row, 'page_url'))->value('id'),
                'clicks' => $this->value($row, 'clicks'),
                'impressions' => $this->value($row, 'impressions'),
                'ctr' => $percent('ctr'),
                'average_position' => $this->optional($row, 'average_position'),
            ],
            default => [
                'country' => $this->value($row, 'country'),
                'active_users' => $this->optional($row, 'active_users'),
                'new_users' => $this->optional($row, 'new_users'),
                'sessions' => $this->optional($row, 'sessions'),
                'engaged_sessions' => $this->optional($row, 'engaged_sessions'),
                'engagement_rate' => $percent('engagement_rate'),
                'event_count' => $this->optional($row, 'event_count'),
                'key_events' => $this->optional($row, 'key_events'),
            ],
        };
    }

    /**
     * @return list<string> sorted identity keys of the month's current rows
     */
    protected function existing(MonthlyCycle $cycle): array
    {
        $keys = match ($this->kind) {
            self::GSC_QUERIES => $cycle->gscQueryMetrics()->pluck('query')->map(fn ($q): string => $this->guard->identityKey((string) $q))->all(),
            self::GSC_PAGES => $cycle->gscPageMetrics()->pluck('page_url')->map(fn ($u): string => $this->guard->identityKey((string) $u))->all(),
            default => $cycle->ga4CountryMetrics()->pluck('country')->map(fn ($c): string => $this->guard->identityKey((string) $c))->all(),
        };

        sort($keys);

        return $keys;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    protected function identityKeys(array $rows): array
    {
        $keys = array_map(fn (array $r): string => $this->guard->identityKey(match ($this->kind) {
            self::GSC_QUERIES => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $r['query']) ?? '')),
            self::GSC_PAGES => (string) $r['page_url'],
            default => trim(preg_replace('/\s+/u', ' ', (string) $r['country']) ?? ''),
        }), $rows);

        sort($keys);

        return array_values(array_unique($keys));
    }

    protected function save(MonthlyCycle $cycle, array $rows, MigrationContext $context): void
    {
        match ($this->kind) {
            self::GSC_QUERIES => $this->saveQueries->handle($cycle, $rows, $context->actor),
            self::GSC_PAGES => $this->savePages->handle($cycle, $rows, $context->actor),
            default => $this->saveCountries->handle($cycle, $rows, $context->actor),
        };
    }
}
