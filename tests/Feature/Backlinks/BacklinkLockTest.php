<?php

namespace Tests\Feature\Backlinks;

use App\Actions\Backlinks\CreateBacklinkAction;
use App\Actions\Backlinks\SetBacklinkStatusAction;
use App\Actions\Backlinks\UpdateBacklinkAction;
use App\Actions\MonthlyCycles\CreateMonthlyCycleAction;
use App\Enums\BacklinkStatus;
use App\Enums\MonthlyCycleStatus;
use App\Exceptions\LockedMonthlyCycleException;
use App\Models\Backlink;
use App\Models\MonthlyCycle;
use App\Models\Project;
use App\Models\User;
use App\Support\MonthlyCycles\CyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BacklinkLockTest extends TestCase
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

    protected function create(MonthlyCycle $cycle, array $overrides = []): Backlink
    {
        return app(CreateBacklinkAction::class)->handle($this->project, $overrides + [
            'monthly_cycle_id' => $cycle->id,
            'published_url' => 'https://blog.example/'.uniqid(),
            'type' => 'citation',
            'status' => 'submitted',
        ], $this->manager);
    }

    public function test_open_and_reporting_cycles_accept_creation_and_edits(): void
    {
        $open = $this->create($this->october);
        $this->assertTrue($open->exists);

        $this->september->forceFill(['status' => MonthlyCycleStatus::Reporting])->save();

        $reporting = $this->create($this->september, ['type' => 'guest_post']);
        app(UpdateBacklinkAction::class)->handle($reporting, ['anchor_text' => 'edited in reporting']);
        app(SetBacklinkStatusAction::class)->handle($reporting, BacklinkStatus::Live);

        $this->assertSame('edited in reporting', $reporting->fresh()->anchor_text);
        $this->assertTrue($reporting->fresh()->isLive());
        $this->assertTrue($this->manager->can('update', $reporting->fresh()));
    }

    public function test_locked_cycles_are_immutable_for_everyone(): void
    {
        $frozen = $this->create($this->september, ['status' => 'live', 'anchor_text' => 'frozen', 'domain_authority' => 40]);
        $openLink = $this->create($this->october);

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
            fn () => app(UpdateBacklinkAction::class)->handle($frozen, ['anchor_text' => 'changed']),
            fn () => app(UpdateBacklinkAction::class)->handle($frozen, ['published_url' => 'https://other.example/x']),
            fn () => app(UpdateBacklinkAction::class)->handle($frozen, ['type' => 'guest_post']),
            fn () => app(UpdateBacklinkAction::class)->handle($frozen, ['domain_authority' => 90]),
            fn () => app(UpdateBacklinkAction::class)->handle($frozen, ['monthly_cycle_id' => $this->october->id]),
            fn () => app(SetBacklinkStatusAction::class)->handle($frozen, BacklinkStatus::Removed),
            fn () => app(SetBacklinkStatusAction::class)->handle($frozen, 'submitted'),
            // Nor may an open record be moved into the locked month.
            fn () => app(UpdateBacklinkAction::class)->handle($openLink, ['monthly_cycle_id' => $this->september->id]),
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
        $this->assertSame('frozen', $fresh->anchor_text);
        $this->assertSame(BacklinkStatus::Live, $fresh->status);
        $this->assertSame(40, $fresh->domain_authority);
        $this->assertSame($this->september->id, $fresh->monthly_cycle_id);
        $this->assertSame(1, $this->september->backlinks()->count());
        $this->assertSame($this->october->id, $openLink->fresh()->monthly_cycle_id);
        $this->assertNotSoftDeleted($frozen);
    }
}
