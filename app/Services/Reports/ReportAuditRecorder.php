<?php

namespace App\Services\Reports;

use App\Enums\AuditEventType;
use App\Models\MonthlyCycleAuditEvent;
use App\Models\MonthlyReport;
use App\Models\User;

/**
 * Writes the immutable audit events for the report lifecycle. Called by
 * the domain actions inside their own transactions, never by the UI.
 */
class ReportAuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata  plain scalars/arrays only; never secrets
     */
    public function record(MonthlyReport $report, User $actor, AuditEventType $type, ?string $reason = null, array $metadata = []): MonthlyCycleAuditEvent
    {
        return MonthlyCycleAuditEvent::query()->create([
            'monthly_cycle_id' => $report->monthly_cycle_id,
            'monthly_report_id' => $report->getKey(),
            'user_id' => $actor->getKey(),
            'event_type' => $type,
            'reason' => $reason,
            'metadata_json' => ['version' => $report->version] + $metadata,
        ]);
    }
}
