<?php

namespace Tests\Feature\MonthlyCycles;

use App\Actions\Projects\CreateProjectAction;
use App\Enums\ProjectStatus;
use App\Exceptions\UnknownTargetKeyException;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Models\Client;
use App\Models\MonthlyCycle;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCreationCycleTest extends TestCase
{
    use RefreshDatabase;

    protected Package $growth;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 08:00:00');

        $this->growth = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create();
    }

    public function test_a_new_active_project_receives_the_current_cycle_with_its_configured_overrides(): void
    {
        $project = app(CreateProjectAction::class)->handle([
            'client_id' => Client::factory()->create()->id,
            'name' => 'Casa Botanica',
            'website_url' => 'https://casabotanica.example',
            'status' => ProjectStatus::Active->value,
            'package_id' => $this->growth->id,
        ], [], ['backlinks' => 40]);

        $cycle = $project->monthlyCycles()->first();

        $this->assertNotNull($cycle);
        $this->assertSame(2026, $cycle->year);
        $this->assertSame(9, $cycle->month);
        // The snapshot reflects the final package + override configuration.
        $this->assertSame(['backlinks' => 40, 'blogs' => 8], $cycle->targets()->orderBy('id')->pluck('target_value', 'target_key')->all());
    }

    public function test_the_same_holds_when_created_through_the_filament_form(): void
    {
        $this->actingAs(User::factory()->seoManager()->create());

        Livewire::test(CreateProject::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Form Project',
                'website_url' => 'https://form.example',
                'status' => ProjectStatus::Active->value,
                'package_id' => $this->growth->id,
                'target_overrides' => [
                    ['target_key' => 'blogs', 'target_value' => 6],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $project = Project::query()->where('name', 'Form Project')->firstOrFail();

        $this->assertSame(1, $project->monthlyCycles()->count());
        $this->assertSame(['backlinks' => 50, 'blogs' => 6], $project->monthlyCycles()->first()->targets()->orderBy('id')->pluck('target_value', 'target_key')->all());
    }

    public function test_non_active_projects_do_not_receive_a_cycle_on_creation(): void
    {
        foreach ([ProjectStatus::Onboarding, ProjectStatus::Paused, ProjectStatus::Completed, ProjectStatus::Cancelled] as $status) {
            $project = app(CreateProjectAction::class)->handle([
                'client_id' => Client::factory()->create()->id,
                'name' => "Project {$status->value}",
                'website_url' => "https://{$status->value}.example",
                'status' => $status->value,
                'package_id' => $this->growth->id,
            ]);

            $this->assertSame(0, $project->monthlyCycles()->count(), "A {$status->value} project must not get a cycle.");
        }

        $this->assertSame(0, MonthlyCycle::query()->count());
    }

    public function test_a_failed_creation_rolls_back_the_cycle_together_with_the_project(): void
    {
        try {
            app(CreateProjectAction::class)->handle([
                'client_id' => Client::factory()->create()->id,
                'name' => 'Never created',
                'website_url' => 'https://never.example',
                'status' => ProjectStatus::Active->value,
                'package_id' => $this->growth->id,
            ], [], ['not_a_key' => 1]);
            $this->fail('Expected UnknownTargetKeyException.');
        } catch (UnknownTargetKeyException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('projects', ['name' => 'Never created']);
        $this->assertSame(0, MonthlyCycle::query()->count());
        $this->assertDatabaseCount('monthly_cycle_targets', 0);
    }
}
