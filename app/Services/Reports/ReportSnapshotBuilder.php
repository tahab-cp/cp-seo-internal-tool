<?php

namespace App\Services\Reports;

use App\Enums\MonthlyNoteType;
use App\Enums\ReportSectionKey;
use App\Enums\ReportStatus;
use App\Models\AuthorityMetric;
use App\Models\Backlink;
use App\Models\Ga4CountryMetric;
use App\Models\Ga4MonthlyMetric;
use App\Models\GscMonthlyMetric;
use App\Models\GscPageMetric;
use App\Models\GscQueryMetric;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\MonthlyCycleTarget;
use App\Models\MonthlyNote;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportSection;
use App\Models\User;
use App\Services\MonthlyCycles\TargetProgressService;
use App\Services\Rankings\RankingMovementService;
use App\Support\Reports\ReportReadiness;
use App\Support\Targets\TargetProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the plain-array representation of a monthly report at one moment:
 * everything the template needs, read from the source tables in a
 * deterministic order, with targets from the cycle's snapshot and the
 * section configuration from the report's own snapshot.
 *
 * For Draft / Ready previews the result is transient. On finalization it
 * is stored in monthly_reports.snapshot_json and becomes the only source
 * for rendering that report. Internal review notes are never included.
 */
class ReportSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        protected ReportReadinessService $readiness,
        protected TargetProgressService $targets,
        protected RankingMovementService $rankings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(MonthlyReport $report, ?User $finalizer = null, ?CarbonInterface $finalizedAt = null): array
    {
        $cycle = $report->monthlyCycle;
        $project = $cycle->project;
        $client = $project->client;
        $period = $cycle->period();
        $readiness = $this->readiness->evaluate($report);
        $notes = $this->notes($cycle);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'finalized_at' => $finalizedAt?->toIso8601String(),
            'finalized_by' => $finalizer ? ['id' => $finalizer->getKey(), 'name' => $finalizer->name] : null,
            'report' => [
                'id' => $report->getKey(),
                // A snapshot built for finalization describes the FINAL report.
                'status' => $finalizedAt !== null ? ReportStatus::Final->value : $report->status->value,
                'executive_summary' => $report->executive_summary,
                'readiness' => [
                    'ready' => $readiness->isReady(),
                    'percentage' => $readiness->percentage(),
                    'required_count' => $readiness->requiredCount(),
                    'completed_required_count' => $readiness->completedRequiredCount(),
                ],
            ],
            'client' => [
                'id' => $client?->getKey(),
                'name' => $client?->name,
                'company_name' => $client?->company_name,
                'contact_name' => $client?->contact_name,
            ],
            'project' => [
                'id' => $project->getKey(),
                'name' => $project->name,
                'website_url' => $project->website_url,
                'target_location' => $project->target_location,
                'status' => $project->status->value,
                'package' => $project->package ? ['id' => $project->package->getKey(), 'name' => $project->package->name] : null,
            ],
            'period' => [
                'year' => $period->year,
                'month' => $period->month,
                'label' => $period->label(),
                'starts_on' => $period->startOfMonth()->toDateString(),
                'ends_on' => $period->startOfMonth()->endOfMonth()->toDateString(),
                'cycle_id' => $cycle->getKey(),
                'cycle_status' => $cycle->status->value,
            ],
            'targets' => $this->targets($cycle),
            'notes' => $notes,
            'sections' => $report->sections()
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn (MonthlyReportSection $section): array => $this->section($section, $cycle, $report, $readiness, $notes))
                ->values()
                ->all(),
        ];
    }

    /**
     * Keys that legitimately differ between two builds of the same data.
     *
     * @var list<string>
     */
    public const VOLATILE_KEYS = ['generated_at', 'finalized_at', 'finalized_by'];

    /**
     * A deterministic fingerprint of the report-relevant source data in a
     * snapshot, ignoring volatile values (timestamps, finalizer, status).
     * Two builds from unchanged source data share a fingerprint; any
     * change to metrics, notes, rankings, backlinks, narrative, targets or
     * section configuration changes it.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function fingerprint(array $snapshot): string
    {
        foreach (self::VOLATILE_KEYS as $key) {
            unset($snapshot[$key]);
        }

        unset($snapshot['report']['status']);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * The cycle's snapshotted targets (never today's package) with live actuals.
     *
     * @return list<array<string, mixed>>
     */
    protected function targets(MonthlyCycle $cycle): array
    {
        return $cycle->targets()
            ->orderBy('id')
            ->get()
            ->map(function (MonthlyCycleTarget $target) use ($cycle): array {
                $progress = $this->targets->progressFor($cycle, $target->target_key);

                return [
                    'key' => $target->target_key,
                    'label' => $target->label,
                    'target' => $target->target_value,
                    'actual' => $progress->actual,
                    'percentage' => $progress->percentage(),
                    'remaining' => $progress->remaining(),
                    'over_target' => $progress->isOverTarget(),
                    'display' => $progress->format(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<array{id: int, type: string, title: ?string, body: string}>>
     */
    protected function notes(MonthlyCycle $cycle): array
    {
        $grouped = [];

        foreach (MonthlyNoteType::cases() as $type) {
            $grouped[$type->value] = [];
        }

        $cycle->monthlyNotes()->ordered()->get()->each(function (MonthlyNote $note) use (&$grouped): void {
            $grouped[$note->type->value][] = [
                'id' => $note->getKey(),
                'type' => $note->type->value,
                'title' => $note->title,
                'body' => $note->body,
            ];
        });

        return $grouped;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $notes
     * @return array<string, mixed>
     */
    protected function section(MonthlyReportSection $section, MonthlyCycle $cycle, MonthlyReport $report, ReportReadiness $readiness, array $notes): array
    {
        $key = $section->section_key;
        $live = $readiness->section($key->value);

        return [
            'key' => $key->value,
            'title' => $section->title,
            'enabled' => $section->is_enabled,
            'required' => $section->is_required,
            'sort_order' => $section->sort_order,
            'custom_text' => $section->custom_text,
            'complete' => $live?->complete ?? false,
            'data' => $section->is_enabled ? $this->sectionData($key, $cycle, $report, $notes) : [],
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $notes
     * @return array<string, mixed>
     */
    protected function sectionData(ReportSectionKey $key, MonthlyCycle $cycle, MonthlyReport $report, array $notes): array
    {
        return match ($key) {
            ReportSectionKey::ExecutiveSummary => [
                'summary' => $report->executive_summary,
                'wins' => $notes[MonthlyNoteType::Win->value],
                'challenges' => $notes[MonthlyNoteType::Challenge->value],
                'observations' => $notes[MonthlyNoteType::Observation->value],
            ],
            ReportSectionKey::SiteAuthority => $this->authority($cycle),
            ReportSectionKey::OrganicSearch => $this->gscSummary($cycle),
            ReportSectionKey::WebsiteTraffic => $this->ga4Summary($cycle),
            ReportSectionKey::TopKeywords => ['queries' => $this->gscQueries($cycle)],
            ReportSectionKey::LandingPages => ['pages' => $this->gscPages($cycle)],
            ReportSectionKey::Rankings => $this->rankings($cycle),
            ReportSectionKey::AudienceCountry => ['countries' => $this->ga4Countries($cycle)],
            ReportSectionKey::Backlinks => $this->backlinks($cycle),
            ReportSectionKey::Recommendations => [
                'recommendations' => $notes[MonthlyNoteType::Recommendation->value],
                'next_month_focus' => $notes[MonthlyNoteType::NextMonthFocus->value],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function authority(MonthlyCycle $cycle): array
    {
        /** @var AuthorityMetric|null $metric */
        $metric = $cycle->authorityMetric()->first();

        return [
            'available' => $metric !== null,
            'moz_domain_authority' => $metric?->moz_domain_authority,
            'moz_linking_root_domains' => $metric?->moz_linking_root_domains,
            'ahrefs_domain_rating' => self::decimal($metric?->ahrefs_domain_rating),
            'ahrefs_url_rating' => self::decimal($metric?->ahrefs_url_rating),
            'backlinks_count' => $metric?->backlinks_count,
            'referring_domains_count' => $metric?->referring_domains_count,
            'notes' => $metric?->notes,
            'source' => $metric?->source->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function gscSummary(MonthlyCycle $cycle): array
    {
        /** @var GscMonthlyMetric|null $metric */
        $metric = $cycle->gscMonthlyMetric()->first();

        return [
            'available' => $metric !== null,
            'clicks' => $metric?->clicks,
            'impressions' => $metric?->impressions,
            'ctr' => self::decimal($metric?->ctr),
            'average_position' => self::decimal($metric?->average_position),
            'source' => $metric?->source->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function ga4Summary(MonthlyCycle $cycle): array
    {
        /** @var Ga4MonthlyMetric|null $metric */
        $metric = $cycle->ga4MonthlyMetric()->first();

        return [
            'available' => $metric !== null,
            'active_users' => $metric?->active_users,
            'new_users' => $metric?->new_users,
            'sessions' => $metric?->sessions,
            'organic_sessions' => $metric?->organic_sessions,
            'engaged_sessions' => $metric?->engaged_sessions,
            'engagement_rate' => self::decimal($metric?->engagement_rate),
            'average_engagement_time_seconds' => $metric?->average_engagement_time_seconds,
            'event_count' => $metric?->event_count,
            'key_events' => $metric?->key_events,
            'source' => $metric?->source->value,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function gscQueries(MonthlyCycle $cycle): array
    {
        return $cycle->gscQueryMetrics()
            ->orderByDesc('clicks')->orderByDesc('impressions')->orderBy('query')->orderBy('id')
            ->get()
            ->map(fn (GscQueryMetric $row): array => [
                'query' => $row->query,
                'clicks' => $row->clicks,
                'impressions' => $row->impressions,
                'ctr' => self::decimal($row->ctr),
                'average_position' => self::decimal($row->average_position),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function gscPages(MonthlyCycle $cycle): array
    {
        return $cycle->gscPageMetrics()
            ->with('page')
            ->orderByDesc('clicks')->orderByDesc('impressions')->orderBy('page_url')->orderBy('id')
            ->get()
            ->map(fn (GscPageMetric $row): array => [
                'page_url' => $row->page_url,
                'page_title' => $row->page?->title,
                'clicks' => $row->clicks,
                'impressions' => $row->impressions,
                'ctr' => self::decimal($row->ctr),
                'average_position' => self::decimal($row->average_position),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function ga4Countries(MonthlyCycle $cycle): array
    {
        return $cycle->ga4CountryMetrics()
            ->orderByDesc('active_users')->orderByDesc('sessions')->orderBy('country')->orderBy('id')
            ->get()
            ->map(fn (Ga4CountryMetric $row): array => [
                'country' => $row->country,
                'active_users' => $row->active_users,
                'new_users' => $row->new_users,
                'sessions' => $row->sessions,
                'engaged_sessions' => $row->engaged_sessions,
                'engagement_rate' => self::decimal($row->engagement_rate),
                'event_count' => $row->event_count,
                'key_events' => $row->key_events,
            ])
            ->values()
            ->all();
    }

    /**
     * Active tracked keywords with their month-start / latest positions and
     * derived movement, plus a movement summary.
     *
     * @return array<string, mixed>
     */
    protected function rankings(MonthlyCycle $cycle): array
    {
        $summary = ['improved' => 0, 'declined' => 0, 'unchanged' => 0, 'entered' => 0, 'dropped' => 0, 'not_ranking' => 0, 'no_comparison' => 0];

        $keywords = Keyword::query()
            ->where('project_id', $cycle->project_id)
            ->active()
            ->orderBy('keyword')->orderBy('location')->orderBy('id')
            ->get()
            ->map(function (Keyword $keyword) use ($cycle, &$summary): array {
                $monthly = $this->rankings->monthlySummary($keyword, $cycle);
                $movement = $monthly->movement();

                if ($movement === null) {
                    $summary['no_comparison']++;
                } else {
                    $summary[$movement->direction]++;
                }

                return [
                    'keyword' => $keyword->keyword,
                    'location' => $keyword->location,
                    'role' => $keyword->keyword_role?->value,
                    'intent' => $keyword->search_intent?->value,
                    'search_volume' => $keyword->search_volume,
                    'month_start_position' => $monthly->monthStartPosition(),
                    'latest_position' => $monthly->latestPosition(),
                    'latest_checked_at' => $monthly->latest?->checked_at?->toIso8601String(),
                    'snapshot_count' => $monthly->snapshotCount,
                    'movement' => [
                        'direction' => $movement?->direction,
                        'change' => $movement?->absoluteChange,
                        'label' => $monthly->movementLabel(),
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'keywords' => $keywords,
            'summary' => $summary,
            'tracked_count' => count($keywords),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function backlinks(MonthlyCycle $cycle): array
    {
        $links = $cycle->backlinks()
            ->orderBy('published_date')->orderBy('id')
            ->get();

        $breakdown = [];

        foreach ($this->targets->liveBacklinkTypeBreakdown($cycle) as $type => $count) {
            $breakdown[] = ['type' => $type, 'count' => $count];
        }

        return [
            'progress' => [
                'backlinks' => $this->progressArray($this->targets->backlinks($cycle)),
                'guest_posts' => $this->progressArray($this->targets->guestPosts($cycle)),
            ],
            'totals' => [
                'recorded' => $links->count(),
                'live' => $links->filter(fn (Backlink $link): bool => $link->status->value === 'live')->count(),
            ],
            'live_type_breakdown' => $breakdown,
            'links' => $links->map(fn (Backlink $link): array => [
                'published_date' => $link->published_date?->toDateString(),
                'published_url' => $link->published_url,
                'anchor_text' => $link->anchor_text,
                'target_url' => $link->target_url,
                'type' => $link->type->value,
                'status' => $link->status->value,
                'domain_authority' => $link->domain_authority,
                'domain_rating' => $link->domain_rating,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function progressArray(TargetProgress $progress): array
    {
        return $progress->toArray() + ['display' => $progress->format(), 'over_target' => $progress->isOverTarget()];
    }

    protected static function decimal(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @return Collection<int, MonthlyNote>
     */
    protected function notesOfType(MonthlyCycle $cycle, MonthlyNoteType ...$types): Collection
    {
        return $cycle->monthlyNotes()->ofType(...$types)->ordered()->get();
    }
}
