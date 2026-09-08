<?php

namespace App\Enums;

/**
 * Application permissions.
 *
 * Permissions are defined in code, mapped to roles by UserRole::permissions(),
 * and exposed as Gate abilities by AuthServiceProvider. Policies check them.
 */
enum Permission: string
{
    case ViewUsers = 'users.view';
    case CreateUsers = 'users.create';
    case UpdateUsers = 'users.update';
    case ActivateUsers = 'users.activate';
    case AssignRoles = 'roles.assign';

    public function label(): string
    {
        return match ($this) {
            self::ViewUsers => 'View users',
            self::CreateUsers => 'Create users',
            self::UpdateUsers => 'Edit users',
            self::ActivateUsers => 'Activate and deactivate users',
            self::AssignRoles => 'Assign system roles to users',
        };
    }
}
