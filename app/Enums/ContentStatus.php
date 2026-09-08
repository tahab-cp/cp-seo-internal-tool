<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContentStatus: string implements HasColor, HasLabel
{
    case Idea = 'idea';
    case Planned = 'planned';
    case Writing = 'writing';
    case Review = 'review';
    case Approved = 'approved';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Idea => 'Idea',
            self::Planned => 'Planned',
            self::Writing => 'Writing',
            self::Review => 'Review',
            self::Approved => 'Approved',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Idea => 'gray',
            self::Planned => 'gray',
            self::Writing => 'info',
            self::Review => 'warning',
            self::Approved => 'primary',
            self::Published => 'success',
            self::Cancelled => 'danger',
        };
    }

    public function isPublished(): bool
    {
        return $this === self::Published;
    }

    /**
     * The natural forward step in the workflow, if any.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Idea => self::Planned,
            self::Planned => self::Writing,
            self::Writing => self::Review,
            self::Review => self::Approved,
            self::Approved => self::Published,
            default => null,
        };
    }
}
