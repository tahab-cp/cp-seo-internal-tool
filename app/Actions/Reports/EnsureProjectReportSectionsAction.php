<?php

namespace App\Actions\Reports;

use App\Enums\ReportSectionKey;
use App\Models\Project;
use App\Models\ProjectReportSection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EnsureProjectReportSectionsAction
{
    /**
     * Give a project the default configuration for every known section it
     * does not have yet. Existing rows (customised titles, flags, order)
     * are never touched, so this is safe to call whenever configuration or
     * reporting is first needed.
     *
     * @return Collection<int, ProjectReportSection>
     */
    public function handle(Project $project): Collection
    {
        return DB::transaction(function () use ($project): Collection {
            $existing = $project->reportSections()
                ->pluck('section_key')
                ->map(fn (ReportSectionKey|string $key): string => $key instanceof ReportSectionKey ? $key->value : $key)
                ->all();

            foreach (ReportSectionKey::defaultDefinitions() as $definition) {
                if (in_array($definition['section_key'], $existing, true)) {
                    continue;
                }

                $project->reportSections()->create($definition);
            }

            $project->unsetRelation('reportSections');

            return $project->reportSections()->get();
        });
    }
}
