<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycle = $this->getCurrentCycle();
        $report = $cycle?->monthlyReport;
        $readiness = $cycle ? $this->readinessFor($cycle) : null;
        $canCreate = $cycle && $report === null && \Illuminate\Support\Facades\Gate::allows('ensureReport', $cycle);

        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);

        $cards = $report ? [
            ['key' => 'report', 'label' => 'Current report', 'value' => $report->status->getLabel(), 'helper' => $report->isCorrection() ? 'Correction in progress' : ($report->isFinal() ? 'Reporting period locked' : 'In preparation'), 'tone' => $report->status->getColor(), 'attr' => 'data-reports-status="'.e($report->status->value).'"'],
            ['key' => 'readiness', 'label' => 'Readiness', 'value' => $readiness->percentage().'%', 'helper' => $readiness->isReady() ? 'Report data is complete' : $readiness->missing()->count().' section'.($readiness->missing()->count() === 1 ? '' : 's').' missing', 'tone' => $readiness->isReady() ? 'success' : 'warning', 'attr' => 'data-reports-readiness="'.e($readiness->percentage()).'"'],
            ['key' => 'sections', 'label' => 'Required sections', 'value' => $readiness->completedRequiredCount().' / '.$readiness->requiredCount(), 'helper' => 'complete', 'tone' => 'gray', 'attr' => 'data-reports-sections="'.e($readiness->completedRequiredCount().'/'.$readiness->requiredCount()).'"'],
            ['key' => 'version', 'label' => 'Version', 'value' => $report->versionLabel(), 'helper' => ($count = $report->revisions->count()) ? $count.' superseded version'.($count === 1 ? '' : 's') : 'First version', 'tone' => 'gray', 'attr' => 'data-reports-version="'.e($report->version).'"'],
        ] : [];
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('reports')])

    {{-- Module title --}}
    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-reports-header>
        <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Reports</h2>
        <p {!! $muted !!}>Prepare, review and manage monthly SEO reports for this project.</p>
    </div>

    {{-- Current period summary --}}
    <x-filament::section>
        <x-slot name="heading">{{ $cycle?->periodLabel() ?? 'Current report' }}</x-slot>
        <x-slot name="description">{{ $cycle ? 'This month\'s report at a glance. Readiness shows whether enough data exists to render every required section.' : 'Reports are prepared per reporting month once the project has monthly cycles.' }}</x-slot>
        @if ($cycle)
            <x-slot name="afterHeader">
                <div {!! $row !!} data-reports-current="{{ $cycle->getKey() }}">
                    @include('filament.reports.partials.status-badge', ['report' => $report, 'size' => 'lg'])
                    @if ($cycle->isLocked())
                        <x-filament::badge color="gray" data-reports-locked>Locked</x-filament::badge>
                    @endif
                </div>
            </x-slot>
        @endif

        @if ($report)
            <div {{ $grid(['default' => 2, 'xl' => 4], 4, ['data-reports-summary' => '']) }}>
                @foreach ($cards as $card)
                    @php $flagged = $card['tone'] !== 'gray'; @endphp
                    <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-reports-card="{{ $card['key'] }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['tone'].' fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums;{{ $flagged ? ' color: var(--text);' : '' }}" {!! $card['attr'] !!}>{{ $card['value'] }}</div>
                            <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <div style="margin-top: calc(var(--spacing) * 4)">
                <x-filament::button tag="a" :href="\App\Filament\Resources\Projects\ProjectResource::getUrl('report', ['record' => $project, 'report' => $report])" :color="$report->isFinal() ? 'gray' : 'primary'" size="sm" :icon="$report->isFinal() ? 'heroicon-o-eye' : 'heroicon-o-pencil-square'" data-reports-open-current>{{ $report->isFinal() ? 'View report' : 'Open report' }}</x-filament::button>
            </div>
        @elseif ($cycle)
            <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 1) 0" data-reports-empty>
                <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No report started yet</div>
                <p {!! $muted !!}>{{ $cycle->isLocked() ? 'This reporting month is locked, so no report can be started for it.' : 'Create a draft report when you are ready to prepare this month\'s SEO report.' }}</p>
                @if ($canCreate)
                    <div><x-filament::button size="sm" icon="heroicon-o-document-plus" wire:click="mountAction('ensureReport', {}, { recordKey: '{{ $cycle->getKey() }}', table: true })" data-reports-create-current>Create draft report</x-filament::button></div>
                @endif
            </div>
        @else
            <p {!! $muted !!} data-reports-no-cycles>No monthly cycles yet.</p>
        @endif
    </x-filament::section>

    {{-- Report history --}}
    <div style="display: grid; gap: calc(var(--spacing) * 3)">
        <div style="display: grid; gap: calc(var(--spacing) * 1)">
            <h2 class="fi-section-header-heading" data-reports-table-heading>Report history</h2>
            <p {!! $muted !!} data-reports-history-helper>View previous monthly reports and their current status. Final reports keep their previous versions when corrections are made.</p>
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
