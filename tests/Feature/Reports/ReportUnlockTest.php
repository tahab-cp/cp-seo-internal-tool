<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UnlockMonthlyReportAction;
use App\Actions\Reports\UpdateReportSectionTextAction;
use App\Enums\MonthlyCycleStatus;
use App\Enums\ReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportUnlockTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $admin;

    protected FakePdfReportGenerator $pdf;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');
        $this->pdf = FakePdfReportGenerator::install();

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Ava Admin']);
        $this->buildCompleteReport();
        app(UpdateReportSectionTextAction::class)->handle($this->report->sections()->where('section_key', 'rankings')->firstOrFail(), 'Rankings commentary v1.');
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
    }

    protected function unlock(?string $reason = 'GA4 organic sessions were entered incorrectly.', ?User $actor = null): MonthlyReport
    {
        return app(UnlockMonthlyReportAction::class)->handle($this->report->fresh(), $actor ?? $this->admin, $reason);
    }

    public function test_a_first_final_is_version_one(): void
    {
        $this->assertSame(1, $this->report->version);
        $this->assertSame('v1', $this->report->versionLabel());
        $this->assertStringContainsString('-v1-', $this->report->generated_pdf_path);
        $this->assertSame(0, $this->report->revisions()->count());
        $this->assertFalse($this->report->isCorrection());
    }

    public function test_only_super_admin_may_unlock_and_only_a_final_report(): void
    {
        foreach ([$this->manager, User::factory()->seoExecutive()->create()] as $user) {
            $this->assertFalse($user->can('unlock', $this->report));

            try {
                $this->unlock(actor: $user);
                $this->fail('Expected AuthorizationException.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($this->admin->can('unlock', $this->report));
        $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);
        $this->assertSame(0, MonthlyReportRevision::query()->count());

        // Non-final reports cannot be unlocked, even by a Super Admin.
        $draft = MonthlyReport::factory()->status(ReportStatus::ReadyForReview)->create();
        $this->assertFalse($this->admin->can('unlock', $draft));

        try {
            app(UnlockMonthlyReportAction::class)->handle($draft, $this->admin, 'A perfectly valid reason here.');
            $this->fail('Expected AuthorizationException for a non-final report.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_meaningful_reason_is_mandatory(): void
    {
        foreach ([null, '', '   ', 'typo', str_repeat('x', 1001)] as $reason) {
            try {
                $this->unlock($reason);
                $this->fail('Expected the reason to be rejected: '.json_encode($reason));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('reason', $exception->getMessage());
            }
        }

        $this->assertSame(ReportStatus::Final, $this->report->fresh()->status);
        $this->assertTrue($this->cycle->fresh()->isLocked());
        $this->assertSame(0, MonthlyReportRevision::query()->count());

        $unlocked = $this->unlock("  Wrong   GSC export\n was used.  ");
        $this->assertSame('Wrong GSC export was used.', $unlocked->revisions()->first()->unlock_reason);
    }

    public function test_unlocking_archives_the_final_as_an_immutable_revision_and_reopens_the_month(): void
    {
        $finalPdf = $this->report->generated_pdf_path;
        $finalSnapshot = $this->report->snapshot_json;
        $finalizedAt = $this->report->finalized_at->toDateTimeString();

        Carbon::setTestNow('2026-10-02 09:32:00');

        $unlocked = $this->unlock('GA4 organic sessions were entered incorrectly.');
        $fresh = $unlocked->fresh();
        $cycle = $this->cycle->fresh();

        // Report: back to Draft, next version, final-only fields cleared.
        $this->assertSame(ReportStatus::Draft, $fresh->status);
        $this->assertSame(2, $fresh->version);
        $this->assertTrue($fresh->isCorrection());
        $this->assertNull($fresh->snapshot_json);
        $this->assertNull($fresh->generated_pdf_path);
        $this->assertNull($fresh->generated_at);
        $this->assertNull($fresh->finalized_at);
        $this->assertNull($fresh->finalized_by);

        // Working narrative is kept for the correction.
        $this->assertSame('A strong month with steady organic growth.', $fresh->executive_summary);
        $this->assertSame('Rankings commentary v1.', $fresh->sections()->where('section_key', 'rankings')->value('custom_text'));
        $this->assertSame(10, $fresh->sections()->count());

        // Cycle: Reporting, unlocked.
        $this->assertSame(MonthlyCycleStatus::Reporting, $cycle->status);
        $this->assertNull($cycle->locked_at);
        $this->assertNull($cycle->locked_by);
        $this->assertFalse($cycle->isLocked());

        // Revision: the exact previous final, with reason and version.
        $revision = $fresh->revisions()->firstOrFail();
        $this->assertSame(1, $revision->version);
        $this->assertSame($finalSnapshot, $revision->snapshot_json);
        $this->assertSame($finalPdf, $revision->generated_pdf_path);
        $this->assertSame($finalizedAt, $revision->finalized_at->toDateTimeString());
        $this->assertTrue($revision->finalizedBy->is($this->manager));
        $this->assertTrue($revision->archivedBy->is($this->admin));
        $this->assertSame('2026-10-02 09:32:00', $revision->archived_at->toDateTimeString());
        $this->assertSame('GA4 organic sessions were entered incorrectly.', $revision->unlock_reason);

        // The previous PDF file is untouched.
        Storage::disk('local')->assertExists($finalPdf);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_refinalization_produces_version_two_with_a_new_pdf_and_leaves_version_one_intact(): void
    {
        $v1Pdf = $this->report->generated_pdf_path;
        $v1Snapshot = $this->report->snapshot_json;

        $this->unlock();
        app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle->fresh(), ['clicks' => 1350, 'impressions' => 48000, 'ctr' => 2.5, 'average_position' => 14.3], $this->manager);

        Carbon::setTestNow('2026-10-03 11:00:00');
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $v2 = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $this->assertSame(ReportStatus::Final, $v2->status);
        $this->assertSame(2, $v2->version);
        $this->assertStringContainsString('-v2-', $v2->generated_pdf_path);
        $this->assertNotSame($v1Pdf, $v2->generated_pdf_path);
        $this->assertSame(1350, collect($v2->snapshot_json['sections'])->firstWhere('key', 'organic_search')['data']['clicks']);
        $this->assertTrue($this->cycle->fresh()->isLocked());
        $this->assertSame(2, $this->pdf->conversions);

        // v1 is untouched: same snapshot, same path, same file still there.
        $revision = $v2->revisions()->where('version', 1)->firstOrFail();
        $this->assertSame($v1Snapshot, $revision->snapshot_json);
        $this->assertSame($v1Pdf, $revision->generated_pdf_path);
        $this->assertSame(1200, collect($revision->snapshot_json['sections'])->firstWhere('key', 'organic_search')['data']['clicks']);
        Storage::disk('local')->assertExists($v1Pdf);
        Storage::disk('local')->assertExists($v2->generated_pdf_path);
        $this->assertCount(2, Storage::disk('local')->allFiles());

        // Unlock again → v3.
        Carbon::setTestNow('2026-10-04 08:00:00');
        $this->report = $v2;
        $this->unlock('Client requested correction to September backlink data.');
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        $v3 = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();

        $this->assertSame(3, $v3->version);
        $this->assertStringContainsString('-v3-', $v3->generated_pdf_path);
        $this->assertSame([2, 1], $v3->revisions()->pluck('version')->all());
        $this->assertSame('Client requested correction to September backlink data.', $v3->revisions()->where('version', 2)->value('unlock_reason'));
        $this->assertCount(3, Storage::disk('local')->allFiles());
        $this->assertSame($v1Snapshot, $v3->revisions()->where('version', 1)->first()->snapshot_json);
    }

    public function test_revisions_are_immutable_history(): void
    {
        $this->unlock();
        $revision = $this->report->fresh()->revisions()->firstOrFail();

        foreach ([$this->admin, $this->manager] as $user) {
            $this->assertTrue($user->can('view', $revision));
            $this->assertFalse($user->can('update', $revision));
            $this->assertFalse($user->can('delete', $revision));
            $this->assertFalse($user->can('forceDelete', $revision));
            $this->assertFalse($user->can('restore', $revision));
        }

        try {
            $revision->unlock_reason = 'rewritten';
            $revision->save();
            $this->fail('Expected revisions to refuse updates.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $revision->delete();
            $this->fail('Expected revisions to refuse deletion.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('GA4 organic sessions were entered incorrectly.', $revision->fresh()->unlock_reason);
        $this->assertSame(1, MonthlyReportRevision::query()->count());
        $this->assertFalse(class_exists('App\Actions\Reports\UpdateReportRevisionAction'));
        $this->assertFalse(class_exists('App\Actions\Reports\DeleteReportRevisionAction'));
        $this->assertFalse(class_exists('App\Actions\Reports\RestoreReportRevisionAction'));
    }
}
