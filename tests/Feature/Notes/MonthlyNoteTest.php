<?php

namespace Tests\Feature\Notes;

use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Actions\Notes\CreateMonthlyNoteAction;
use App\Actions\Notes\DeleteMonthlyNoteAction;
use App\Actions\Notes\UpdateMonthlyNoteAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\MonthlyNoteType;
use App\Exceptions\InactiveUserAssignmentException;
use App\Exceptions\LockedMonthlyCycleException;
use App\Exceptions\UnauthorizedProjectUserException;
use App\Filament\Resources\Projects\Pages\ProjectMonthlyWork;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class MonthlyNoteTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected User $executive;

    protected Project $project;

    protected Project $unrelated;

    protected MonthlyCycle $september;

    protected MonthlyCycle $unrelatedCycle;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->executive = User::factory()->seoExecutive()->create();
        $this->project = Project::factory()->ownedBy($this->executive)->create();
        $this->unrelated = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->unrelatedCycle = app(CreateMonthlyCycleAction::class)->handle($this->unrelated, new CyclePeriod(2026, 9));
    }

    protected function note(array $attributes = [], ?User $author = null, ?MonthlyCycle $cycle = null): MonthlyNote
    {
        return app(CreateMonthlyNoteAction::class)->handle($cycle ?? $this->september, $attributes + [
            'type' => 'win',
            'body' => 'Ranked #1 for the main keyword.',
        ], $author ?? $this->manager);
    }

    public function test_cycle_has_many_notes_with_the_documented_types(): void
    {
        $this->assertSame(
            ['win', 'challenge', 'observation', 'recommendation', 'next_month_focus'],
            array_map(fn (MonthlyNoteType $t): string => $t->value, MonthlyNoteType::cases()),
        );

        foreach (MonthlyNoteType::cases() as $type) {
            $this->note(['type' => $type->value, 'title' => $type->getLabel(), 'body' => 'Body for '.$type->value]);
        }

        $this->assertSame(5, $this->september->monthlyNotes()->count());
        $this->assertSame(0, $this->unrelatedCycle->monthlyNotes()->count());

        $note = $this->september->monthlyNotes()->ofType(MonthlyNoteType::NextMonthFocus)->firstOrFail();
        $this->assertTrue($note->monthlyCycle->is($this->september));
        $this->assertTrue($note->createdBy->is($this->manager));
        $this->assertSame('Next month focus', $note->title);
        $this->assertSame(1, $note->sort_order);
        $this->assertSame(MonthlyNoteType::NextMonthFocus, $note->type);

        // Ordering within a type increments automatically; explicit order is honoured.
        $second = $this->note();
        $this->assertSame(2, $second->sort_order);
        $this->assertSame(7, $this->note(['sort_order' => 7])->sort_order);
    }

    public function test_body_is_required_and_lengths_are_capped(): void
    {
        foreach ([
            ['body' => ''],
            ['body' => '   '],
            ['body' => str_repeat('b', 5001)],
            ['title' => str_repeat('t', 151)],
            ['type' => 'idea'],
            ['sort_order' => -1],
        ] as $attributes) {
            try {
                $this->note($attributes);
                $this->fail('Expected rejection for '.json_encode($attributes));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, MonthlyNote::query()->count());
        $this->assertNull($this->note(['title' => '  '])->title);
    }

    public function test_admin_manager_and_assigned_executive_may_create_notes(): void
    {
        $member = User::factory()->seoExecutive()->create();
        $this->project->teamMembers()->attach($member);

        foreach ([User::factory()->superAdmin()->create(), $this->manager, $this->executive, $member] as $author) {
            $note = $this->note(['body' => 'By '.$author->id], $author);

            $this->assertSame($author->id, $note->created_by);
            $this->assertTrue($author->can('manageNotes', $this->september));
            $this->assertTrue($author->can('update', $note));
            $this->assertTrue($author->can('delete', $note));
        }

        $this->assertSame(4, $this->september->monthlyNotes()->count());
    }

    public function test_author_must_be_active_with_project_access(): void
    {
        $inactive = User::factory()->seoManager()->inactive()->create();
        $outsider = User::factory()->seoExecutive()->create();

        try {
            $this->note([], $inactive);
            $this->fail('Expected InactiveUserAssignmentException.');
        } catch (InactiveUserAssignmentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->note([], $outsider);
            $this->fail('Expected UnauthorizedProjectUserException.');
        } catch (UnauthorizedProjectUserException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, MonthlyNote::query()->count());
    }

    public function test_unrelated_executive_cannot_access_notes(): void
    {
        $secret = $this->note(['body' => 'Secret win'], $this->manager, $this->unrelatedCycle);

        $this->actingAs($this->executive);

        $this->assertFalse($this->executive->can('view', $secret));
        $this->assertFalse($this->executive->can('update', $secret));
        $this->assertFalse($this->executive->can('delete', $secret));
        $this->assertFalse($this->executive->can('manageNotes', $this->unrelatedCycle));
        $this->assertFalse($this->executive->can('manageNotes', $this->unrelated));
        $this->assertFalse(MonthlyNote::query()->accessibleBy($this->executive)->whereKey($secret->id)->exists());

        $this->get(ProjectResource::getUrl('monthly-work', ['record' => $this->unrelated]))->assertNotFound();

        try {
            Livewire::test(ProjectMonthlyWork::class, ['record' => $this->unrelated->getRouteKey()]);
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // Crafted note ids from another project resolve to nothing (404) on the accessible page.
        $page = Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()])
            ->assertDontSee('Secret win');

        foreach ([
            fn () => $page->callAction('editNote', data: ['type' => 'win', 'body' => 'Hijacked'], arguments: ['note' => $secret->id]),
            fn () => $page->callAction('deleteNote', arguments: ['note' => $secret->id]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the foreign note to be unresolvable.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Secret win', $secret->fresh()->body);
        $this->assertNotNull($secret->fresh());
    }

    public function test_locked_cycles_reject_note_writes_for_everyone(): void
    {
        $existing = $this->note(['body' => 'Frozen']);

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $existing->refresh();

        $admin = User::factory()->superAdmin()->create();

        foreach ([$admin, $this->manager, $this->executive] as $user) {
            $this->assertFalse($user->can('manageNotes', $this->september->fresh()));
            $this->assertFalse($user->can('update', $existing));
            $this->assertFalse($user->can('delete', $existing));
            $this->assertTrue($user->can('view', $existing));

            foreach ([
                fn () => $this->note(['body' => 'Late'], $user),
                fn () => app(UpdateMonthlyNoteAction::class)->handle($existing, ['body' => 'Changed']),
                fn () => app(UpdateMonthlyNoteAction::class)->handle($existing, ['type' => 'challenge']),
                fn () => app(UpdateMonthlyNoteAction::class)->handle($existing, ['sort_order' => 9]),
                fn () => app(DeleteMonthlyNoteAction::class)->handle($existing),
            ] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Expected LockedMonthlyCycleException.');
                } catch (LockedMonthlyCycleException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->assertSame('Frozen', $existing->fresh()->body);
        $this->assertSame(1, $this->september->monthlyNotes()->count());

        // The reporting status remains editable.
        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting, 'locked_at' => null])->save();
        app(UpdateMonthlyNoteAction::class)->handle($existing->fresh(), ['body' => 'Edited while reporting']);
        $this->assertSame('Edited while reporting', $existing->fresh()->body);
    }

    public function test_monthly_work_page_groups_notes_and_supports_add_edit_delete(): void
    {
        $this->actingAs($this->executive);

        $this->get(ProjectResource::getUrl('monthly-work', ['record' => $this->project]))
            ->assertOk()
            ->assertSee('No wins recorded yet')
            ->assertSee('Capture notable results during the month so reporting is easier later.')
            ->assertSee('data-empty-lane="challenges"', false)
            ->assertSee('data-empty-lane="recommendations"', false);

        $component = Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()])
            ->assertSet('selectedCycle', (string) $this->september->id)
            ->callAction('addNote', data: ['type' => 'win', 'body' => ''])
            ->assertHasFormErrors(['body' => 'required']);

        Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()])
            ->callAction('addNote', data: ['type' => 'win', 'title' => 'Top spot', 'body' => 'Ranked #1 for villa rentals.'])
            ->assertHasNoFormErrors()
            ->assertNotified('Note added')
            ->callAction('addNote', data: ['type' => 'challenge', 'body' => 'Site migration slipped.'], arguments: ['type' => 'challenge'])
            ->assertNotified('Note added')
            ->callAction('addNote', data: ['type' => 'next_month_focus', 'body' => 'Publish the pricing guide.'])
            ->assertNotified('Note added')
            ->assertSee('Top spot')
            ->assertSee('Site migration slipped.')
            ->assertSee('Publish the pricing guide.')
            ->assertDontSee('data-empty-lane', false);

        $win = $this->september->monthlyNotes()->ofType(MonthlyNoteType::Win)->firstOrFail();
        $this->assertSame($this->executive->id, $win->created_by);

        Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()])
            ->callAction('editNote', data: ['type' => 'observation', 'title' => 'Top spot', 'body' => 'Actually #2.'], arguments: ['note' => $win->id])
            ->assertNotified('Note updated')
            ->assertSee('Actually #2.')
            ->callAction('deleteNote', arguments: ['note' => $win->id])
            ->assertNotified('Note deleted');

        $this->assertNull($win->fresh());
        $this->assertSame(2, $this->september->monthlyNotes()->count());
    }

    public function test_locked_month_is_read_only_in_the_ui(): void
    {
        $note = $this->note(['body' => 'Frozen note']);
        $october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()])
            ->set('selectedCycle', (string) $this->september->id)
            ->assertSee('Frozen note')
            ->assertSee('Locked')
            ->assertActionHidden('addNote')
            ->assertActionHidden('editNote')
            ->assertActionHidden('deleteNote')
            ->mountAction('deleteNote', arguments: ['note' => $note->id])
            ->callMountedAction();

        $this->assertNotNull($note->fresh());

        Livewire::test(ProjectMonthlyWork::class, ['record' => $this->project->getRouteKey()])
            ->set('selectedCycle', (string) $october->id)
            ->assertActionVisible('addNote')
            ->callAction('addNote', data: ['type' => 'win', 'body' => 'October win'])
            ->assertNotified('Note added');

        $this->assertSame(1, $october->monthlyNotes()->count());
    }

    public function test_monthly_work_is_not_a_global_sidebar_module(): void
    {
        $this->get(ProjectResource::getUrl('monthly-work', ['record' => $this->project]))
            ->assertRedirect(Filament::getLoginUrl());

        $this->actingAs(User::factory()->superAdmin()->create());

        $labels = collect(Filament::getPanel('admin')->getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => $item->getLabel())
            ->all();

        $this->assertNotContains('Monthly work', $labels);
        $this->assertNotContains('Notes', $labels);
        $this->get('/admin')->assertOk()->assertDontSee('/admin/monthly-work');
        $this->get(ProjectResource::getUrl('view', ['record' => $this->project]))
            ->assertOk()
            ->assertSee(ProjectResource::getUrl('monthly-work', ['record' => $this->project]));
    }
}
