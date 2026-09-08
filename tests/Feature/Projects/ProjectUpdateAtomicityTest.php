<?php

namespace Tests\Feature\Projects;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\UnknownTargetKeyException;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Client;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UpdateProjectAction is the single transaction boundary for a project
 * update: fields, team, package change (clearing stale overrides) and new
 * overrides all succeed or roll back together. CreateProjectAction does
 * the same for creation.
 */
class ProjectUpdateAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected Package $old;

    protected Package $new;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->old = Package::factory()->withTargets([
            ['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 50],
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 8],
        ])->create(['name' => 'Old']);

        $this->new = Package::factory()->withTargets([
            ['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 12],
            ['target_key' => 'guest_posts', 'label' => 'Guest Posts', 'target_value' => 4],
        ])->create(['name' => 'New']);

        $this->project = Project::factory()->withPackage($this->old)->create(['name' => 'Original']);
        $this->project->targetOverrides()->create(['target_key' => 'backlinks', 'label' => 'Backlinks', 'target_value' => 40]);
    }

    public function test_changing_package_and_submitting_valid_overrides_in_one_save_produces_the_expected_state(): void
    {
        app(UpdateProjectAction::class)->handle(
            $this->project,
            ['name' => 'Renamed', 'package_id' => $this->new->id],
            null,
            ['guest_posts' => 3],
        );

        $this->project->refresh();

        $this->assertSame('Renamed', $this->project->name);
        $this->assertTrue($this->project->package->is($this->new));
        $this->assertSame(['guest_posts' => 3], $this->project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_an_invalid_override_during_a_package_change_rolls_back_the_whole_update(): void
    {
        // "backlinks" belongs to the old package only, so it is invalid for the new one.
        try {
            app(UpdateProjectAction::class)->handle(
                $this->project,
                ['name' => 'Renamed', 'package_id' => $this->new->id],
                null,
                ['backlinks' => 30],
            );
            $this->fail('Expected UnknownTargetKeyException.');
        } catch (UnknownTargetKeyException) {
            $this->addToAssertionCount(1);
        }

        $this->assertPreviousStateUnchanged();

        // A malformed value fails the same way.
        try {
            app(UpdateProjectAction::class)->handle(
                $this->project->fresh(),
                ['name' => 'Renamed', 'package_id' => $this->new->id],
                null,
                ['blogs' => -1],
            );
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertPreviousStateUnchanged();
    }

    public function test_a_failing_team_assignment_rolls_back_a_package_change_and_field_edits(): void
    {
        $inactive = User::factory()->seoExecutive()->inactive()->create();

        try {
            app(UpdateProjectAction::class)->handle(
                $this->project,
                ['name' => 'Renamed', 'package_id' => $this->new->id],
                [$inactive->id],
                ['guest_posts' => 3],
            );
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertPreviousStateUnchanged();
        $this->assertSame(0, $this->project->teamMembers()->count());
    }

    public function test_changing_package_without_new_overrides_clears_the_old_stale_overrides(): void
    {
        // Overrides not supplied at all (null).
        app(UpdateProjectAction::class)->handle($this->project, ['package_id' => $this->new->id]);

        $this->assertTrue($this->project->fresh()->package->is($this->new));
        $this->assertSame(0, $this->project->targetOverrides()->count());

        // Overrides supplied as an empty list.
        $this->project->refresh();
        $this->project->targetOverrides()->create(['target_key' => 'blogs', 'label' => 'Blogs', 'target_value' => 5]);

        app(UpdateProjectAction::class)->handle($this->project, ['package_id' => $this->old->id], null, []);

        $this->assertTrue($this->project->fresh()->package->is($this->old));
        $this->assertSame(0, $this->project->targetOverrides()->count());
    }

    public function test_the_edit_page_delegates_the_whole_save_to_the_action(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        // A crafted request pairing a package change with an override the new
        // package does not define is rejected by validation before anything runs.
        Livewire::test(EditProject::class, ['record' => $this->project->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed',
                'package_id' => $this->new->id,
                'target_overrides' => [
                    ['target_key' => 'backlinks', 'target_value' => 30],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertPreviousStateUnchanged();

        // The valid version of the same save lands as one unit.
        Livewire::test(EditProject::class, ['record' => $this->project->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed',
                'package_id' => $this->new->id,
                'target_overrides' => [
                    ['target_key' => 'guest_posts', 'target_value' => 3],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->project->refresh();

        $this->assertSame('Renamed', $this->project->name);
        $this->assertTrue($this->project->package->is($this->new));
        $this->assertSame(['guest_posts' => 3], $this->project->targetOverrides()->pluck('target_value', 'target_key')->all());
    }

    public function test_create_project_action_rolls_back_creation_when_initial_overrides_are_invalid(): void
    {
        $client = Client::factory()->create();
        $member = User::factory()->seoExecutive()->create();

        try {
            app(CreateProjectAction::class)->handle([
                'client_id' => $client->id,
                'name' => 'Never created',
                'website_url' => 'https://never.example',
                'package_id' => $this->new->id,
            ], [$member->id], ['backlinks' => 10]);
            $this->fail('Expected UnknownTargetKeyException.');
        } catch (UnknownTargetKeyException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('projects', ['name' => 'Never created']);
        $this->assertSame(0, $member->teamProjects()->count());
        $this->assertDatabaseCount('project_target_overrides', 1);
    }

    protected function assertPreviousStateUnchanged(): void
    {
        $fresh = $this->project->fresh();

        $this->assertSame('Original', $fresh->name);
        $this->assertSame($this->old->id, $fresh->package_id);
        $this->assertSame(['backlinks' => 40], $fresh->targetOverrides()->pluck('target_value', 'target_key')->all());
    }
}
