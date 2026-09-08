<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Model policies. Registered explicitly rather than relying on discovery.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        User::class => UserPolicy::class,
        Client::class => ClientPolicy::class,
        Project::class => ProjectPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Expose every permission as a Gate ability, e.g. $user->can('roles.assign').
        foreach (Permission::cases() as $permission) {
            Gate::define(
                $permission->value,
                fn (User $user): bool => $user->hasPermission($permission),
            );
        }
    }
}
