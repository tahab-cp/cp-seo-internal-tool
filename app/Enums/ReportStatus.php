<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Draft → Ready for Review → Final. Only Draft is reachable through the
 * application in Milestone 12; the workflow transitions arrive in 13.
 */
enum ReportStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case ReadyForReview = 'ready_for_review';
    case Final = 'final';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::ReadyForReview => 'Ready for review',
            self::Final => 'Final',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ReadyForReview => 'warning',
            self::Final => 'success',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
