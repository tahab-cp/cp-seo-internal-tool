<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Browser preview of the client report. Draft / Ready reports render from
 * current source data (nothing is persisted); final reports render from
 * the stored snapshot only.
 */
class ReportPreviewController extends Controller
{
    use ResolvesAccessibleReport;

    public function __invoke(Request $request, ReportRenderer $renderer, int|string $project, int|string $report): View
    {
        $monthlyReport = $this->resolveReport($request->user(), $project, $report, 'view');

        return $renderer->view($renderer->snapshotFor($monthlyReport));
    }
}
