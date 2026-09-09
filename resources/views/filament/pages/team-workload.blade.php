<x-filament-panels::page>
    @php
        $rows = $this->getRows();
        $summary = $this->getSummary();
        $cards = [
            ['label' => 'Active team members', 'value' => $summary['members'], 'key' => 'members', 'color' => 'gray'],
            ['label' => 'Active primary project assignments', 'value' => $summary['primary'], 'key' => 'primary', 'color' => 'gray'],
            ['label' => 'Open assigned tasks', 'value' => $summary['open'], 'key' => 'open', 'color' => 'gray'],
            ['label' => 'Overdue assigned tasks', 'value' => $summary['overdue'], 'key' => 'overdue', 'color' => $summary['overdue'] > 0 ? 'danger' : 'gray'],
        ];
    @endphp

    {{--
        Same visual language as the Dashboard: Filament's grid macro and
        stat-card classes (shipped by the panel stylesheet), page-level
        blocks as direct children of the page content grid for a consistent
        row gap, semantic danger colour only for overdue counts.
    --}}

    {{-- Summary cards --}}
    <div
        {{
            (new \Filament\Support\View\ComponentAttributeBag)
                ->grid(['default' => 1, 'sm' => 2, 'xl' => 4])
                ->style(['gap: calc(var(--spacing) * 4)'])
                ->merge(['data-team-workload-summary' => ''], escape: false)
        }}
    >
        @foreach ($cards as $card)
            @php $flagged = $card['color'] !== 'gray'; @endphp
            <div class="fi-wi-stats-overview-stat">
                <div class="fi-wi-stats-overview-stat-content">
                    <div class="fi-wi-stats-overview-stat-label-ctn">
                        <span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span>
                    </div>
                    <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['color'].' fi-text-color-600 dark:fi-text-color-400' : '' }}"{!! $flagged ? ' style="color: var(--text)"' : '' !!} data-workload-summary="{{ $card['key'] }}" data-value="{{ $card['value'] }}">{{ $card['value'] }}</div>
                    <div class="fi-wi-stats-overview-stat-description"><span>{{ $this->getPeriod()->label() }}</span></div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Team member workload --}}
    <x-filament::section>
        <x-slot name="heading">Current workload — {{ $this->getPeriod()->label() }}</x-slot>
        <x-slot name="description">Indicators of what each active team member has on their plate: active-project ownership and membership, open and overdue assigned tasks (active projects only), tasks due this week (today to Sunday, never counting overdue ones) and reports in preparation. These are workload indicators, not capacity or performance measures.</x-slot>

        @if ($rows->isEmpty())
            <x-filament::empty-state heading="No active team workload to display." :contained="false" compact data-team-workload-empty />
        @else
            <div
                {{
                    (new \Filament\Support\View\ComponentAttributeBag)
                        ->grid(['default' => 1])
                        ->style(['gap: calc(var(--spacing) * 4)'])
                        ->merge(['data-team-workload' => ''], escape: false)
                }}
            >
                @foreach ($rows as $row)
                    @php
                        $metrics = [
                            ['label' => 'Primary projects', 'value' => $row->primaryProjects, 'key' => 'primary', 'flagged' => false],
                            ['label' => 'Additional projects', 'value' => $row->teamProjects, 'key' => 'team', 'flagged' => false],
                            ['label' => 'Open tasks', 'value' => $row->openTasks, 'key' => 'open', 'flagged' => false],
                            ['label' => 'Overdue', 'value' => $row->overdueTasks, 'key' => 'overdue', 'flagged' => $row->overdueTasks > 0],
                            ['label' => 'Due this week', 'value' => $row->dueThisWeekTasks, 'key' => 'week', 'flagged' => false],
                            ['label' => 'Reports in preparation', 'value' => $row->reportsInPreparation, 'key' => 'reports', 'flagged' => false],
                        ];
                    @endphp
                    {{-- One card per member: name and role prominent, then six compact metrics (2 / sm:3 / lg:6 per row). --}}
                    <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-workload-user="{{ $row->user->getKey() }}" data-open="{{ $row->openTasks }}" data-overdue="{{ $row->overdueTasks }}" data-week="{{ $row->dueThisWeekTasks }}" data-primary="{{ $row->primaryProjects }}" data-team="{{ $row->teamProjects }}" data-reports="{{ $row->reportsInPreparation }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn" style="flex-wrap: wrap; justify-content: space-between">
                                <span class="fi-wi-stats-overview-stat-value" style="font-size: var(--text-base); line-height: var(--text-base--line-height)" data-workload-name>{{ $row->user->name }}</span>
                                <span class="fi-wi-stats-overview-stat-description" data-workload-role>{{ $row->roleLabel() }}</span>
                            </div>

                            <dl
                                {{
                                    (new \Filament\Support\View\ComponentAttributeBag)
                                        ->grid(['default' => 2, 'sm' => 3, 'lg' => 6])
                                        ->style(['gap: calc(var(--spacing) * 4)', 'margin: 0', 'margin-top: calc(var(--spacing) * 2)'])
                                        ->merge(['data-workload-metrics' => ''], escape: false)
                                }}
                            >
                                @foreach ($metrics as $metric)
                                    <div>
                                        <dt class="fi-wi-stats-overview-stat-label">{{ $metric['label'] }}</dt>
                                        <dd class="fi-wi-stats-overview-stat-value{{ $metric['flagged'] ? ' fi-color-danger fi-text-color-600 dark:fi-text-color-400' : '' }}" style="margin: 0; font-size: var(--text-2xl); line-height: var(--text-2xl--line-height);{{ $metric['flagged'] ? ' color: var(--text);' : '' }}" data-workload-metric="{{ $metric['key'] }}">{{ $metric['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
