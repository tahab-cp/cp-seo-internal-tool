<?php

namespace App\Services\Reports;

use App\Models\MonthlyReport;
use Illuminate\Contracts\View\View;

/**
 * The single rendering path for the client report. Browser preview and
 * Chromium PDF both go through here, from the same snapshot array and the
 * same Blade template, so they cannot drift apart.
 */
class ReportRenderer
{
    public function __construct(
        protected ReportSnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * The snapshot to render: the stored one for final reports, a fresh
     * (never persisted) build for Draft / Ready previews.
     *
     * @return array<string, mixed>
     */
    public function snapshotFor(MonthlyReport $report): array
    {
        if ($report->isFinal() && is_array($report->snapshot_json)) {
            return $report->snapshot_json;
        }

        return $this->snapshotBuilder->build($report);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function view(array $snapshot, bool $forPdf = false): View
    {
        return view('reports.monthly-report', [
            'snapshot' => $snapshot,
            'forPdf' => $forPdf,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function html(array $snapshot, bool $forPdf = false): string
    {
        return $this->view($snapshot, $forPdf)->render();
    }
}
