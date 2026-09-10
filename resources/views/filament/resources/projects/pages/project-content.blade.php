<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $unscheduled = $this->isUnscheduledView();
        $blogs = $this->getBlogsProgress();
        $workflow = $this->getWorkflowSummary();
        $count = array_sum($workflow);
        $percentage = $blogs?->percentage();
        $complete = $blogs?->isComplete() ?? false;

        $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);

        $viewHeading = $cycle ? 'Monthly progress' : ($unscheduled ? 'Unscheduled content' : 'All content');
        $viewDescription = $cycle
            ? 'Published blogs assigned to this reporting month count towards the monthly Blog target.'
            : ($unscheduled
                ? 'Content ideas and work that have not yet been assigned to a reporting month. They count towards no monthly target.'
                : 'Every content item for this project. Select a reporting month to view Blogs Published against its target.');
        $tableHeading = $cycle ? 'Content in '.$cycle->periodLabel() : ($unscheduled ? 'Unscheduled content' : 'All content');
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('content')])

    {{-- Module title --}}
    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-content-header>
        <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Content</h2>
        <p {!! $muted !!}>Plan, manage and track SEO content for this project.</p>
    </div>

    {{-- Monthly progress / view summary: view selector, Blogs Published card, workflow summary --}}
    <x-filament::section>
        <x-slot name="heading">{{ $viewHeading }}</x-slot>
        <x-slot name="description">{{ $viewDescription }}</x-slot>
        <x-slot name="afterHeader">
            <div {!! $row !!} data-content-view="{{ $cycle?->periodLabel() ?? $this->selectedView }}">
                <label for="content-view" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">View</label>
                <div style="min-width: 12rem">
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="content-view" wire:model.live="selectedView">
                            @foreach ($cycles as $option)
                                <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                            @endforeach
                            <option value="unscheduled">Unscheduled</option>
                            <option value="all">All content</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                @if ($cycle)
                    <x-filament::badge :color="$cycle->status->getColor()" data-content-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                @endif
            </div>
        </x-slot>

        <div {{ $grid(['default' => 1, 'xl' => $cycle ? 2 : 1], 6) }}>
            @if ($cycle && $blogs)
                <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-content-card="blogs">
                    <div class="fi-wi-stats-overview-stat-content">
                        <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">Blogs published</span></div>
                        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 2)">
                            <div class="fi-wi-stats-overview-stat-value{{ $complete ? ' fi-color-success fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums;{{ $complete ? ' color: var(--text);' : '' }}" data-blogs-actual="{{ $blogs->actual ?? '' }}" data-blogs-target="{{ $blogs->target ?? '' }}">{{ $blogs->format() }}</div>
                            @if ($percentage !== null)
                                <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums; color: {{ $complete ? 'var(--success-600)' : 'var(--primary-600)' }}" data-blogs-percentage="{{ $percentage }}">{{ $percentage }}%</span>
                            @endif
                        </div>
                        @if ($percentage !== null)
                            <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $percentage) }}" aria-label="Blogs published {{ $percentage }}%" style="margin-top: calc(var(--spacing) * 2); height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden">
                                <div style="height: 100%; width: {{ min(100, $percentage) }}%; border-radius: 999px; background: {{ $complete ? 'var(--success-500)' : 'var(--primary-500)' }}"></div>
                            </div>
                        @endif
                        <div class="fi-wi-stats-overview-stat-description" style="margin-top: calc(var(--spacing) * 2)"><span data-blogs-remaining="{{ $blogs->remaining() ?? '' }}">{{ $blogs->remainingLabel() ?? 'No blog target was configured for this reporting month.' }}</span></div>
                    </div>
                </div>
            @endif

            {{-- Workflow summary: items per status in this view (one grouped query, existing statuses only) --}}
            <div style="display: grid; gap: calc(var(--spacing) * 2)" data-content-workflow>
                <div {!! $label !!}>Workflow{{ $cycle ? ' · '.$cycle->periodLabel() : '' }}</div>
                @if ($count === 0)
                    <p {!! $muted !!} data-content-workflow-empty>No content in this view yet.</p>
                @else
                    <div {{ $grid(['default' => 2, 'sm' => 4, 'lg' => 7], 2) }}>
                        @foreach ($workflow as $statusValue => $statusCount)
                            @php $status = \App\Enums\ContentStatus::from($statusValue); @endphp
                            <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 2) calc(var(--spacing) * 3); display: grid; gap: calc(var(--spacing) * 0.5)" data-content-status="{{ $statusValue }}">
                                <span class="fi-section-header-description" style="font-size: var(--text-xs)">{{ $status->getLabel() }}</span>
                                <span class="fi-in-text-item" style="font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums" data-content-status-count="{{ $statusCount }}">{{ $statusCount }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @if ($cycle?->isLocked())
            <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)" data-content-locked>This reporting month is locked. Content assigned to this month is read-only.</p>
        @endif
    </x-filament::section>

    {{-- Content records --}}
    <div style="display: grid; gap: calc(var(--spacing) * 3)">
        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 2)">
            <h2 class="fi-section-header-heading" data-content-table-heading>{{ $tableHeading }}</h2>
            @if ($count > 0)
                <span {!! $muted !!} data-content-summary>{{ $count }} {{ $count === 1 ? 'item' : 'items' }}{{ ($workflow['published'] ?? 0) > 0 ? ' · '.$workflow['published'].' published' : '' }}</span>
            @endif
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
