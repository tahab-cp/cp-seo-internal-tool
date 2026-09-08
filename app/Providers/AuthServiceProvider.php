<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Backlink;
use App\Models\Client;
use App\Models\ContentItem;
use App\Models\Keyword;
use App\Models\MonthlyCycle;
use App\Models\MonthlyNote;
use App\Models\MonthlyReport;
use App\Models\Package;
use App\Models\Page;
use App\Models\PageOptimization;
use App\Models\Project;
use App\Models\RankingSnapshot;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Policies\BacklinkPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ContentItemPolicy;
use App\Policies\KeywordPolicy;
use App\Policies\MonthlyCyclePolicy;
use App\Policies\MonthlyNotePolicy;
use App\Policies\MonthlyReportPolicy;
use App\Policies\PackagePolicy;
use App\Policies\PageOptimizationPolicy;
use App\Policies\PagePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RankingSnapshotPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TaskTemplatePolicy;
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
        Package::class => PackagePolicy::class,
        MonthlyCycle::class => MonthlyCyclePolicy::class,
        TaskTemplate::class => TaskTemplatePolicy::class,
        Task::class => TaskPolicy::class,
        Page::class => PagePolicy::class,
        PageOptimization::class => PageOptimizationPolicy::class,
        Keyword::class => KeywordPolicy::class,
        RankingSnapshot::class => RankingSnapshotPolicy::class,
        Backlink::class => BacklinkPolicy::class,
        ContentItem::class => ContentItemPolicy::class,
        MonthlyNote::class => MonthlyNotePolicy::class,
        MonthlyReport::class => MonthlyReportPolicy::class,
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
