<?php

namespace Tests\Feature\Tasks;

use App\Actions\Projects\CreateProjectAction;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCreationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected TaskTemplate $template;

    protected Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->template = TaskTemplate::factory()->withItems()->create();
        $this->package = Package::factory()->withTargets()->create();
    }

    public function test_project_creation_with_onboarding_creates_project_and_tasks_atomically_using_the_final_owner(): void
    {
        $owner = User::factory()->seoExecutive()->create();

        $project = app(CreateProjectAction::class)->handle([
            'client_id' => Client::factory()->create()->id,
            'name' => 'Casa Botanica',
            'website_url' => 'https://casabotanica.example',
            'status' => ProjectStatus::Active->value,
            'package_id' => $this->package->id,
            'primary_seo_user_id' => $owner->id,
            'start_date' => '2026-10-01',
        ], [], ['backlinks' => 40], $this->template, $this->manager);

        $this->assertSame(4, $project->tasks()->count());
        $this->assertSame([$owner->id], $project->tasks()->distinct()->pluck('assigned_user_id')->all());
        $this->assertSame([null], $project->tasks()->distinct()->pluck('monthly_cycle_id')->all());
        $this->assertSame('2026-10-04', $project->tasks()->where('title', 'Configure Google Search Console')->firstOrFail()->due_date->toDateString());
        $this->assertTrue($project->tasks()->get()->every(fn (Task $task): bool => $task->creator->is($this->manager)));
        // The rest of the workflow still ran.
        $this->assertSame(1, $project->monthlyCycles()->count());
        $this->assertSame(['backlinks' => 40], $project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_the_same_works_through_the_filament_create_form(): void
    {
        $this->actingAs($this->manager);
        $owner = User::factory()->seoExecutive()->create();

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Form Project',
                'website_url' => 'https://form.example',
                'status' => ProjectStatus::Onboarding->value,
                'package_id' => $this->package->id,
                'primary_seo_user_id' => $owner->id,
                'generate_onboarding' => true,
                'onboarding_template_id' => $this->template->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('name', 'Form Project')->firstOrFail();

        $this->assertSame(4, $project->tasks()->count());
        $this->assertSame([$owner->id], $project->tasks()->distinct()->pluck('assigned_user_id')->all());
        $this->assertSame(0, $project->monthlyCycles()->count());
    }

    public function test_the_form_requires_an_active_template_when_onboarding_is_enabled_and_skips_it_when_disabled(): void
    {
        $this->actingAs($this->manager);
        $inactive = TaskTemplate::factory()->inactive()->withItems()->create();

        $base = [
            'client_id' => Client::factory()->create()->id,
            'website_url' => 'https://x.example',
            'status' => ProjectStatus::Onboarding->value,
            'package_id' => $this->package->id,
        ];

        Livewire::test(CreateProject::class)
            ->fillForm($base + ['name' => 'Missing template', 'generate_onboarding' => true, 'onboarding_template_id' => null])
            ->call('create')
            ->assertHasFormErrors(['onboarding_template_id' => 'required']);

        Livewire::test(CreateProject::class)
            ->fillForm($base + ['name' => 'Inactive template', 'generate_onboarding' => true, 'onboarding_template_id' => $inactive->id])
            ->call('create')
            ->assertHasFormErrors(['onboarding_template_id']);

        Livewire::test(CreateProject::class)
            ->fillForm($base + ['name' => 'No onboarding', 'generate_onboarding' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Project::query()->where('name', 'No onboarding')->firstOrFail()->tasks()->count());
        $this->assertDatabaseMissing('projects', ['name' => 'Missing template']);
        $this->assertDatabaseMissing('projects', ['name' => 'Inactive template']);
    }

    public function test_failed_onboarding_generation_rolls_back_project_creation(): void
    {
        $inactive = TaskTemplate::factory()->inactive()->withItems()->create();

        try {
            app(CreateProjectAction::class)->handle([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Never created',
                'website_url' => 'https://never.example',
                'status' => ProjectStatus::Active->value,
                'package_id' => $this->package->id,
            ], [], [], $inactive, $this->manager);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('projects', ['name' => 'Never created']);
        $this->assertSame(0, Task::query()->count());
        $this->assertDatabaseCount('monthly_cycles', 0);
    }

    public function test_editing_a_project_never_regenerates_onboarding(): void
    {
        $this->actingAs($this->manager);
        $project = Project::factory()->withPackage($this->package)->create();

        $this->get(ProjectResource::getUrl('edit', ['record' => $project]))
            ->assertOk()
            ->assertDontSee('Generate onboarding checklist');

        $this->assertSame(0, $project->tasks()->count());
    }
}
