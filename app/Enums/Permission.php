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

    case ViewClients = 'clients.view';
    case CreateClients = 'clients.create';
    case UpdateClients = 'clients.update';
    case ArchiveClients = 'clients.archive';

    case ViewAllProjects = 'projects.view_all';
    case ViewAssignedProjects = 'projects.view_assigned';
    case CreateProjects = 'projects.create';
    case UpdateProjects = 'projects.update';
    case ArchiveProjects = 'projects.archive';
    case AssignProjectTeam = 'projects.assign_team';
    case AssignProjectPackage = 'projects.assign_package';
    case ManageProjectTargets = 'projects.manage_targets';

    case ManagePackages = 'packages.manage';

    case EnsureMonthlyCycles = 'monthly_cycles.ensure';

    case ManageTaskTemplates = 'task_templates.manage';
    case GenerateOnboardingTasks = 'projects.generate_onboarding';

    case ManageReportSections = 'projects.manage_report_sections';

    public function label(): string
    {
        return match ($this) {
            self::ViewUsers => 'View users',
            self::CreateUsers => 'Create users',
            self::UpdateUsers => 'Edit users',
            self::ActivateUsers => 'Activate and deactivate users',
            self::AssignRoles => 'Assign system roles to users',
            self::ViewClients => 'View clients',
            self::CreateClients => 'Create clients',
            self::UpdateClients => 'Edit clients',
            self::ArchiveClients => 'Archive clients',
            self::ViewAllProjects => 'View every project',
            self::ViewAssignedProjects => 'View assigned projects only',
            self::CreateProjects => 'Create projects',
            self::UpdateProjects => 'Edit projects and change their lifecycle',
            self::ArchiveProjects => 'Archive and restore projects',
            self::AssignProjectTeam => 'Assign project owners and team members',
            self::AssignProjectPackage => 'Assign a package to a project',
            self::ManageProjectTargets => 'Configure project target overrides',
            self::ManagePackages => 'Manage packages and their targets (Settings)',
            self::EnsureMonthlyCycles => 'Manually create missing monthly cycles',
            self::ManageTaskTemplates => 'Manage onboarding task templates (Settings)',
            self::GenerateOnboardingTasks => 'Generate onboarding tasks for a project',
            self::ManageReportSections => 'Configure a project\'s report sections (future reports)',
        };
    }
}
