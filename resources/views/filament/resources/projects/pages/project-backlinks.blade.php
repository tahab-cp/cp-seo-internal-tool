<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $backlinks = $this->getBacklinksProgress();
        $guestPosts = $this->getGuestPostsProgress();
        $breakdown = $this->getLiveTypeBreakdown();
        $count = $this->getRecordCount();

        $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('backlinks')])

    {{-- Module title --}}
    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-backlinks-header>
        <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Backlinks</h2>
        <p {!! $muted !!}>Track monthly link-building work and guest post progress.</p>
    </div>

    {{-- Monthly progress: period + status, compact month selector, two progress cards --}}
    <x-filament::section>
        <x-slot name="heading">Monthly progress</x-slot>
        <x-slot name="description">Only live links count towards monthly progress. A live guest post counts towards both Backlinks and Guest Posts.</x-slot>
        <x-slot name="afterHeader">
            <div {!! $row !!} data-backlinks-period="{{ $cycle?->periodLabel() ?? 'all' }}">
                <label for="backlinks-cycle" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Reporting month</label>
                <div style="min-width: 12rem">
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="backlinks-cycle" wire:model.live="selectedCycle">
                            <option value="all">All time</option>
                            @foreach ($cycles as $option)
                                <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                @if ($cycle)
                    <x-filament::badge :color="$cycle->status->getColor()" data-backlinks-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                @endif
            </div>
        </x-slot>

        @if ($cycle && $backlinks && $guestPosts)
            @php
                $cards = [
                    ['key' => 'backlinks', 'label' => 'Live backlinks', 'progress' => $backlinks, 'attr' => 'data-backlinks-actual="'.e($backlinks->actual ?? '').'" data-backlinks-target="'.e($backlinks->target ?? '').'"', 'remainingAttr' => 'data-backlinks-remaining="'.e($backlinks->remaining() ?? '').'"', 'noTarget' => 'No backlink target was configured for this reporting month.', 'percentageAttr' => 'data-backlinks-percentage'],
                    ['key' => 'guest-posts', 'label' => 'Guest posts', 'progress' => $guestPosts, 'attr' => 'data-guest-posts-actual="'.e($guestPosts->actual ?? '').'" data-guest-posts-target="'.e($guestPosts->target ?? '').'"', 'remainingAttr' => 'data-guest-posts-remaining="'.e($guestPosts->remaining() ?? '').'"', 'noTarget' => 'No guest post target was configured for this reporting month.', 'percentageAttr' => 'data-guest-posts-percentage'],
                ];
            @endphp

            <div {{ $grid(['default' => 1, 'sm' => 2], 4, ['data-backlinks-metrics' => '']) }}>
                @foreach ($cards as $card)
                    @php
                        $progress = $card['progress'];
                        $percentage = $progress->percentage();
                        $complete = $progress->isComplete();
                    @endphp
                    <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-backlinks-card="{{ $card['key'] }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 2)">
                                <div class="fi-wi-stats-overview-stat-value{{ $complete ? ' fi-color-success fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums;{{ $complete ? ' color: var(--text);' : '' }}" {!! $card['attr'] !!}>{{ $progress->format() }}</div>
                                @if ($percentage !== null)
                                    <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums; color: {{ $complete ? 'var(--success-600)' : 'var(--primary-600)' }}" {{ $card['percentageAttr'] }}="{{ $percentage }}">{{ $percentage }}%</span>
                                @endif
                            </div>
                            @if ($percentage !== null)
                                <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $percentage) }}" aria-label="{{ $card['label'] }} {{ $percentage }}%" style="margin-top: calc(var(--spacing) * 2); height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden">
                                    <div style="height: 100%; width: {{ min(100, $percentage) }}%; border-radius: 999px; background: {{ $complete ? 'var(--success-500)' : 'var(--primary-500)' }}"></div>
                                </div>
                            @endif
                            <div class="fi-wi-stats-overview-stat-description" style="margin-top: calc(var(--spacing) * 2)"><span {!! $card['remainingAttr'] !!}>{{ $progress->remainingLabel() ?? $card['noTarget'] }}</span></div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($cycle->isLocked())
                <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)" data-backlinks-locked>This reporting month is locked. Backlink records are read-only.</p>
            @endif
        @elseif ($cycles->isEmpty())
            <p {!! $muted !!} data-backlinks-no-cycles>No monthly cycles yet. Progress appears once the project has a reporting month.</p>
        @else
            <p {!! $muted !!} data-backlinks-all-time>All-time view: every backlink recorded for this project. Select a reporting month to view target progress.</p>
        @endif
    </x-filament::section>

    {{-- Live link breakdown (selected month only) --}}
    @if ($cycle)
        <x-filament::section>
            <x-slot name="heading">Live link breakdown</x-slot>
            <x-slot name="description">Live links only, for {{ $cycle->periodLabel() }}.</x-slot>

            @if ($breakdown === [])
                <p {!! $muted !!} data-backlinks-breakdown-empty>No live links in {{ $cycle->periodLabel() }} yet.</p>
            @else
                <div {{ $grid(['default' => 2, 'sm' => 3, 'lg' => 4], 3, ['data-backlinks-breakdown' => '']) }}>
                    @foreach ($breakdown as $typeLabel => $typeCount)
                        <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 3) calc(var(--spacing) * 4); display: flex; align-items: center; justify-content: space-between; gap: calc(var(--spacing) * 3)">
                            <span class="fi-in-text-item" style="font-size: var(--text-sm)">{{ $typeLabel }}</span>
                            <span class="fi-in-text-item" style="font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums" data-type-breakdown="{{ \Illuminate\Support\Str::slug($typeLabel, '_') }}">{{ $typeCount }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Backlink records --}}
    <div style="display: grid; gap: calc(var(--spacing) * 3)">
        <div style="display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 2)">
            <h2 class="fi-section-header-heading" data-backlinks-table-heading>{{ $cycle ? 'Backlinks in '.$cycle->periodLabel() : 'All backlinks' }}</h2>
            @if ($count > 0)
                <span {!! $muted !!} data-backlinks-summary>{{ $count }} {{ $count === 1 ? 'backlink' : 'backlinks' }} recorded{{ $backlinks?->actual !== null ? ' · '.$backlinks->actual.' live' : '' }}</span>
            @endif
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
