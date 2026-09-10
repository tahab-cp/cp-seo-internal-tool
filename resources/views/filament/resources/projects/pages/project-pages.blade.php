<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $progress = $this->getPagesOptimisedProgress();
        $tracked = $this->getPagesTracked();
        $percentage = $progress?->percentage();
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('pages')])

    {{-- Monthly progress: period + status, compact month selector, metric cards, progress bar --}}
    <x-filament::section>
        <x-slot name="heading">Pages optimised</x-slot>
        <x-slot name="description">Each page counts once per month, against that month's snapshotted target.</x-slot>
        <x-slot name="afterHeader">
            @if ($cycles->isNotEmpty())
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)" data-pages-period="{{ $cycle?->periodLabel() }}">
                    <label for="pages-cycle" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Reporting month</label>
                    <div style="min-width: 12rem">
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="pages-cycle" wire:model.live="selectedCycleId">
                                @foreach ($cycles as $option)
                                    <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    @if ($cycle)
                        <x-filament::badge :color="$cycle->status->getColor()" data-pages-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                    @endif
                </div>
            @endif
        </x-slot>

        @if ($cycles->isEmpty())
            <p {!! $muted !!} data-pages-no-cycles>No monthly cycles yet. Progress appears once the project has a reporting month.</p>
        @elseif ($cycle && $progress)
            @php
                $cards = [
                    ['key' => 'optimised', 'label' => 'Pages optimised', 'value' => $progress->format(), 'helper' => $percentage !== null ? $percentage.'% of target' : 'No target this month', 'color' => $progress->isComplete() ? 'success' : 'gray', 'attrs' => 'data-pages-optimised="'.e($progress->actual ?? '').'" data-pages-target="'.e($progress->target ?? '').'"'],
                    ['key' => 'tracked', 'label' => 'Pages tracked', 'value' => $tracked, 'helper' => 'In this project', 'color' => 'gray', 'attrs' => 'data-pages-tracked="'.$tracked.'"'],
                    ['key' => 'remaining', 'label' => $progress->isOverTarget() ? 'Over target' : 'Remaining', 'value' => $progress->hasTarget() ? ($progress->isOverTarget() ? $progress->actual - $progress->target : $progress->remaining()) : '—', 'helper' => $progress->hasTarget() ? ($progress->remainingLabel() ?? '') : 'No target set', 'color' => 'gray', 'attrs' => 'data-pages-remaining="'.e($progress->remaining() ?? '').'"'],
                ];
            @endphp

            <div {{ $grid(['default' => 1, 'sm' => 3], 4, ['data-pages-metrics' => '']) }}>
                @foreach ($cards as $card)
                    @php $flagged = $card['color'] !== 'gray'; @endphp
                    <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-pages-card="{{ $card['key'] }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['color'].' fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height);{{ $flagged ? ' color: var(--text);' : '' }}" {!! $card['attrs'] !!}>{{ $card['value'] }}</div>
                            <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="margin-top: calc(var(--spacing) * 5)" data-pages-progress>
                @if ($percentage !== null)
                    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: calc(var(--spacing) * 2)">
                        <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $progress->label }}</span>
                        <span class="fi-in-text-item" style="font-size: var(--text-sm); font-variant-numeric: tabular-nums">{{ $progress->format() }} <span style="margin-left: calc(var(--spacing) * 2); font-weight: var(--font-weight-semibold); color: {{ $progress->isComplete() ? 'var(--success-600)' : 'var(--primary-600)' }}" data-pages-percentage="{{ $percentage }}">{{ $percentage }}%</span></span>
                    </div>
                    <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $percentage) }}" aria-label="{{ $progress->label }} {{ $percentage }}%" style="margin-top: calc(var(--spacing) * 1.5); height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden">
                        <div style="height: 100%; width: {{ min(100, $percentage) }}%; border-radius: 999px; background: {{ $progress->isComplete() ? 'var(--success-500)' : 'var(--primary-500)' }}"></div>
                    </div>
                    <div class="fi-section-header-description" style="margin-top: calc(var(--spacing) * 1); font-size: var(--text-xs)">{{ $progress->isOverTarget() ? 'Target exceeded by '.($progress->actual - $progress->target) : $progress->remainingLabel() }}</div>
                @else
                    <p {!! $muted !!} data-pages-no-target>No pages-optimised target was configured for this month.</p>
                @endif

                @if ($cycle->isLocked())
                    <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 3); font-size: var(--text-sm)" data-pages-locked>This reporting month is locked. Historical optimisation records are read-only.</p>
                @endif
            </div>
        @endif
    </x-filament::section>

    {{-- Pages table --}}
    <div style="display: grid; gap: calc(var(--spacing) * 3)">
        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 2)">
            <h2 class="fi-section-header-heading">Pages</h2>
            @if ($tracked > 0)
                <span {!! $muted !!} data-pages-summary>{{ $tracked }} {{ $tracked === 1 ? 'page' : 'pages' }} tracked{{ $progress?->actual ? ' · '.$progress->actual.' optimised in '.$cycle->periodLabel() : '' }}</span>
            @endif
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
