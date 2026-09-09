<?php

namespace Tests\Feature\Reports;

use App\Actions\Analytics\SaveGscMonthlyMetricsAction;
use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Actions\Reports\UpdateProjectReportSectionsAction;
use App\Actions\Reports\UpdateReportReviewNotesAction;
use App\Actions\Reports\UpdateReportSectionTextAction;
use App\Exceptions\LockedMonthlyCycleException;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\MonthlyReportSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportEditorTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');

        $this->executive = User::factory()->seoExecutive()->create(['name' => 'Eli Executive']);
        $this->buildCompleteReport($this->executive);
    }

    protected function editor(?int $reportId = null)
    {
        return Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $reportId ?? $this->report->getKey()]);
    }

    protected function previewUrl(): string
    {
        return route('filament.admin.reports.preview', ['project' => $this->project->getKey(), 'report' => $this->report->getKey()]);
    }

    public function test_editor_loads_for_admin_manager_and_assigned_executive_with_header_context(): void
    {
        foreach ([User::factory()->superAdmin()->create(), $this->manager, $this->executive] as $user) {
            $this->actingAs($user);

            $this->get(ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->report]))
                ->assertOk()
                ->assertSee('Casa Botanica')
                ->assertSee('Casa Botanica Ltd')
                ->assertSee('September 2026')
                ->assertSee('data-report-status="draft"', false)
                ->assertSee('data-readiness="100"', false)
                ->assertSee('9 / 9 required sections complete');

            $this->editor()
                ->assertActionVisible('preview')
                ->assertActionVisible('markReady')
                ->assertActionHidden('finalize')
                ->assertActionHidden('downloadPdf')
                ->assertActionVisible('editExecutiveSummary');
        }
    }

    public function test_unrelated_executive_cannot_access_the_editor_or_preview(): void
    {
        $outsider = User::factory()->seoExecutive()->create();
        $this->actingAs($outsider);

        $this->get(ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->report]))->assertNotFound();
        $this->get($this->previewUrl())->assertNotFound();

        try {
            $this->editor();
            $this->fail('Expected the project to be outside the scoped resource query.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        // A crafted report id from another project on an accessible project is also a 404.
        $own = Project::factory()->ownedBy($outsider)->create();
        $this->get(ProjectResource::getUrl('report', ['record' => $own, 'report' => $this->report]))->assertNotFound();
        $this->get(route('filament.admin.reports.preview', ['project' => $own->getKey(), 'report' => $this->report->getKey()]))->assertNotFound();
    }

    public function test_editor_and_preview_use_the_snapshot_configuration_not_the_current_project_template(): void
    {
        // Customise the snapshot directly: rename Backlinks, move it first, disable Audience by Country.
        $this->report->sections()->where('section_key', 'backlinks')->update(['title' => 'Link Building Snapshot', 'sort_order' => 1]);
        $this->report->sections()->where('section_key', 'audience_country')->update(['is_enabled' => false]);

        // …and change the project template to something else entirely.
        app(UpdateProjectReportSectionsAction::class)->handle($this->project, [
            ['section_key' => 'audience_country', 'title' => 'Countries (template)', 'is_enabled' => true, 'is_required' => true],
            ['section_key' => 'backlinks', 'title' => 'Links (template)', 'is_enabled' => false, 'is_required' => false],
        ]);

        $this->actingAs($this->manager);

        $html = $this->get(ProjectResource::getUrl('report', ['record' => $this->project, 'report' => $this->report]))->assertOk()->getContent();

        $this->assertStringContainsString('Link Building Snapshot', $html);
        $this->assertStringNotContainsString('Links (template)', $html);
        $this->assertStringNotContainsString('Countries (template)', $html);
        $this->assertSame('backlinks', $this->editor()->instance()->getSections()->first()->section_key->value);

        $preview = $this->get($this->previewUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-section="backlinks"', $preview);
        $this->assertStringContainsString('Link Building Snapshot', $preview);
        $this->assertStringNotContainsString('data-section="audience_country"', $preview);
        $this->assertStringNotContainsString('United Kingdom', $preview);
        $this->assertLessThan(strpos($preview, 'data-section="executive_summary"'), strpos($preview, 'data-section="backlinks"'));
    }

    public function test_narrative_is_editable_in_draft_and_review_notes_stay_internal(): void
    {
        $this->actingAs($this->executive);

        $rankings = $this->report->sections()->where('section_key', 'rankings')->firstOrFail();

        $this->editor()
            ->callAction('editExecutiveSummary', data: ['executive_summary' => 'Executive summary written by Eli.'])
            ->assertNotified('Executive summary saved')
            ->assertSee('Executive summary written by Eli.')
            ->callAction('editSectionText', data: ['custom_text' => 'Rankings improved after the migration settled.'], arguments: ['section' => $rankings->getKey()])
            ->assertNotified('Commentary saved')
            ->assertSee('Rankings improved after the migration settled.')
            ->assertActionHidden('editReviewNotes');

        $this->assertSame('Rankings improved after the migration settled.', $rankings->fresh()->custom_text);
        $this->assertSame('Executive summary written by Eli.', $this->report->fresh()->executive_summary);

        // Reviewer notes: manager only, and never in the client preview.
        $this->actingAs($this->manager);

        $this->editor()
            ->assertActionVisible('editReviewNotes')
            ->callAction('editReviewNotes', data: ['review_notes' => 'INTERNAL: double-check the DR figure before finalizing.'])
            ->assertNotified('Review notes saved')
            ->assertSee('INTERNAL: double-check the DR figure');

        $preview = $this->get($this->previewUrl())->assertOk()->getContent();
        $this->assertStringContainsString('Executive summary written by Eli.', $preview);
        $this->assertStringContainsString('Rankings improved after the migration settled.', $preview);
        $this->assertStringNotContainsString('INTERNAL', $preview);
        $this->assertStringNotContainsString('double-check the DR figure', $preview);

        // Crafted section id from another report is a 404, not an edit.
        $foreign = MonthlyReportSection::factory()->create();

        try {
            $this->editor()->callAction('editSectionText', data: ['custom_text' => 'hijack'], arguments: ['section' => $foreign->getKey()]);
            $this->fail('Expected a foreign section to be unresolvable.');
        } catch (ModelNotFoundException) {
            $this->assertNull($foreign->fresh()->custom_text);
        }

        $this->assertFalse($this->executive->can('reviewNotes', $this->report->fresh()));
    }

    public function test_final_report_is_read_only_everywhere(): void
    {
        FakePdfReportGenerator::install();
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);

        $final = $this->report->fresh();
        $section = $final->sections()->first();

        foreach ([User::factory()->superAdmin()->create(), $this->manager, $this->executive] as $user) {
            $this->assertFalse($user->can('prepare', $final));
            $this->assertFalse($user->can('markReady', $final));
            $this->assertFalse($user->can('reviewNotes', $final));
            $this->assertFalse($user->can('finalize', $final));
            $this->assertTrue($user->can('view', $final));
        }

        try {
            app(UpdateReportSectionTextAction::class)->handle($section, 'late edit');
            $this->fail('Expected a final report section to be immutable.');
        } catch (InvalidArgumentException|LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(UpdateReportReviewNotesAction::class)->handle($final, 'late note');
            $this->fail('Expected review notes to be frozen.');
        } catch (InvalidArgumentException|LockedMonthlyCycleException) {
            $this->addToAssertionCount(1);
        }

        $this->actingAs($this->manager);

        $this->editor()
            ->assertSee('data-report-status="final"', false)
            ->assertSee('Morgan Manager')
            ->assertSee('Reporting period locked')
            ->assertActionHidden('editExecutiveSummary')
            ->assertActionHidden('editSectionText')
            ->assertActionHidden('editReviewNotes')
            ->assertActionHidden('markReady')
            ->assertActionHidden('finalize')
            ->assertActionVisible('downloadPdf')
            ->assertActionVisible('preview');

        $this->assertNull($section->fresh()->custom_text);
    }

    public function test_draft_preview_reads_live_data_while_final_preview_reads_the_snapshot(): void
    {
        $this->actingAs($this->manager);

        $this->get($this->previewUrl())->assertOk()
            ->assertSee('data-metric="clicks">1,200<', false)
            ->assertSee('Preview —');

        // Change source data: the draft preview follows it.
        app(SaveGscMonthlyMetricsAction::class)->handle($this->cycle, ['clicks' => 1500, 'impressions' => 50000], $this->manager);
        $this->get($this->previewUrl())->assertOk()->assertSee('data-metric="clicks">1,500<', false);
        $this->assertNull($this->report->fresh()->snapshot_json);

        // Finalize, then tamper with the stored snapshot to prove it is the only source.
        FakePdfReportGenerator::install();
        app(MarkReportReadyAction::class)->handle($this->report->fresh(), $this->manager);
        app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager);

        $final = $this->report->fresh();
        $this->assertSame(1500, $final->snapshot_json['sections'][2]['data']['clicks']);

        $snapshot = $final->snapshot_json;
        $snapshot['sections'][2]['data']['clicks'] = 999999;
        $final->forceFill(['snapshot_json' => $snapshot])->save();

        $this->get($this->previewUrl())->assertOk()
            ->assertSee('data-metric="clicks">999,999<', false)
            ->assertDontSee('Preview —')
            ->assertSee('Finalized 30 September 2026 by Morgan Manager');
    }
}
