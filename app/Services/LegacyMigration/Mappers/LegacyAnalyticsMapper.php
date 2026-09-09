<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Analytics\SaveAuthorityMetricsAction;
use App\Actions\Analytics\SaveGa4MonthlyMetricsAction;
use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Models\MonthlyCycle;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use InvalidArgumentException;

/**
 * "Analytics" sheet: one row per project-month with the Search Console,
 * GA4 and site-authority SUMMARY figures of that month. Percentages keep
 * the human 0-100 convention (8.5 = 8.5%). AuthorityMetric.backlinks_count
 * is the vendor's index count and stays separate from operational
 * Backlinks. An existing summary is a SKIP when equal, a CONFLICT when
 * different; it is never overwritten.
 */
class LegacyAnalyticsMapper extends LegacySheetMapper
{
    protected const GSC = ['clicks' => 'gsc_clicks', 'impressions' => 'gsc_impressions', 'ctr' => 'gsc_ctr', 'average_position' => 'gsc_average_position'];

    protected const GA4 = [
        'active_users' => 'ga4_active_users', 'new_users' => 'ga4_new_users', 'sessions' => 'ga4_sessions', 'organic_sessions' => 'ga4_organic_sessions',
        'engaged_sessions' => 'ga4_engaged_sessions', 'engagement_rate' => 'ga4_engagement_rate', 'average_engagement_time_seconds' => 'ga4_average_engagement_seconds',
        'event_count' => 'ga4_event_count', 'key_events' => 'ga4_key_events',
    ];

    protected const AUTHORITY = [
        'moz_domain_authority' => 'moz_domain_authority', 'moz_linking_root_domains' => 'moz_linking_root_domains', 'ahrefs_domain_rating' => 'ahrefs_domain_rating',
        'ahrefs_url_rating' => 'ahrefs_url_rating', 'backlinks_count' => 'authority_backlinks_count', 'referring_domains_count' => 'referring_domains_count', 'notes' => 'authority_notes',
    ];

    public function __construct(
        protected SaveGscMonthlyMetricsAction $saveGsc,
        protected SaveGa4MonthlyMetricsAction $saveGa4,
        protected SaveAuthorityMetricsAction $saveAuthority,
    ) {}

    public function sheet(): string
    {
        return 'Analytics';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'period' => ['aliases' => ['month', 'reporting month'], 'required' => true],
            'gsc_clicks' => ['aliases' => ['clicks']],
            'gsc_impressions' => ['aliases' => ['impressions']],
            'gsc_ctr' => ['aliases' => ['ctr']],
            'gsc_average_position' => ['aliases' => ['average position', 'avg position']],
            'ga4_active_users' => ['aliases' => ['active users', 'users']],
            'ga4_new_users' => ['aliases' => ['new users']],
            'ga4_sessions' => ['aliases' => ['sessions']],
            'ga4_organic_sessions' => ['aliases' => ['organic sessions', 'organic traffic']],
            'ga4_engaged_sessions' => ['aliases' => ['engaged sessions']],
            'ga4_engagement_rate' => ['aliases' => ['engagement rate']],
            'ga4_average_engagement_seconds' => ['aliases' => ['average engagement time', 'avg engagement time (s)']],
            'ga4_event_count' => ['aliases' => ['event count', 'events']],
            'ga4_key_events' => ['aliases' => ['key events', 'conversions']],
            'moz_domain_authority' => ['aliases' => ['da', 'moz da', 'domain authority']],
            'moz_linking_root_domains' => ['aliases' => ['linking root domains']],
            'ahrefs_domain_rating' => ['aliases' => ['dr', 'ahrefs dr', 'domain rating']],
            'ahrefs_url_rating' => ['aliases' => ['ur', 'url rating']],
            'authority_backlinks_count' => ['aliases' => ['backlinks count', 'total backlinks', 'ahrefs backlinks']],
            'referring_domains_count' => ['aliases' => ['referring domains']],
            'authority_notes' => ['aliases' => ['authority note']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'period');

                $project = $this->project($context, $row);
                $cycle = $this->cycle($context, $project, $context->values->period($this->value($row, 'period')), $sheet, $rowNumber);

                if ($cycle === null) {
                    $context->tally('analytics', MigrationOutcome::CONFLICT);

                    continue;
                }

                $this->summary($context, $sheet, $rowNumber, $row, $cycle, self::GSC, 'Search Console summary', $cycle->gscMonthlyMetric()->first(), fn (array $payload) => $this->saveGsc->handle($cycle, $payload, $context->actor));
                $this->summary($context, $sheet, $rowNumber, $row, $cycle, self::GA4, 'GA4 summary', $cycle->ga4MonthlyMetric()->first(), fn (array $payload) => $this->saveGa4->handle($cycle, $payload, $context->actor));
                $this->summary($context, $sheet, $rowNumber, $row, $cycle, self::AUTHORITY, 'authority metrics', $cycle->authorityMetric()->first(), fn (array $payload) => $this->saveAuthority->handle($cycle, $payload, $context->actor));
            } catch (InvalidArgumentException $exception) {
                $context->tally('analytics', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'analytics', $exception->getMessage(), $row);
            }
        }
    }

    /**
     * @param  array<string, string>  $map  action attribute => sheet field
     */
    protected function summary(MigrationContext $context, LegacySheet $sheet, int $rowNumber, array $row, MonthlyCycle $cycle, array $map, string $label, $existing, callable $save): void
    {
        $payload = [];

        foreach ($map as $attribute => $field) {
            $value = $this->has($field) ? $this->optional($row, $field) : null;

            if ($value !== null) {
                $payload[$attribute] = rtrim($value, '%');
            }
        }

        if ($payload === []) {
            return;
        }

        if ($existing !== null) {
            $same = true;

            foreach ($payload as $attribute => $value) {
                $current = $existing->{$attribute};

                if ($current === null || (is_numeric($current) && is_numeric($value) ? (float) $current !== (float) $value : (string) $current !== (string) $value)) {
                    $same = false;
                }
            }

            $context->tally('analytics', $same ? MigrationOutcome::SKIP : MigrationOutcome::CONFLICT);

            if (! $same) {
                $context->warning($sheet->name, $rowNumber, 'analytics', sprintf('%s of "%s" already has %s with different values; the legacy figures were NOT applied.', $cycle->periodLabel(), $cycle->project->name, $label));
            }

            return;
        }

        $save($payload);
        $context->tally('analytics', MigrationOutcome::CREATE);
    }
}
