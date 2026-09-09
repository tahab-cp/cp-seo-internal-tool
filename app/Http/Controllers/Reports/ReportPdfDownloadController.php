<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\PdfReportGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authorized download of a final report's stored PDF. The storage path is
 * never the authorization mechanism: project access and the report policy
 * are checked first, then the file is streamed from the report disk.
 */
class ReportPdfDownloadController extends Controller
{
    use ResolvesAccessibleReport;

    public function __invoke(Request $request, PdfReportGenerator $pdf, int|string $project, int|string $report): StreamedResponse|Response
    {
        $monthlyReport = $this->resolveReport($request->user(), $project, $report, 'downloadPdf');

        if (! $pdf->exists($monthlyReport->generated_pdf_path)) {
            return response('The final PDF for this report is not available on the report disk. Ask an administrator to check the report storage.', 404)
                ->header('Content-Type', 'text/plain');
        }

        $cycle = $monthlyReport->monthlyCycle;
        $filename = sprintf(
            '%s-seo-report-%04d-%02d.pdf',
            Str::slug($cycle->project->name) ?: 'project',
            $cycle->year,
            $cycle->month,
        );

        return $pdf->disk()->download($monthlyReport->generated_pdf_path, $filename, ['Content-Type' => 'application/pdf']);
    }
}
