<?php

namespace App\Services\LegacyMigration\Mappers;

use App\Actions\Pages\CreatePageAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Models\Page;
use App\Support\LegacyMigration\LegacySheet;
use App\Support\LegacyMigration\MigrationContext;
use App\Support\LegacyMigration\MigrationOutcome;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * "PageOptimizations" sheet: one row per historical optimisation EVENT
 * (page URL, date, which elements changed). A page merely existing never
 * implies an event. Pages are reused by Project + URL or created; the
 * optimisation goes to the cycle of its date. Pages-optimised actuals stay
 * derived from these events.
 */
class LegacyPageOptimizationMapper extends LegacySheetMapper
{
    public function __construct(
        protected CreatePageAction $createPage,
        protected RecordPageOptimizationAction $recordOptimization,
    ) {}

    public function sheet(): string
    {
        return 'PageOptimizations';
    }

    public function fields(): array
    {
        return [
            'project' => ['aliases' => ['website', 'project name'], 'required' => true],
            'page_url' => ['aliases' => ['url', 'page'], 'required' => true],
            'optimized_at' => ['aliases' => ['date', 'optimised on', 'optimized on', 'optimised at'], 'required' => true],
            'period' => ['aliases' => ['month', 'reporting month']],
            'meta_title' => ['aliases' => ['title tag', 'meta title updated']],
            'meta_description' => ['aliases' => ['meta description updated', 'description']],
            'content' => ['aliases' => ['content updated', 'copy']],
            'internal_links' => ['aliases' => ['internal links updated', 'links']],
            'schema' => ['aliases' => ['schema updated', 'structured data']],
            'user' => ['aliases' => ['optimised by', 'optimized by', 'by', 'owner']],
            'notes' => ['aliases' => ['note', 'comments']],
            'title' => ['aliases' => ['page title']],
        ];
    }

    public function migrateGroup(MigrationContext $context, LegacySheet $sheet, string $group, array $rows): void
    {
        foreach ($rows as $rowNumber => $row) {
            try {
                $this->requireValues($row, 'project', 'page_url', 'optimized_at');

                $project = $this->project($context, $row);
                $optimizedAt = $context->values->dateTime($this->value($row, 'optimized_at'));
                $period = $this->period($context, $row, $optimizedAt);

                $flags = [];

                foreach (['meta_title' => 'meta_title_updated', 'meta_description' => 'meta_description_updated', 'content' => 'content_updated', 'internal_links' => 'internal_links_updated', 'schema' => 'schema_updated'] as $field => $flag) {
                    $flags[$flag] = ! $this->blank($row, $field) && $context->values->boolean($this->value($row, $field));
                }

                $fingerprint = $context->ledger::fingerprint(['page_optimization', $project->getKey(), $this->value($row, 'page_url'), $optimizedAt]);

                if (! $this->shouldCreate($context, $sheet, $rowNumber, 'page_optimizations', $fingerprint)) {
                    continue;
                }

                $cycle = $this->cycle($context, $project, $period, $sheet, $rowNumber);

                if ($cycle === null) {
                    $context->tally('page_optimizations', MigrationOutcome::CONFLICT);

                    continue;
                }

                $page = Page::withTrashed()->where('project_id', $project->getKey())->where('url', $this->value($row, 'page_url'))->first();

                if ($page === null) {
                    $page = $this->createPage->handle($project, ['url' => $this->value($row, 'page_url'), 'title' => $this->optional($row, 'title')]);
                    $context->tally('pages', MigrationOutcome::CREATE);
                } else {
                    $context->tally('pages', MigrationOutcome::SKIP);
                }

                $userId = null;

                if (! $this->blank($row, 'user')) {
                    $user = $context->values->user($this->value($row, 'user'));

                    if ($user === null || ! Gate::forUser($user)->allows('view', $project)) {
                        $context->warning($sheet->name, $rowNumber, 'page_optimization', sprintf('User "%s" does not resolve to a user with access to "%s"; event recorded without a user.', $this->value($row, 'user'), $project->name));
                    } else {
                        $userId = $user->getKey();
                    }
                }

                $optimization = $this->recordOptimization->handle($project, $flags + [
                    'page_id' => $page->getKey(),
                    'monthly_cycle_id' => $cycle->getKey(),
                    'optimized_at' => $optimizedAt,
                    'user_id' => $userId,
                    'notes' => $this->optional($row, 'notes'),
                ]);

                $context->ledger->record($sheet->name, $rowNumber, $fingerprint, 'page_optimization', (int) $optimization->getKey());
                $context->tally('page_optimizations', MigrationOutcome::CREATE);
            } catch (InvalidArgumentException $exception) {
                $context->tally('page_optimizations', MigrationOutcome::CONFLICT);
                $context->error($sheet->name, $rowNumber, 'page_optimization', $exception->getMessage(), $row);
            }
        }
    }
}
