<?php

namespace App\Http\Controllers\Reports;

use App\Filament\Resources\Projects\ProjectResource;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Browser view of an archived final report version. Rendered ONLY from
 * the revision's stored snapshot; live source data is never consulted.
 * The small preview toolbar is browser-only presentation.
 */
class ReportRevisionPreviewController extends Controller
{
    use ResolvesAccessibleReport;

    public function __invoke(Request $request, ReportRenderer $renderer, int|string $project, int|string $report, int|string $revision): View
    {
        $archived = $this->resolveRevision($request->user(), $project, $report, $revision, 'view');
        $parent = $archived->report;

        return $renderer->view($archived->snapshot_json)->with('toolbar', [
            'back_url' => ProjectResource::getUrl('report', ['record' => $parent->monthlyCycle->project, 'report' => $parent]),
            'back_label' => 'Back to report',
            'state' => 'Superseded '.$archived->versionLabel(),
            'final' => false,
            'pdf_url' => $archived->hasPdf() && Gate::forUser($request->user())->allows('downloadPdf', $archived)
                ? route('filament.admin.reports.revisions.pdf', ['project' => $parent->monthlyCycle->project->getKey(), 'report' => $parent, 'revision' => $archived])
                : null,
        ]);
    }
}
