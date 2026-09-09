<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Content\CreateContentItemAction;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Models\Keyword;
use App\Support\Keywords\KeywordNormalizer;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * "Content" sheet: content planning rows (never article bodies). Published
 * items need a month (from the period column or the published date), a
 * published date and a URL, as the domain requires; only published Blogs
 * count towards the blogs target, under the existing rules.
 */
class LegacyContentMapper extends LegacySheetMapper
{
    public function __construct(
        protected CreateContentItemAction $createContent,
    ) {}

    public function sheet(): string
    {
        return 'Content';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'title' => ['aliases' => ['content title', 'headline', 'topic'], 'required' => true],
            'type' => ['aliases' => ['content type'], 'required' => true],
            'status' => ['aliases' => ['content status', 'workflow status'], 'required' => true],
            'period' => ['aliases' => ['month', 'reporting month']],
            'target_keyword' => ['aliases' => ['keyword', 'focus keyword']],
            'planned_date' => ['aliases' => ['planned', 'planned publish date', 'due']],
            'published_at' => ['aliases' => ['published', 'published date', 'live date']],
            'published_url' => ['aliases' => ['url', 'live url']],
            'assignee' => ['aliases' => ['writer', 'assigned to', 'owner']],
            'notes' => ['aliases' => ['note', 'comments']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'title', 'type', 'status');

                $project = $this->project($context, $row);
                $type = $context->values->enum('content_type', $this->value($row, 'type'), ContentType::class);
                $status = $context->values->enum('content_status', $this->value($row, 'status'), ContentStatus::class);
                $publishedAt = $this->blank($row, 'published_at') ? null : $context->values->dateTime($this->value($row, 'published_at'));
                $plannedDate = $this->blank($row, 'planned_date') ? null : $context->values->date($this->value($row, 'planned_date'));

                $fingerprint = $context->ledger::fingerprint(['content', $project->getKey(), $this->value($row, 'title'), $type->value]);

                if (! $this->shouldCreate($context, $sheet, $rowNumber, 'content_items', $fingerprint)) {
                    continue;
                }

                $hasPeriod = ($this->has('period') && ! $this->blank($row, 'period')) || $publishedAt !== null || $plannedDate !== null;
                $cycle = null;

                if ($hasPeriod) {
                    $cycle = $this->cycle($context, $project, $this->period($context, $row, $publishedAt ?? $plannedDate), $sheet, $rowNumber);

                    if ($cycle === null) {
                        $context->tally('content_items', MigrationOutcome::CONFLICT);

                        continue;
                    }
                }

                $assignee = null;

                if (! $this->blank($row, 'assignee')) {
                    $assignee = $context->values->user($this->value($row, 'assignee'));

                    if ($assignee === null || ! Gate::forUser($assignee)->allows('view', $project)) {
                        $context->warning($sheet->name, $rowNumber, 'content_item', sprintf('Assignee "%s" does not resolve to a user with access to "%s"; left unassigned.', $this->value($row, 'assignee'), $project->name));
                        $assignee = null;
                    }
                }

                $keyword = null;

                if (! $this->blank($row, 'target_keyword')) {
                    $keyword = Keyword::withTrashed()->where('project_id', $project->getKey())
                        ->where('keyword_normalized', KeywordNormalizer::keyword($this->value($row, 'target_keyword')))
                        ->orderBy('id')->first();

                    if ($keyword === null) {
                        $context->warning($sheet->name, $rowNumber, 'content_item', sprintf('Target keyword "%s" is not tracked in "%s"; content migrated without a target keyword.', $this->value($row, 'target_keyword'), $project->name));
                    }
                }

                $item = $this->createContent->handle($project, [
                    'monthly_cycle_id' => $cycle?->getKey(),
                    'title' => $this->value($row, 'title'),
                    'content_type' => $type,
                    'status' => $status,
                    'planned_publish_date' => $plannedDate,
                    'published_at' => $publishedAt,
                    'published_url' => $this->optional($row, 'published_url'),
                    'target_keyword_id' => $keyword?->getKey(),
                    'assigned_user_id' => $assignee?->getKey(),
                    'notes' => $this->optional($row, 'notes'),
                ]);

                $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'content_item', (int) $item->getKey());
                $context->tally('content_items', MigrationOutcome::CREATE);
            } catch (InvalidArgumentException $exception) {
                $context->tally('content_items', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'content_item', $exception->getMessage(), $row);
            }
        }
    }
}
