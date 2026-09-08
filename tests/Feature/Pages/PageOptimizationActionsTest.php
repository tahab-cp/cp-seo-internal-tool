<?php

namespace Tests\Feature\Pages;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Pages\RecordPageOptimizationAction;
use App\Actions\Pages\UpdatePageAction;
use App\Actions\Pages\UpdatePageOptimizationAction;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Models\MonthlyCycle;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class PageOptimizationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected Page $page;

    protected MonthlyCycle $september;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create(['name' => 'Casa Botanica']);
        $this->page = Page::factory()->forProject($this->project)->create(['url' => 'https://casa.example/services/seo']);
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function record(array $overrides = [], ?User $by = null): PageOptimization
    {
        return app(RecordPageOptimizationAction::class)->handle($this->project, $overrides + [
            'page_id' => $this->page->id,
            'monthly_cycle_id' => $this->september->id,
            'meta_title_updated' => true,
        ], $by ?? $this->manager);
    }

    public function test_an_optimisation_belongs_to_page_project_cycle_and_user(): void
    {
        $optimization = $this->record(['notes' => 'Rewrote title', 'content_updated' => true]);

        $this->assertTrue($optimization->page->is($this->page));
        $this->assertTrue($optimization->project->is($this->project));
        $this->assertTrue($optimization->monthlyCycle->is($this->september));
        $this->assertTrue($optimization->user->is($this->manager));
        $this->assertSame(['Meta title', 'Content'], $optimization->changeLabels());
        $this->assertTrue($this->page->optimizations->contains($optimization));
        $this->assertTrue($this->september->pageOptimizations->contains($optimization));
        $this->assertTrue($this->manager->pageOptimizations->contains($optimization));
        $this->assertSame('2026-09-15 10:00:00', $optimization->optimized_at->toDateTimeString());
    }

    public function test_a_page_from_another_project_is_rejected(): void
    {
        $foreignPage = Page::factory()->create();

        try {
            $this->record(['page_id' => $foreignPage->id]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('does not belong to project "Casa Botanica"', $exception->getMessage());
        }

        $this->assertDatabaseCount('page_optimizations', 0);
    }

    public function test_a_cycle_from_another_project_is_rejected(): void
    {
        $foreignCycle = MonthlyCycle::factory()->create();

        try {
            $this->record(['monthly_cycle_id' => $foreignCycle->id]);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Monthly cycle', $exception->getMessage());
        }

        // Nor may an existing event be moved to one.
        $optimization = $this->record();

        $this->expectException(InvalidArgumentException::class);
        app(UpdatePageOptimizationAction::class)->handle($optimization, ['monthly_cycle_id' => $foreignCycle->id]);
    }

    public function test_the_recording_user_must_be_active_and_able_to_access_the_project(): void
    {
        $inactive = User::factory()->seoManager()->inactive()->create();
        $outsider = User::factory()->seoExecutive()->create();
        $member = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($member);

        try {
            $this->record(['user_id' => $inactive->id]);
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->record([], $outsider);
            $this->fail('Expected UnauthorizedProjectUserException.');
        } catch (UnauthorizedProjectUserException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseCount('page_optimizations', 0);

        $byMember = $this->record([], $member);
        $byAdmin = $this->record(['user_id' => User::factory()->superAdmin()->create()->id]);
        $anonymous = $this->record(['user_id' => null]);

        $this->assertTrue($byMember->user->is($member));
        $this->assertNotNull($byAdmin->user_id);
        $this->assertNull($anonymous->user_id);
    }

    public function test_at_least_one_change_flag_is_required_and_notes_alone_do_not_count(): void
    {
        foreach ([
            ['meta_title_updated' => false],
            ['meta_title_updated' => false, 'notes' => 'Looked at it'],
            ['meta_title_updated' => '0', 'content_updated' => 'false'],
        ] as $attributes) {
            try {
                $this->record($attributes);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('at least one change', $exception->getMessage());
            }
        }

        $this->assertDatabaseCount('page_optimizations', 0);

        $optimization = $this->record(['meta_title_updated' => false, 'schema_updated' => '1']);
        $this->assertSame(['Schema'], $optimization->changeLabels());

        // Editing cannot clear every flag either.
        $this->expectException(InvalidArgumentException::class);
        app(UpdatePageOptimizationAction::class)->handle($optimization, ['schema_updated' => false]);
    }

    public function test_multiple_events_may_exist_for_the_same_page_and_cycle(): void
    {
        $first = $this->record(['optimized_at' => '2026-09-04 09:00:00', 'meta_description_updated' => true]);
        $second = $this->record(['optimized_at' => '2026-09-20 09:00:00', 'meta_title_updated' => false, 'content_updated' => true, 'internal_links_updated' => true]);

        $this->assertSame(2, $this->page->optimizations()->count());
        $this->assertSame([$second->id, $first->id], $this->page->optimizations->pluck('id')->all());
    }

    public function test_events_may_be_created_and_edited_in_open_and_reporting_cycles(): void
    {
        $open = $this->record();
        $this->assertTrue($open->exists);

        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();

        $reporting = $this->record(['content_updated' => true]);
        app(UpdatePageOptimizationAction::class)->handle($reporting, ['notes' => 'Edited in reporting', 'schema_updated' => true]);

        $this->assertSame('Edited in reporting', $reporting->fresh()->notes);
        $this->assertTrue($reporting->fresh()->schema_updated);
        $this->assertTrue($this->manager->can('update', $reporting->fresh()));
    }

    public function test_a_locked_cycle_refuses_new_events_and_freezes_existing_ones_for_everyone(): void
    {
        $existing = $this->record(['notes' => 'Original']);
        $otherPage = Page::factory()->forProject($this->project)->create();
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now(), 'locked_by' => $this->manager->id])->save();
        $existing->refresh();

        $this->assertTrue($existing->isLocked());

        foreach ([$this->manager, User::factory()->superAdmin()->create()] as $user) {
            $this->assertFalse($user->can('update', $existing));
            $this->assertTrue($user->can('view', $existing));
        }

        try {
            $this->record(['page_id' => $otherPage->id]);
            $this->fail('Expected LockedMonthlyCycleException on create.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            ['notes' => 'Changed'],
            ['content_updated' => true],
            ['meta_title_updated' => false, 'schema_updated' => true],
            ['page_id' => $otherPage->id],
            ['monthly_cycle_id' => $october->id],
            ['optimized_at' => '2026-09-01 08:00:00'],
        ] as $attributes) {
            try {
                app(UpdatePageOptimizationAction::class)->handle($existing, $attributes);
                $this->fail('Expected LockedMonthlyCycleException for '.json_encode($attributes));
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        // Nor may an open event be moved into the locked month.
        $openEvent = $this->record(['page_id' => $otherPage->id, 'monthly_cycle_id' => $october->id]);

        try {
            app(UpdatePageOptimizationAction::class)->handle($openEvent, ['monthly_cycle_id' => $this->september->id]);
            $this->fail('Expected LockedMonthlyCycleException on move.');
        } catch (LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $fresh = $existing->fresh();
        $this->assertSame('Original', $fresh->notes);
        $this->assertTrue($fresh->meta_title_updated);
        $this->assertFalse($fresh->content_updated);
        $this->assertSame($this->page->id, $fresh->page_id);
        $this->assertSame($this->september->id, $fresh->monthly_cycle_id);
        $this->assertSame(1, $this->september->pageOptimizations()->count());
    }

    public function test_page_master_data_stays_editable_when_historical_cycles_are_locked(): void
    {
        $this->record();
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->assertTrue($this->manager->can('update', $this->page));

        app(UpdatePageAction::class)->handle($this->page, ['title' => 'Corrected title', 'page_type' => 'service']);

        $this->assertSame('Corrected title', $this->page->fresh()->title);
    }

    public function test_removed_pages_do_not_take_new_optimisation_work_until_reactivated(): void
    {
        $this->page->forceFill(['status' => 'removed'])->save();

        try {
            $this->record();
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('marked removed', $exception->getMessage());
        }

        $this->page->forceFill(['status' => 'active'])->save();

        $this->assertTrue($this->record()->exists);
    }
}
