<?php

namespace Database\Factories;

use App\Enums\ReportSectionKey;
use App\Models\Project;
use App\Models\ProjectReportSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectReportSection>
 */
class ProjectReportSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ReportSectionKey::Backlinks->defaults() + ['project_id' => Project::factory()];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => ['project_id' => $project->getKey()]);
    }

    public function key(ReportSectionKey $key): static
    {
        return $this->state(fn (array $attributes) => $key->defaults());
    }
}
