<?php

namespace Tests\Feature\Reports;

use App\Actions\Reports\FinalizeMonthlyReportAction;
use App\Actions\Reports\MarkReportReadyAction;
use App\Filament\Resources\Projects\Pages\ProjectReportEditor;
use App\Filament\Resources\Projects\Pages\ProjectReports;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsCompleteReports;
use Tests\Support\FakePdfReportGenerator;
use Tests\TestCase;

class ReportPdfDownloadTest extends TestCase
{
    use BuildsCompleteReports;
    use RefreshDatabase;

    protected User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 10:00:00');
        Storage::fake('local');
        FakePdfReportGenerator::install();

        $this->executive = User::factory()->seoExecutive()->create();
        $this->buildCompleteReport($this->executive);
    }

    protected function finalize(): void
    {
        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->report = app(FinalizeMonthlyReportAction::class)->handle($this->report->fresh(), $this->manager)->fresh();
    }

    protected function pdfUrl(?Project $project = null, ?int $report = null): string
    {
        return route('filament.admin.reports.pdf', ['project' => ($project ?? $this->project)->getKey(), 'report' => $report ?? $this->report->getKey()]);
    }

    public function test_admin_manager_and_assigned_executive_can_download_the_final_pdf(): void
    {
        $this->finalize();

        foreach ([User::factory()->superAdmin()->create(), $this->manager, $this->executive] as $user) {
            $this->actingAs($user);

            $this->assertTrue($user->can('downloadPdf', $this->report));

            $response = $this->get($this->pdfUrl())->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('content-type'));
            $this->assertStringContainsString('casa-botanica-seo-report-2026-09.pdf', (string) $response->headers->get('content-disposition'));
            $this->assertStringStartsWith('%PDF', $response->streamedContent());

            Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
                ->assertActionVisible('downloadPdf');
            Livewire::test(ProjectReports::class, ['record' => $this->project->getRouteKey()])
                ->assertTableActionVisible('downloadPdf', $this->cycle);
        }
    }

    public function test_unrelated_executive_and_guessed_routes_cannot_download(): void
    {
        $this->finalize();
        $outsider = User::factory()->seoExecutive()->create();
        $own = Project::factory()->ownedBy($outsider)->create();

        $this->assertFalse($outsider->can('downloadPdf', $this->report));

        $this->actingAs($outsider);
        $this->get($this->pdfUrl())->assertNotFound();
        // Guessing the report id under an accessible project is still a 404.
        $this->get($this->pdfUrl($own, $this->report->getKey()))->assertNotFound();
        // The storage path itself is never a URL (private disk: refused or unknown, never served).
        $this->assertContains($this->get('/storage/'.$this->report->generated_pdf_path)->getStatusCode(), [403, 404]);
        $this->assertContains($this->get('/storage/local/'.$this->report->generated_pdf_path)->getStatusCode(), [403, 404]);

        // Guests are redirected to the login page.
        auth()->logout();
        $this->get($this->pdfUrl())->assertRedirect(Filament::getLoginUrl());

        Storage::disk('local')->assertExists($this->report->generated_pdf_path);
    }

    public function test_non_final_reports_have_no_pdf_download(): void
    {
        $this->actingAs($this->manager);

        $this->assertFalse($this->manager->can('downloadPdf', $this->report));
        $this->get($this->pdfUrl())->assertForbidden();

        Livewire::test(ProjectReportEditor::class, ['record' => $this->project->getRouteKey(), 'report' => $this->report->getKey()])
            ->assertActionHidden('downloadPdf');

        app(MarkReportReadyAction::class)->handle($this->report, $this->manager);
        $this->get($this->pdfUrl())->assertForbidden();
    }

    public function test_a_missing_stored_file_is_handled_safely(): void
    {
        $this->finalize();
        Storage::disk('local')->delete($this->report->generated_pdf_path);

        $this->actingAs($this->manager);

        $this->get($this->pdfUrl())
            ->assertNotFound()
            ->assertSee('not available on the report disk');

        // The report itself is untouched and still final.
        $this->assertTrue($this->report->fresh()->isFinal());
        $this->assertNotNull($this->report->fresh()->generated_pdf_path);
    }
}
