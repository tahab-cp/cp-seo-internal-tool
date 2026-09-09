<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Browser view of an archived final report version. Rendered ONLY from
 * the revision's stored snapshot; live source data is never consulted.
 */
class ReportRevisionPreviewController extends Controller
{
    use ResolvesAccessibleReport;

    public function __invoke(Request $request, ReportRenderer $renderer, int|string $project, int|string $report, int|string $revision): View
    {
        $archived = $this->resolveRevision($request->user(), $project, $report, $revision, 'view');

        return $renderer->view($archived->snapshot_json);
    }
}
