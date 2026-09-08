<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplateItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Bare persistence for tests. Domain rules (cycle/project consistency,
 * assignability, locking) live in the actions, so use those when the
 * rule under test matters.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'monthly_cycle_id' => null,
            'task_template_item_id' => null,
            'assigned_user_id' => null,
            'created_by' => User::factory(),
            'title' => ucfirst(fake()->unique()->words(3, true)),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->optional()->randomElement(['Technical', 'Content', 'Links', 'Reporting']),
            'status' => TaskStatus::Pending,
            'priority' => TaskPriority::Normal,
            'due_date' => null,
            'completed_at' => null,
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->getKey(),
        ]);
    }

    public function forCycle(MonthlyCycle $cycle): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $cycle->project_id,
            'monthly_cycle_id' => $cycle->getKey(),
        ]);
    }

    public function fromTemplateItem(TaskTemplateItem $item): static
    {
        return $this->state(fn (array $attributes) => [
            'task_template_item_id' => $item->getKey(),
            'title' => $item->title,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_user_id' => $user->getKey(),
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->getKey(),
        ]);
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
            'completed_at' => $status === TaskStatus::Completed ? now() : null,
        ]);
    }

    public function completed(): static
    {
        return $this->status(TaskStatus::Completed);
    }

    public function due(CarbonInterface|string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'due_date' => Carbon::parse($date)->toDateString(),
        ]);
    }

    public function overdue(): static
    {
        return $this->due(Carbon::today()->subDays(3));
    }

    public function dueToday(): static
    {
        return $this->due(Carbon::today());
    }
}
