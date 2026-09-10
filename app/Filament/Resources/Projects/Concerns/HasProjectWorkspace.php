<?php

namespace App\Filament\Resources\Projects\Concerns;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;

/**
 * Shared presentation of the project workspace (header context and module
 * navigation) for the project pages. Pure presentation: URLs, labels and
 * icons only; visibility of the report-sections module follows the
 * existing policy ability exactly as before.
 */
trait HasProjectWorkspace
{
    abstract public function getProject(): Project;

    /**
     * "Client • Location" under the project name.
     */
    public function getWorkspaceSubheading(): ?string
    {
        $project = $this->getProject();
        $subheading = implode(' • ', array_filter([$project->client?->name, $project->target_location]));

        return $subheading === '' ? null : $subheading;
    }

    /**
     * Operational modules of the workspace, in navigation order, with the
     * active one flagged.
     *
     * @return list<array{key: string, label: string, icon: string, url: string, current: bool}>
     */
    public function getWorkspaceModules(string $active): array
    {
        $project = $this->getProject();
        $url = fn (string $page): string => ProjectResource::getUrl($page, ['record' => $project]);

        $modules = [
            ['key' => 'overview', 'label' => 'Overview', 'icon' => 'heroicon-o-squares-2x2', 'url' => $url('view')],
            ['key' => 'tasks', 'label' => 'Tasks', 'icon' => 'heroicon-o-clipboard-document-list', 'url' => $url('tasks')],
            ['key' => 'pages', 'label' => 'Pages', 'icon' => 'heroicon-o-document-text', 'url' => $url('pages')],
            ['key' => 'keywords', 'label' => 'Keywords', 'icon' => 'heroicon-o-magnifying-glass', 'url' => $url('keywords')],
            ['key' => 'backlinks', 'label' => 'Backlinks', 'icon' => 'heroicon-o-link', 'url' => $url('backlinks')],
            ['key' => 'content', 'label' => 'Content', 'icon' => 'heroicon-o-pencil', 'url' => $url('content')],
            ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'heroicon-o-chart-bar', 'url' => $url('analytics')],
            ['key' => 'monthly-work', 'label' => 'Monthly work', 'icon' => 'heroicon-o-light-bulb', 'url' => $url('monthly-work')],
            ['key' => 'reports', 'label' => 'Reports', 'icon' => 'heroicon-o-document-chart-bar', 'url' => $url('reports')],
        ];

        if (Gate::allows('manageReportSections', $project)) {
            $modules[] = ['key' => 'report-sections', 'label' => 'Report sections', 'icon' => 'heroicon-o-adjustments-horizontal', 'url' => $url('report-sections')];
        }

        $modules[] = ['key' => 'monthly-cycles', 'label' => 'Monthly cycles', 'icon' => 'heroicon-o-calendar-days', 'url' => $url('monthly-cycles')];

        return array_map(fn (array $module): array => $module + ['current' => $module['key'] === $active], $modules);
    }
}
