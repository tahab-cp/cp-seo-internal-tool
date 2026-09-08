<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The three system roles. Every user holds exactly one.
 */
enum UserRole: string implements HasColor, HasLabel
{
    case SuperAdmin = 'super_admin';
    case SeoManager = 'seo_manager';
    case SeoExecutive = 'seo_executive';

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::SeoManager => 'SEO Manager',
            self::SeoExecutive => 'SEO Executive',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::SeoManager => 'warning',
            self::SeoExecutive => 'info',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access, including users, roles, settings, finalization and unlocking.',
            self::SeoManager => 'Manages clients, projects and team assignments; reviews and finalizes reports.',
            self::SeoExecutive => 'Works on assigned projects and prepares reports for review.',
        };
    }

    /**
     * User administration is reserved for Super Admins. Managers get team
     * visibility through the dedicated Team functionality in a later milestone.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => Permission::cases(),
            self::SeoManager => [],
            self::SeoExecutive => [],
        };
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
