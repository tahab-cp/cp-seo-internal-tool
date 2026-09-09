<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\PdfReportGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized download of an archived final version's PDF, streamed from
 * the report disk after project and report access are verified.
 */
class ReportRevisionPdfDownloadController extends Controller
{
    use ResolvesAccessibleReport;

    public function __invoke(Request $request, PdfReportGenerator $pdf, int|string $project, int|string $report, int|string $revision): StreamedResponse|Response
    {
        $archived = $this->resolveRevision($request->user(), $project, $report, $revision, 'downloadPdf');

        if (! $pdf->exists($archived->generated_pdf_path)) {
            return response('The PDF for this archived report version is not available on the report disk. Ask an administrator to check the report storage.', 404)
                ->header('Content-Type', 'text/plain');
        }

        $cycle = $archived->report->monthlyCycle;
        $filename = sprintf(
            '%s-seo-report-%04d-%02d-v%d.pdf',
            Str::slug($cycle->project->name) ?: 'project',
            $cycle->year,
            $cycle->month,
            $archived->version,
        );

        return $pdf->disk()->download($archived->generated_pdf_path, $filename, ['Content-Type' => 'application/pdf']);
    }
}
