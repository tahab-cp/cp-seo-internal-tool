<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\ImportBatch;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'monthly_cycle_id' => null,
            'import_type' => ImportType::Keywords,
            'original_filename' => 'keywords.csv',
            'stored_file_path' => null,
            'mapping_json' => null,
            'status' => ImportStatus::Uploaded,
            'total_rows' => 0,
            'valid_rows' => 0,
            'imported_rows' => 0,
            'failed_rows' => 0,
            'created_by' => User::factory()->superAdmin(),
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (): array => ['project_id' => $project->getKey()]);
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (): array => ['project_id' => $cycle->project_id, 'monthly_cycle_id' => $cycle->getKey()]);
    }

    public function ofType(ImportType $type): static
    {
        return $this->state(fn (): array => ['import_type' => $type]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (): array => ['created_by' => $user->getKey()]);
    }

    public function completed(int $rows = 3): static
    {
        return $this->state(fn (): array => [
            'status' => ImportStatus::Completed,
            'total_rows' => $rows,
            'valid_rows' => $rows,
            'imported_rows' => $rows,
            'failed_rows' => 0,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
