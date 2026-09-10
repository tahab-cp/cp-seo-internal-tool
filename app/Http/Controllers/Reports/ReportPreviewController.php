<?php

namespace App\Http\Controllers\Reports;

use App\Filament\Resources\Projects\ProjectResource;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Browser preview of the client report. Draft / Ready reports render from
 * current source data (nothing is persisted); final reports render from
 * the stored snapshot only. The small preview toolbar is browser-only
 * presentation and never reaches the PDF.
 */
class ReportPreviewController extends Controller
{
    use ResolvesAccessibleReport;

    public function __invoke(Request $request, ReportRenderer $renderer, int|string $project, int|string $report): View
    {
        $monthlyReport = $this->resolveReport($request->user(), $project, $report, 'view');

        return $renderer->view($renderer->snapshotFor($monthlyReport))->with('toolbar', [
            'back_url' => ProjectResource::getUrl('report', ['record' => $monthlyReport->monthlyCycle->project, 'report' => $monthlyReport]),
            'back_label' => 'Back to report',
            'state' => match (true) {
                $monthlyReport->isFinal() => 'Final report · '.$monthlyReport->versionLabel(),
                $monthlyReport->isReadyForReview() => 'Ready for review preview',
                default => 'Draft preview',
            },
            'final' => $monthlyReport->isFinal(),
            'pdf_url' => $monthlyReport->isFinal() && $monthlyReport->hasPdf() && Gate::forUser($request->user())->allows('downloadPdf', $monthlyReport)
                ? route('filament.admin.reports.pdf', ['project' => $monthlyReport->monthlyCycle->project->getKey(), 'report' => $monthlyReport])
                : null,
        ]);
    }
}
