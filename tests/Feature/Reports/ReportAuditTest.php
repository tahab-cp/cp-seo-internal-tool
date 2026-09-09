<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Enums\AuditEventType;
use App\Models\MonthlyCycleAuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportAuditTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-09 10:14:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Ava Admin']);
        $this->buildCompleteReport();
    }

    public function test_finalize_unlock_and_refinalize_leave_an_immutable_trail(): void
    {
        $this->assertSame(0, MonthlyCycleAuditEvent::query()->count());

        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $v1 = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $events = $v1->auditEvents()->get();
        $this->assertSame(['report_marked_ready', 'report_finalized'], $events->map(fn ($e) => $e->event_type->value)->all());

        $finalized = $events->last();
        $this->assertTrue($finalized->user->is($this->manager));
        $this->assertSame(1, $finalized->version());
        $this->assertSame('2026-09-09 10:14:00', $finalized->created_at->toDateTimeString());
        $this->assertSame($v1->generated_pdf_path, $finalized->metadata_json['generated_pdf_path']);
        $this->assertSame(64, strlen($finalized->metadata_json['snapshot_fingerprint']));
        $this->assertNull($finalized->reason);
        $this->assertTrue($finalized->monthlyCycle->is($this->cycle));

        // Unlock.
        Carbon::setTestNow('2026-09-10 09:32:00');
        $unlocked = app(UnlockMonthlyReportAction::class)->handle($v1, $this->admin, 'Incorrect GA4 sessions were entered.');
        $revision = $unlocked->revisions()->firstOrFail();

        $unlock = $unlocked->auditEvents()->get()->last();
        $this->assertSame(AuditEventType::ReportUnlockedForCorrection, $unlock->event_type);
        $this->assertTrue($unlock->user->is($this->admin));
        $this->assertSame('Incorrect GA4 sessions were entered.', $unlock->reason);
        $this->assertSame(2, $unlock->version(), 'The event is recorded against the version now being prepared.');
        $this->assertSame(1, $unlock->metadata_json['superseded_version']);
        $this->assertSame($revision->getKey(), $unlock->metadata_json['revision_id']);
        $this->assertSame($this->manager->getKey(), $unlock->metadata_json['previous_finalized_by']);
        $this->assertStringStartsWith('2026-09-09T10:14:00', $unlock->metadata_json['previous_finalized_at']);
        $this->assertSame('2026-09-10 09:32:00', $unlock->created_at->toDateTimeString());

        // Re-finalize: a NEW event, old ones untouched.
        app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle->fresh(), ['clicks' => 1350, 'impressions' => 48000], $this->manager);
        Carbon::setTestNow('2026-09-10 10:47:00');
        app(MarkReportReadyAction::class)->handle($unlocked->fresh(), $this->manager);
        $v2 = app(FinalizeMonthlyReportAction::class)->handle($unlocked->fresh(), $this->manager)->fresh();

        $all = $v2->auditEvents()->get();
        $this->assertSame(
            ['report_marked_ready', 'report_finalized', 'report_unlocked_for_correction', 'report_marked_ready', 'report_finalized'],
            $all->map(fn ($e) => $e->event_type->value)->all(),
        );
        $this->assertSame([1, 1, 2, 2, 2], $all->map(fn ($e) => $e->version())->all());
        $this->assertSame($finalized->getKey(), $all[1]->getKey());
        $this->assertSame($finalized->metadata_json, $all[1]->metadata_json);
        $this->assertSame('2026-09-09 10:14:00', $all[1]->created_at->toDateTimeString());
        $this->assertSame('2026-09-10 10:47:00', $all->last()->created_at->toDateTimeString());
        $this->assertNotSame($finalized->metadata_json['snapshot_fingerprint'], $all->last()->metadata_json['snapshot_fingerprint']);
        $this->assertSame(5, $this->cycle->fresh()->auditEvents()->count());
    }

    public function test_audit_events_cannot_be_mutated_or_deleted(): void
    {
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);

        $event = MonthlyCycleAuditEvent::query()->latest('id')->firstOrFail();

        foreach ([$this->admin, $this->manager, User::factory()->seoExecutive()->create()] as $user) {
            $this->assertFalse($user->can('update', $event));
            $this->assertFalse($user->can('delete', $event));
            $this->assertFalse($user->can('forceDelete', $event));
            $this->assertFalse($user->can('create', MonthlyCycleAuditEvent::class));
        }

        $this->assertTrue($this->admin->can('view', $event));
        $this->assertFalse(User::factory()->seoExecutive()->create()->can('view', $event));

        try {
            $event->reason = 'tampered';
            $event->save();
            $this->fail('Expected audit events to refuse updates.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('Expected audit events to refuse deletion.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($event->fresh()->reason);
        $this->assertSame(2, MonthlyCycleAuditEvent::query()->count());
        $this->assertFalse(class_exists('App\Actions\Reports\DeleteAuditEventAction'));
        $this->assertFalse(class_exists('Spatie\Activitylog\Models\Activity'));
    }
}
