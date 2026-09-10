<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $keyword = $this->getKeyword();
        $target = $keyword->targetPage;
        $latest = $keyword->latestSnapshot;
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $summary = $this->getMonthlySummary();
        $movement = $summary?->movement();
        $canRecord = \Illuminate\Support\Facades\Gate::allows('recordRankings', $project);
        $meta = implode(' • ', array_filter([$keyword->search_intent?->getLabel(), $keyword->keyword_role?->getLabel(), $keyword->displayLocation()]));

        $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $value = 'class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); overflow-wrap: anywhere"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $stack = 'style="display: grid; gap: calc(var(--spacing) * 1)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);

        // Semantic tone of the derived movement (RankingMovementService output, never recalculated here).
        $tone = match ($movement?->direction) {
            \App\Support\Rankings\RankingMovement::IMPROVED, \App\Support\Rankings\RankingMovement::ENTERED => 'success',
            \App\Support\Rankings\RankingMovement::DECLINED => 'danger',
            \App\Support\Rankings\RankingMovement::DROPPED => 'warning',
            default => 'gray',
        };
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('keywords')])

    {{-- Keyword header: title, status, meta line, target page and the latest check --}}
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: calc(var(--spacing) * 4)" data-keyword-header data-keyword-status="{{ $keyword->status->value }}">
        <div style="display: grid; gap: calc(var(--spacing) * 2); min-width: 0">
            <div {!! $row !!}>
                <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height); overflow-wrap: anywhere" data-keyword-title>{{ $keyword->keyword }}</h2>
                <x-filament::badge :color="$keyword->status->getColor()" size="lg">{{ $keyword->status->getLabel() }}</x-filament::badge>
                @if ($keyword->is_branded)
                    <x-filament::badge color="gray" data-keyword-branded>Branded</x-filament::badge>
                @endif
            </div>
            <p {!! $muted !!} data-keyword-meta>{{ $meta }}</p>
            <div {!! $stack !!} data-keyword-target>
                <span {!! $label !!}>Target page</span>
                @if ($target)
                    @php $targetUrl = $this->targetPageUrl($target); @endphp
                    <div style="display: grid; gap: calc(var(--spacing) * 0.5)">
                        @if ($targetUrl)
                            <x-filament::link :href="$targetUrl" size="sm" style="font-weight: var(--font-weight-semibold)" data-keyword-target-page="{{ $target->getKey() }}">{{ $target->displayName() }}</x-filament::link>
                        @else
                            <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold)" data-keyword-target-page="{{ $target->getKey() }}">{{ $target->displayName() }}</span>
                        @endif
                        <span class="fi-section-header-description" style="font-size: var(--text-xs); overflow-wrap: anywhere">{{ $this->targetPagePath($target) }}</span>
                    </div>
                @else
                    <span {!! $muted !!} data-keyword-target-page="">No target page assigned</span>
                @endif
            </div>
        </div>

        @if ($latest)
            <div style="display: flex; flex-wrap: wrap; gap: calc(var(--spacing) * 6)" data-keyword-latest>
                <div {!! $stack !!}>
                    <span {!! $label !!}>Latest rank</span>
                    <span class="fi-in-text-item" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums" data-keyword-latest-rank>{{ $latest->positionLabel() }}</span>
                </div>
                <div {!! $stack !!}>
                    <span {!! $label !!}>Last checked</span>
                    <span class="fi-in-text-item" style="font-size: var(--text-sm)" data-keyword-last-checked="{{ $latest->checked_at->toDateString() }}">{{ $latest->checked_at->format('j M Y') }}</span>
                </div>
            </div>
        @endif
    </div>

    {{-- Keyword details | Monthly ranking summary --}}
    <div {{ $grid(['default' => 1, 'xl' => 2], 6) }}>
        <x-filament::section>
            <x-slot name="heading">Keyword details</x-slot>
            <x-slot name="description">Keyword details can be updated at any time. Ranking records from a locked reporting month are read-only.</x-slot>

            <dl {{ $grid(['default' => 1, 'sm' => 2], 5, ['data-keyword-details' => '']) }}>
                <div {!! $stack !!}><dt {!! $label !!}>Keyword</dt><dd {!! $value !!} data-keyword>{{ $keyword->keyword }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Status</dt><dd style="margin: 0"><x-filament::badge :color="$keyword->status->getColor()">{{ $keyword->status->getLabel() }}</x-filament::badge></dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Target page</dt><dd {!! $value !!}>
                    @if ($target)
                        <span style="font-weight: var(--font-weight-medium)">{{ $target->displayName() }}</span>
                        <span class="fi-section-header-description" style="display: block; font-size: var(--text-xs)">{{ $this->targetPagePath($target) }}</span>
                    @else
                        No target page assigned
                    @endif
                </dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Role</dt><dd {!! $value !!} data-keyword-role>{{ $keyword->keyword_role?->getLabel() ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Search volume</dt><dd {!! $value !!} data-keyword-volume>{{ $keyword->search_volume !== null ? number_format($keyword->search_volume) : '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Keyword difficulty</dt><dd {!! $value !!} data-keyword-difficulty>{{ $keyword->keyword_difficulty ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Intent</dt><dd {!! $value !!} data-keyword-intent>{{ $keyword->search_intent?->getLabel() ?? '—' }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Location</dt><dd {!! $value !!} data-keyword-location>{{ $keyword->displayLocation() }}</dd></div>
                <div {!! $stack !!}><dt {!! $label !!}>Branded</dt><dd {!! $value !!}>{{ $keyword->is_branded ? 'Yes' : 'No' }}</dd></div>
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Monthly ranking summary</x-slot>
            <x-slot name="description">Lower positions are better. Movement compares the first and latest checks of the month.</x-slot>
            <x-slot name="afterHeader">
                @if ($cycles->isNotEmpty())
                    <div {!! $row !!} data-keyword-period="{{ $cycle?->periodLabel() }}">
                        <label for="keyword-cycle" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Reporting month</label>
                        <div style="min-width: 12rem">
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="keyword-cycle" wire:model.live="selectedCycleId">
                                    @foreach ($cycles as $option)
                                        <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        @if ($cycle)
                            <x-filament::badge :color="$cycle->status->getColor()" data-keyword-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                        @endif
                    </div>
                @endif
            </x-slot>

            @if ($cycles->isEmpty())
                <p {!! $muted !!} data-keyword-no-cycles>No monthly cycles yet, so no ranking can be recorded for this keyword.</p>
            @elseif ($cycle && $summary)
                @php
                    $hasData = $summary->snapshotCount > 0;
                    $cards = [
                        ['key' => 'start', 'label' => 'Month start', 'value' => $summary->earliest ? $summary->monthStartLabel() : 'Not recorded', 'helper' => $summary->earliest ? 'Checked '.$summary->earliest->checked_at->format('j M') : 'No check yet', 'tone' => 'gray', 'attr' => 'data-month-start', 'large' => (bool) $summary->earliest],
                        ['key' => 'latest', 'label' => 'Latest position', 'value' => $summary->latest ? $summary->latestLabel() : 'Not recorded', 'helper' => $summary->latest ? 'Checked '.$summary->latest->checked_at->format('j M') : 'No check yet', 'tone' => 'gray', 'attr' => 'data-month-latest', 'large' => (bool) $summary->latest],
                        ['key' => 'movement', 'label' => 'Movement', 'value' => $summary->movementLabel(), 'helper' => $movement ? $movement->transition() : ($summary->snapshotCount === 1 ? 'Only one check this month' : 'Nothing to compare yet'), 'tone' => $tone, 'attr' => 'data-month-movement', 'large' => false],
                    ];
                @endphp

                <div {{ $grid(['default' => 1, 'sm' => 3], 4, ['data-keyword-metrics' => '']) }}>
                    @foreach ($cards as $card)
                        @php $flagged = $card['tone'] !== 'gray'; @endphp
                        <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-keyword-card="{{ $card['key'] }}">
                            <div class="fi-wi-stats-overview-stat-content">
                                <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                                <div class="fi-wi-stats-overview-stat-value{{ $flagged ? ' fi-color-'.$card['tone'].' fi-text-color-600 dark:fi-text-color-400' : '' }}" style="font-size: {{ $card['large'] ? 'var(--text-2xl)' : 'var(--text-lg)' }}; line-height: {{ $card['large'] ? 'var(--text-2xl--line-height)' : 'var(--text-lg--line-height)' }}; font-variant-numeric: tabular-nums;{{ $flagged ? ' color: var(--text);' : '' }}" {{ $card['attr'] }}>{{ $card['value'] }}</div>
                                <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (! $hasData)
                    <div style="margin-top: calc(var(--spacing) * 4); display: grid; gap: calc(var(--spacing) * 2)" data-keyword-month-empty>
                        <p {!! $muted !!}>No ranking checks have been recorded for {{ $cycle->periodLabel() }}.</p>
                        @if ($canRecord && ! $cycle->isLocked())
                            <div><x-filament::button size="sm" icon="heroicon-o-chart-bar" wire:click="mountAction('recordRanking')" data-keyword-record-this-month>Record ranking</x-filament::button></div>
                        @endif
                    </div>
                @endif

                @if ($cycle->isLocked())
                    <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)" data-keyword-month-locked>This reporting month is locked; its ranking records are read-only.</p>
                @endif
            @endif
        </x-filament::section>
    </div>

    {{-- Ranking history --}}
    <x-filament::section>
        <x-slot name="heading">Ranking history</x-slot>
        <x-slot name="description">Each ranking check is saved here. “Not Ranking” means the keyword was not found.</x-slot>

        @if ($latest === null)
            <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 2) 0" data-keyword-history-empty>
                <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No ranking history yet</div>
                <p {!! $muted !!}>Record the first ranking check for this keyword to start tracking movement.</p>
                @if ($canRecord)
                    <div><x-filament::button size="sm" icon="heroicon-o-chart-bar" wire:click="mountAction('recordRanking')" data-keyword-record-first>Record ranking</x-filament::button></div>
                @endif
            </div>
        @else
            {{ $this->table }}
        @endif
    </x-filament::section>

    {{--
        Filament's page layout leaves the action-modal container to the table view on
        HasTable pages. The table is only rendered once the keyword has history, so the
        container is included here explicitly (Filament renders it at most once per
        component) to keep the header actions working on a keyword without records.
    --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
