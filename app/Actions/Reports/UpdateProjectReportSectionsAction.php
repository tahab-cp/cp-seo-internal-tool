<?php

namespace App\Actions\Reports;

use App\Enums\ReportSectionKey;
use App\Models\Project;
use App\Models\ProjectReportSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateProjectReportSectionsAction
{
    public const TITLE_MAX = 120;

    public function __construct(
        protected EnsureProjectReportSectionsAction $ensureSections,
    ) {}

    /**
     * Update the project's report template: title, enabled, required and
     * order per known section. Keys are validated against the enum, so
     * the UI can never introduce arbitrary sections. Sort order follows
     * the order of the submitted rows unless a sort_order is given.
     *
     * Affects future reports only; existing monthly reports keep their
     * snapshot.
     *
     * @param  list<array{section_key: string, title?: mixed, is_enabled?: mixed, is_required?: mixed, sort_order?: mixed}>  $rows
     * @return Collection<int, ProjectReportSection>
     */
    public function handle(Project $project, array $rows): Collection
    {
        return DB::transaction(function () use ($project, $rows): Collection {
            $this->ensureSections->handle($project);

            $sections = $project->reportSections()->get()->keyBy(fn ($section): string => $section->section_key->value);
            $seen = [];

            foreach (array_values($rows) as $index => $row) {
                $key = ReportSectionKey::tryFrom((string) ($row['section_key'] ?? ''))
                    ?? throw new InvalidArgumentException(sprintf('Unknown report section [%s].', $row['section_key'] ?? ''));

                if (in_array($key->value, $seen, true)) {
                    throw new InvalidArgumentException("Report section [{$key->value}] appears more than once.");
                }

                $seen[] = $key->value;

                $section = $sections->get($key->value);

                if (array_key_exists('title', $row)) {
                    $title = trim((string) $row['title']);

                    if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
                        throw new InvalidArgumentException("The {$key->defaultTitle()} section needs a title of at most ".self::TITLE_MAX.' characters.');
                    }

                    $section->title = $title;
                }

                if (array_key_exists('is_enabled', $row)) {
                    $section->is_enabled = (bool) $row['is_enabled'];
                }

                if (array_key_exists('is_required', $row)) {
                    $section->is_required = (bool) $row['is_required'];
                }

                $sortOrder = $row['sort_order'] ?? null;

                if ($sortOrder !== null && $sortOrder !== '') {
                    if (! is_numeric($sortOrder) || (int) $sortOrder != $sortOrder || (int) $sortOrder < 0) {
                        throw new InvalidArgumentException("The sort order for {$key->defaultTitle()} must be a whole number of 0 or more.");
                    }

                    $section->sort_order = (int) $sortOrder;
                } else {
                    $section->sort_order = ($index + 1) * 10;
                }

                $section->save();
            }

            $project->unsetRelation('reportSections');

            return $project->reportSections()->get();
        });
    }
}
