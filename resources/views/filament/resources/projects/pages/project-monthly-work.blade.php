<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $groups = $this->getGroupedNotes();
        $canManage = $this->canManageSelectedCycle();

        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);

        $lanes = [
            'wins' => [
                'heading' => 'Wins',
                'description' => 'Record positive results, completed work and notable improvements.',
                'types' => [\App\Enums\MonthlyNoteType::Win],
                'empty' => 'No wins recorded yet',
                'emptyHelp' => 'Add notable results or positive progress from this month.',
            ],
            'challenges' => [
                'heading' => 'Challenges & Observations',
                'description' => 'Capture issues, delays and useful findings from the month.',
                'types' => [\App\Enums\MonthlyNoteType::Challenge, \App\Enums\MonthlyNoteType::Observation],
                'empty' => 'No challenges or observations yet',
                'emptyHelp' => 'Capture anything that affected progress or is worth remembering.',
            ],
            'recommendations' => [
                'heading' => 'Recommendations',
                'description' => 'Actions or improvements you recommend based on this month\'s work. Recommendations can be included in the monthly report.',
                'types' => [\App\Enums\MonthlyNoteType::Recommendation],
                'empty' => 'No recommendations yet',
                'emptyHelp' => 'Add recommendations to help prepare the monthly report.',
            ],
            'focus' => [
                'heading' => 'Next month focus',
                'description' => 'Record the main priorities for the following reporting month.',
                'types' => [\App\Enums\MonthlyNoteType::NextMonthFocus],
                'empty' => 'No next-month focus yet',
                'emptyHelp' => 'Add the main priorities for the next reporting period.',
            ],
        ];
        $columns = [['wins', 'challenges'], ['recommendations', 'focus']];
        $summary = [
            ['key' => 'wins', 'label' => 'Wins'],
            ['key' => 'challenges', 'label' => 'Challenges / Observations'],
            ['key' => 'recommendations', 'label' => 'Recommendations'],
            ['key' => 'focus', 'label' => 'Next month focus'],
        ];
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('monthly-work')])

    {{-- Module title and the page-level Add note action --}}
    <div style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: calc(var(--spacing) * 4)" data-monthly-work-header>
        <div style="display: grid; gap: calc(var(--spacing) * 1)">
            <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Monthly work</h2>
            <p {!! $muted !!}>Capture wins, challenges, observations and recommendations throughout the month.</p>
        </div>
        @if ($canManage)
            <div data-monthly-work-add>{{ ($this->addNoteAction)([]) }}</div>
        @endif
    </div>

    {{-- Reporting month: selector, status, note counts --}}
    <x-filament::section>
        <x-slot name="heading">{{ $cycle?->periodLabel() ?? 'Reporting month' }}</x-slot>
        <x-slot name="description">Notes belong to the month they describe and help prepare that month's report.</x-slot>
        <x-slot name="afterHeader">
            @if ($cycles->isNotEmpty())
                <div {!! $row !!} @if ($cycle) data-selected-cycle="{{ $cycle->getKey() }}" @endif>
                    <label for="monthly-work-cycle" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Reporting month</label>
                    <div style="min-width: 12rem">
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="monthly-work-cycle" wire:model.live="selectedCycle">
                                @foreach ($cycles as $option)
                                    <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    @if ($cycle)
                        <x-filament::badge :color="$cycle->status->getColor()" data-notes-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                    @endif
                </div>
            @endif
        </x-slot>

        @if ($cycle)
            <div {{ $grid(['default' => 2, 'xl' => 4], 4, ['data-notes-summary' => '']) }}>
                @foreach ($summary as $card)
                    <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-notes-card="{{ $card['key'] }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div class="fi-wi-stats-overview-stat-value" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums" data-notes-count="{{ $groups[$card['key']]->count() }}">{{ $groups[$card['key']]->count() }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($cycle->isLocked())
                <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)" data-notes-locked>This reporting month is locked. Monthly notes are read-only.</p>
            @endif
        @else
            <p {!! $muted !!} data-notes-no-cycles>This project has no monthly cycles yet. Notes can be added once it has a reporting month.</p>
        @endif
    </x-filament::section>

    @if ($cycle)
        {{-- Wins + Challenges on the left, Recommendations + Next month focus on the right --}}
        <div {{ $grid(['default' => 1, 'xl' => 2], 6, ['data-notes-lanes' => '']) }}>
            @foreach ($columns as $column)
                <div style="display: grid; gap: calc(var(--spacing) * 6); align-content: start">
                    @foreach ($column as $lane)
                        @php $config = $lanes[$lane]; $notes = $groups[$lane]; @endphp
                        <x-filament::section>
                            <x-slot name="heading">{{ $config['heading'] }}</x-slot>
                            <x-slot name="description">{{ $config['description'] }}</x-slot>
                            @if ($canManage)
                                <x-slot name="afterHeader">
                                    <div {!! $row !!} data-lane-actions="{{ $lane }}">
                                        @foreach ($config['types'] as $type)
                                            {{ ($this->addLaneNoteAction)(['type' => $type->value]) }}
                                        @endforeach
                                    </div>
                                </x-slot>
                            @endif

                            @if ($notes->isEmpty())
                                <div style="display: grid; gap: calc(var(--spacing) * 1.5); padding: calc(var(--spacing) * 1) 0" data-empty-lane="{{ $lane }}">
                                    <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">{{ $config['empty'] }}</div>
                                    <p {!! $muted !!}>{{ $config['emptyHelp'] }}</p>
                                </div>
                            @else
                                <ul style="display: grid; gap: calc(var(--spacing) * 3); margin: 0; padding: 0; list-style: none" data-lane="{{ $lane }}">
                                    @foreach ($notes as $note)
                                        <li style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 4); border: 1px solid color-mix(in oklab, var(--gray-500) 22%, transparent); border-radius: var(--radius-lg)" data-note="{{ $note->getKey() }}" data-note-type="{{ $note->type->value }}">
                                            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: calc(var(--spacing) * 2)">
                                                <x-filament::badge :color="$note->type->getColor()">{{ $note->type->getLabel() }}</x-filament::badge>
                                                @if ($canManage)
                                                    <div {!! $row !!} data-note-actions>
                                                        {{ ($this->editNoteAction)(['note' => $note->getKey()]) }}
                                                        {{ ($this->deleteNoteAction)(['note' => $note->getKey()]) }}
                                                    </div>
                                                @endif
                                            </div>
                                            @if (filled($note->title))
                                                <div class="fi-in-text-item" style="font-size: var(--text-base); font-weight: var(--font-weight-semibold); overflow-wrap: anywhere" data-note-title>{{ $note->title }}</div>
                                            @endif
                                            <p class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); line-height: 1.6; white-space: pre-line; overflow-wrap: anywhere" data-note-body>{{ $note->body }}</p>
                                            <p class="fi-section-header-description" style="margin: 0; font-size: var(--text-xs)" data-note-meta>{{ $note->createdBy?->name ?? 'Unknown' }} • {{ $note->created_at?->format('j M Y') }}</p>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-filament::section>
                    @endforeach
                </div>
            @endforeach
        </div>

        <p {!! $muted !!} data-monthly-work-hint>These notes help prepare the Executive Summary, Recommendations and Next Month Focus sections of the monthly report.</p>
    @endif
</x-filament-panels::page>
