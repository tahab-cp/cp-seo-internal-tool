<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The reporting / cycle lifecycle events worth an immutable audit record.
 */
enum AuditEventType: string implements HasColor, HasLabel
{
    case ReportMarkedReady = 'report_marked_ready';
    case ReportFinalized = 'report_finalized';
    case ReportUnlockedForCorrection = 'report_unlocked_for_correction';

    public function getLabel(): string
    {
        return match ($this) {
            self::ReportMarkedReady => 'Marked ready for review',
            self::ReportFinalized => 'Finalized',
            self::ReportUnlockedForCorrection => 'Unlocked for correction',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ReportMarkedReady => 'warning',
            self::ReportFinalized => 'success',
            self::ReportUnlockedForCorrection => 'danger',
        };
    }
}
