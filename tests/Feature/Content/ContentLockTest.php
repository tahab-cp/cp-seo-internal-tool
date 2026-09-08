<?php

namespace Tests\Feature\Content;

use App\Actions\Content\CreateContentItemAction;
use App\Actions\Content\SetContentStatusAction;
use App\Actions\Content\UpdateContentItemAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\ContentStatus;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContentLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Project $project;

    protected MonthlyCycle $september;

    protected MonthlyCycle $october;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 10:00:00');

        $this->manager = User::factory()->seoManager()->create();
        $this->project = Project::factory()->create();
        $this->september = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 9));
        $this->october = app(CreateMonthlyCycleAction::class)->handle($this->project, new CyclePeriod(2026, 10));
    }

    protected function create(?MonthlyCycle $cycle, array $overrides = []): ContentItem
    {
        return app(CreateContentItemAction::class)->handle($this->project, $overrides + [
            'title' => 'Post '.uniqid(),
            'content_type' => 'blog',
            'status' => 'planned',
            'monthly_cycle_id' => $cycle?->id,
        ]);
    }

    public function test_open_and_reporting_cycles_accept_creation_and_edits(): void
    {
        $this->assertTrue($this->create($this->october)->exists);

        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();

        $item = $this->create($this->september);
        app(UpdateContentItemAction::class)->handle($item, ['title' => 'Edited in reporting']);
        app(SetContentStatusAction::class)->handle($item, ContentStatus::Published, ['published_url' => 'https://site.example/blog/x']);

        $this->assertSame('Edited in reporting', $item->fresh()->title);
        $this->assertTrue($item->fresh()->isPublished());
        $this->assertTrue($this->manager->can('update', $item->fresh()));
    }

    public function test_locked_cycles_are_immutable_for_everyone(): void
    {
        $keyword = Keyword::factory()->forProject($this->project)->create();
        $frozen = $this->create($this->september, ['status' => 'published', 'published_at' => '2026-09-20 09:00', 'published_url' => 'https://site.example/blog/frozen', 'title' => 'Frozen']);
        $openItem = $this->create($this->october);
        $projectLevel = $this->create(null, ['title' => 'Unscheduled']);

        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now(), 'locked_by' => $this->manager->id])->save();
        $frozen->refresh();

        $this->assertTrue($frozen->isLocked());

        foreach ([User::factory()->superAdmin()->create(), $this->manager, User::factory()->seoExecutive()->create()] as $user) {
            $this->assertFalse($user->can('update', $frozen));
            $this->assertFalse($user->can('setStatus', $frozen));
            $this->assertFalse($user->can('delete', $frozen));
        }

        $attempts = [
            fn () => $this->create($this->september),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['title' => 'Changed']),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['content_type' => 'landing_page']),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['target_keyword_id' => $keyword->id]),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['assigned_user_id' => $this->manager->id]),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['published_url' => 'https://site.example/blog/other']),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['monthly_cycle_id' => $this->october->id]),
            fn () => app(UpdateContentItemAction::class)->handle($frozen, ['monthly_cycle_id' => null]),
            fn () => app(SetContentStatusAction::class)->handle($frozen, ContentStatus::Review),
            fn () => app(SetContentStatusAction::class)->handle($frozen, ContentStatus::Cancelled),
            // Nor may open or project-level items be moved into the locked month.
            fn () => app(UpdateContentItemAction::class)->handle($openItem, ['monthly_cycle_id' => $this->september->id]),
            fn () => app(UpdateContentItemAction::class)->handle($projectLevel, ['monthly_cycle_id' => $this->september->id]),
            fn () => app(SetContentStatusAction::class)->handle($projectLevel, ContentStatus::Published, ['monthly_cycle_id' => $this->september->id, 'published_url' => 'https://site.example/blog/late']),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('Expected LockedMonthlyCycleException.');
            } catch (LockedMonthlyCycleException) {
                $this->addToAssertionCount(1);
            }
        }

        $fresh = $frozen->fresh();
        $this->assertSame('Frozen', $fresh->title);
        $this->assertTrue($fresh->isPublished());
        $this->assertSame('https://site.example/blog/frozen', $fresh->published_url);
        $this->assertSame($this->september->id, $fresh->monthly_cycle_id);
        $this->assertSame(1, $this->september->contentItems()->count());
        $this->assertSame($this->october->id, $openItem->fresh()->monthly_cycle_id);
        $this->assertNull($projectLevel->fresh()->monthly_cycle_id);
        $this->assertNotSoftDeleted($frozen);
    }

    public function test_project_level_content_stays_editable_despite_locked_cycles(): void
    {
        $this->september->forceFill(['status' => MonthlyCycleStatus::Locked, 'locked_at' => now()])->save();
        $item = $this->create(null, ['title' => 'Evergreen guide']);

        $this->assertFalse($item->isLocked());
        $this->assertTrue($this->manager->can('update', $item));

        app(UpdateContentItemAction::class)->handle($item, ['title' => 'Evergreen guide v2', 'content_type' => 'service_page']);
        app(SetContentStatusAction::class)->handle($item, ContentStatus::Writing);
        app(UpdateContentItemAction::class)->handle($item, ['monthly_cycle_id' => $this->october->id]);

        $fresh = $item->fresh();
        $this->assertSame('Evergreen guide v2', $fresh->title);
        $this->assertSame(ContentStatus::Writing, $fresh->status);
        $this->assertSame($this->october->id, $fresh->monthly_cycle_id);
    }
}
