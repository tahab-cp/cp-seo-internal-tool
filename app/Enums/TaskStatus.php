<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TaskStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'info',
            self::Blocked => 'danger',
            self::Completed => 'success',
            self::Cancelled => 'warning',
        };
    }

    /**
     * Still needs work: pending, in progress or blocked.
     */
    public function isOpen(): bool
    {
        return in_array($this, self::openCases(), true);
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    /**
     * @return list<self>
     */
    public static function openCases(): array
    {
        return [self::Pending, self::InProgress, self::Blocked];
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::openCases());
    }
}
