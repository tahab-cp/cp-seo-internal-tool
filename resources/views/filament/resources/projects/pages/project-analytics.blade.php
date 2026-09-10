<x-filament-panels::page>
    @php
        $project = $this->getProject();
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $gsc = $this->getGscSummary();
        $queries = $this->getGscQueries();
        $pages = $this->getGscPages();
        $ga4 = $this->getGa4Summary();
        $countries = $this->getGa4Countries();
        $authority = $this->getAuthority();
        $canManage = $this->canManageSelectedCycle();
        $period = $cycle?->periodLabel() ?? 'this month';

        // Presentation formatting only: thousands separators, trimmed decimals, stored values untouched.
        $int = fn ($value) => $value === null ? '—' : number_format((int) $value);
        $num = fn ($value, string $suffix = '') => $value === null ? '—' : rtrim(rtrim(number_format((float) $value, 2), '0'), '.').$suffix;

        $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);

        $th = 'class="fi-section-header-description" style="padding: calc(var(--spacing) * 2) calc(var(--spacing) * 3); font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap"';
        $thNum = str_replace('white-space: nowrap"', 'white-space: nowrap; text-align: right"', $th);
        $td = 'class="fi-in-text-item" style="padding: calc(var(--spacing) * 2.5) calc(var(--spacing) * 3); font-size: var(--text-sm); border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent)"';
        $tdNum = str_replace('font-size: var(--text-sm);', 'font-size: var(--text-sm); text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;', $td);
        $tdStrong = str_replace('font-size: var(--text-sm);', 'font-size: var(--text-sm); font-weight: var(--font-weight-semibold); overflow-wrap: anywhere;', $td);
        $tableWrap = 'style="overflow-x: auto; border: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent); border-radius: var(--radius-lg)"';
        $table = 'style="width: 100%; border-collapse: collapse; text-align: left"';

        $sections = [
            ['id' => 'gsc', 'label' => 'Search Console', 'icon' => 'heroicon-o-magnifying-glass'],
            ['id' => 'ga4', 'label' => 'Google Analytics', 'icon' => 'heroicon-o-chart-bar'],
            ['id' => 'authority', 'label' => 'Authority', 'icon' => 'heroicon-o-shield-check'],
        ];

        $overview = [
            ['key' => 'clicks', 'label' => 'Organic clicks', 'value' => $int($gsc?->clicks), 'helper' => 'Search Console'],
            ['key' => 'impressions', 'label' => 'Organic impressions', 'value' => $int($gsc?->impressions), 'helper' => 'Search Console'],
            ['key' => 'organic-sessions', 'label' => 'Organic sessions', 'value' => $int($ga4?->organic_sessions), 'helper' => 'Google Analytics'],
            ['key' => 'active-users', 'label' => 'Active users', 'value' => $int($ga4?->active_users), 'helper' => 'Google Analytics'],
        ];
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('analytics')])

    {{-- Module title --}}
    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-analytics-header>
        <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">Analytics</h2>
        <p {!! $muted !!}>Track monthly search, website traffic and authority performance.</p>
    </div>

    {{-- Month controls, overview cards and section links --}}
    <x-filament::section>
        <x-slot name="heading">{{ $cycle?->periodLabel() ?? 'Reporting month' }}</x-slot>
        <x-slot name="description">Analytics belong to the month they describe. Switching months shows that month's own figures.</x-slot>
        <x-slot name="afterHeader">
            @if ($cycles->isNotEmpty())
                <div {!! $row !!} @if ($cycle) data-selected-cycle="{{ $cycle->getKey() }}" @endif>
                    <label for="analytics-cycle" class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em">Reporting month</label>
                    <div style="min-width: 12rem">
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="analytics-cycle" wire:model.live="selectedCycle">
                                @foreach ($cycles as $option)
                                    <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}{{ $option->isLocked() ? ' (locked)' : '' }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    @if ($cycle)
                        <x-filament::badge :color="$cycle->status->getColor()" data-analytics-cycle-status="{{ $cycle->status->value }}">{{ $cycle->status->getLabel() }}</x-filament::badge>
                    @endif
                </div>
            @endif
        </x-slot>

        @if ($cycle)
            <div {{ $grid(['default' => 2, 'xl' => 4], 4, ['data-analytics-overview' => '']) }}>
                @foreach ($overview as $card)
                    <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)" data-analytics-overview-card="{{ $card['key'] }}">
                        <div class="fi-wi-stats-overview-stat-content">
                            <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                            <div class="fi-wi-stats-overview-stat-value" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums">{{ $card['value'] }}</div>
                            <div class="fi-wi-stats-overview-stat-description"><span>{{ $card['helper'] }}</span></div>
                        </div>
                    </div>
                @endforeach
            </div>

            <nav aria-label="Analytics sections" style="display: flex; flex-wrap: wrap; gap: calc(var(--spacing) * 2); margin-top: calc(var(--spacing) * 5)" data-analytics-sections>
                @foreach ($sections as $section)
                    <x-filament::button tag="a" href="#{{ $section['id'] }}" color="gray" outlined :icon="$section['icon']" size="sm" data-analytics-section-link="{{ $section['id'] }}">{{ $section['label'] }}</x-filament::button>
                @endforeach
            </nav>

            @if ($cycle->isLocked())
                <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 4); font-size: var(--text-sm)" data-analytics-locked>This reporting month is locked. Analytics data is read-only.</p>
            @endif
        @else
            <p {!! $muted !!} data-analytics-no-cycles>This project has no monthly cycles yet. Analytics can be recorded once it has a reporting month.</p>
        @endif
    </x-filament::section>

    @if ($cycle)
        {{-- 1. Google Search Console --}}
        <div id="gsc" style="display: grid; gap: calc(var(--spacing) * 6)">
            <x-filament::section>
                <x-slot name="heading">Google Search Console</x-slot>
                <x-slot name="description">Organic search performance for {{ $period }}.</x-slot>
                <x-slot name="afterHeader">
                    <div {!! $row !!}>
                        <x-filament::badge :color="$gsc?->source->getColor() ?? 'gray'" data-gsc-source="{{ $gsc?->source->value ?? 'manual' }}">{{ $gsc?->source->getLabel() ?? 'Manual Entry' }}</x-filament::badge>
                        {{ $this->editGscSummaryAction }}
                    </div>
                </x-slot>

                @if ($gsc)
                    @php
                        $gscCards = [
                            ['label' => 'Clicks', 'value' => $int($gsc->clicks), 'attr' => 'data-gsc-clicks="'.e($gsc->clicks).'"'],
                            ['label' => 'Impressions', 'value' => $int($gsc->impressions), 'attr' => 'data-gsc-impressions="'.e($gsc->impressions).'"'],
                            ['label' => 'CTR', 'value' => $num($gsc->ctr, '%'), 'attr' => 'data-gsc-ctr="'.e($gsc->ctr ?? '').'"'],
                            ['label' => 'Average position', 'value' => $num($gsc->average_position), 'attr' => 'data-gsc-position="'.e($gsc->average_position ?? '').'"'],
                        ];
                    @endphp
                    <div {{ $grid(['default' => 2, 'lg' => 4], 4, ['data-gsc-summary' => $gsc->getKey()]) }}>
                        @foreach ($gscCards as $card)
                            <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)">
                                <div class="fi-wi-stats-overview-stat-content">
                                    <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                                    <div class="fi-wi-stats-overview-stat-value" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums" {!! $card['attr'] !!}>{{ $card['value'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <p class="fi-section-header-description" style="margin-top: calc(var(--spacing) * 3); font-size: var(--text-xs)">Lower average position is better. Entered by {{ $gsc->enteredBy?->name ?? 'unknown' }} · {{ $gsc->updated_at?->format('j M Y H:i') }}</p>
                @else
                    <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 2) 0" data-gsc-empty>
                        <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No Search Console data yet</div>
                        <p {!! $muted !!}>Add this month's GSC figures to include organic search performance in the monthly report for {{ $period }}.</p>
                        @if ($canManage)
                            <div><x-filament::button size="sm" icon="heroicon-o-plus" wire:click="mountAction('editGscSummary')" data-gsc-add>Add GSC data</x-filament::button></div>
                        @endif
                    </div>
                @endif
            </x-filament::section>

            {{-- Top search queries --}}
            <x-filament::section>
                <x-slot name="heading">Top search queries</x-slot>
                <x-slot name="description">Queries generating organic search visibility during this reporting month. Lower average position is better.</x-slot>
                <x-slot name="afterHeader">{{ $this->editGscQueriesAction }}</x-slot>

                @if ($queries->isEmpty())
                    <p {!! $muted !!} data-gsc-queries-empty>No queries recorded for {{ $period }} yet.</p>
                @else
                    <div {!! $tableWrap !!}>
                        <table {!! $table !!} data-gsc-queries>
                            <thead><tr><th {!! $th !!}>Query</th><th {!! $thNum !!}>Clicks</th><th {!! $thNum !!}>Impressions</th><th {!! $thNum !!}>CTR</th><th {!! $thNum !!}>Position</th></tr></thead>
                            <tbody>
                                @foreach ($queries as $query)
                                    <tr data-gsc-query="{{ $query->query }}">
                                        <td {!! $tdStrong !!}>{{ $query->query }}</td>
                                        <td {!! $tdNum !!} style="font-weight: var(--font-weight-semibold)">{{ $int($query->clicks) }}</td>
                                        <td {!! $tdNum !!}>{{ $int($query->impressions) }}</td>
                                        <td {!! $tdNum !!}>{{ $num($query->ctr, '%') }}</td>
                                        <td {!! $tdNum !!}>{{ $num($query->average_position) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>

            {{-- Top landing pages --}}
            <x-filament::section>
                <x-slot name="heading">Top landing pages</x-slot>
                <x-slot name="description">Pages receiving organic search traffic during this reporting month.</x-slot>
                <x-slot name="afterHeader">{{ $this->editGscPagesAction }}</x-slot>

                @if ($pages->isEmpty())
                    <p {!! $muted !!} data-gsc-pages-empty>No landing pages recorded for {{ $period }} yet.</p>
                @else
                    <div {!! $tableWrap !!}>
                        <table {!! $table !!} data-gsc-pages>
                            <thead><tr><th {!! $th !!}>Page</th><th {!! $thNum !!}>Clicks</th><th {!! $thNum !!}>Impressions</th><th {!! $thNum !!}>CTR</th><th {!! $thNum !!}>Position</th></tr></thead>
                            <tbody>
                                @foreach ($pages as $landing)
                                    <tr data-gsc-page="{{ $landing->page_url }}" data-gsc-page-mapping="{{ $landing->page_id ?? '' }}">
                                        <td {!! $td !!} style="max-width: 24rem">
                                            <div style="display: grid; gap: calc(var(--spacing) * 0.5); min-width: 0">
                                                @if ($landing->page)
                                                    <span style="font-weight: var(--font-weight-semibold); overflow-wrap: anywhere">{{ $landing->page->displayName() }}</span>
                                                    <x-filament::link :href="$landing->page_url" target="_blank" rel="noopener noreferrer" size="sm" color="gray" :tooltip="$landing->page_url" style="overflow-wrap: anywhere">{{ $this->landingPagePath($landing) }}</x-filament::link>
                                                @else
                                                    <x-filament::link :href="$landing->page_url" target="_blank" rel="noopener noreferrer" size="sm" :tooltip="$landing->page_url" style="font-weight: var(--font-weight-semibold); overflow-wrap: anywhere">{{ $this->landingPagePath($landing) }}</x-filament::link>
                                                @endif
                                            </div>
                                        </td>
                                        <td {!! $tdNum !!} style="font-weight: var(--font-weight-semibold)">{{ $int($landing->clicks) }}</td>
                                        <td {!! $tdNum !!}>{{ $int($landing->impressions) }}</td>
                                        <td {!! $tdNum !!}>{{ $num($landing->ctr, '%') }}</td>
                                        <td {!! $tdNum !!}>{{ $num($landing->average_position) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- 2. Google Analytics --}}
        <div id="ga4" style="display: grid; gap: calc(var(--spacing) * 6)">
            <x-filament::section>
                <x-slot name="heading">Google Analytics</x-slot>
                <x-slot name="description">Website traffic for {{ $period }}.</x-slot>
                <x-slot name="afterHeader">
                    <div {!! $row !!}>
                        <x-filament::badge :color="$ga4?->source->getColor() ?? 'gray'" data-ga4-source="{{ $ga4?->source->value ?? 'manual' }}">{{ $ga4?->source->getLabel() ?? 'Manual Entry' }}</x-filament::badge>
                        {{ $this->editGa4SummaryAction }}
                    </div>
                </x-slot>

                @if ($ga4)
                    @php
                        $ga4Cards = [
                            ['label' => 'Active users', 'value' => $int($ga4->active_users), 'attr' => 'data-ga4-active-users="'.e($ga4->active_users ?? '').'"'],
                            ['label' => 'Sessions', 'value' => $int($ga4->sessions), 'attr' => 'data-ga4-sessions="'.e($ga4->sessions ?? '').'"'],
                            ['label' => 'Organic sessions', 'value' => $int($ga4->organic_sessions), 'attr' => 'data-ga4-organic-sessions="'.e($ga4->organic_sessions ?? '').'"'],
                            ['label' => 'Engagement rate', 'value' => $num($ga4->engagement_rate, '%'), 'attr' => 'data-ga4-engagement-rate="'.e($ga4->engagement_rate ?? '').'"'],
                        ];
                        $ga4Secondary = [
                            ['label' => 'New users', 'value' => $int($ga4->new_users), 'key' => 'new-users'],
                            ['label' => 'Engaged sessions', 'value' => $int($ga4->engaged_sessions), 'key' => 'engaged-sessions'],
                            ['label' => 'Avg engagement time', 'value' => $this->formatDuration($ga4->average_engagement_time_seconds), 'key' => 'engagement-time'],
                            ['label' => 'Event count', 'value' => $int($ga4->event_count), 'key' => 'event-count'],
                            ['label' => 'Key events', 'value' => $int($ga4->key_events), 'key' => 'key-events'],
                        ];
                    @endphp
                    <div {{ $grid(['default' => 2, 'lg' => 4], 4, ['data-ga4-summary' => $ga4->getKey()]) }}>
                        @foreach ($ga4Cards as $card)
                            <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4)">
                                <div class="fi-wi-stats-overview-stat-content">
                                    <div class="fi-wi-stats-overview-stat-label-ctn"><span class="fi-wi-stats-overview-stat-label">{{ $card['label'] }}</span></div>
                                    <div class="fi-wi-stats-overview-stat-value" style="font-size: var(--text-2xl); line-height: var(--text-2xl--line-height); font-variant-numeric: tabular-nums" {!! $card['attr'] !!}>{{ $card['value'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <dl {{ $grid(['default' => 1, 'sm' => 2, 'lg' => 5], 3, ['data-ga4-secondary' => '', 'style' => 'margin-top: calc(var(--spacing) * 4); gap: calc(var(--spacing) * 3)']) }}>
                        @foreach ($ga4Secondary as $metric)
                            <div style="display: flex; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 3); padding: calc(var(--spacing) * 2) calc(var(--spacing) * 3); border: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent); border-radius: var(--radius-lg)">
                                <dt class="fi-section-header-description" style="font-size: var(--text-xs)">{{ $metric['label'] }}</dt>
                                <dd class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums; white-space: nowrap" data-ga4-metric="{{ $metric['key'] }}">{{ $metric['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="fi-section-header-description" style="margin-top: calc(var(--spacing) * 3); font-size: var(--text-xs)">Entered by {{ $ga4->enteredBy?->name ?? 'unknown' }} · {{ $ga4->updated_at?->format('j M Y H:i') }}</p>
                @else
                    <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 2) 0" data-ga4-empty>
                        <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No Google Analytics data yet</div>
                        <p {!! $muted !!}>Add this month's GA4 figures to include website traffic in the monthly report for {{ $period }}.</p>
                        @if ($canManage)
                            <div><x-filament::button size="sm" icon="heroicon-o-plus" wire:click="mountAction('editGa4Summary')" data-ga4-add>Add GA4 data</x-filament::button></div>
                        @endif
                    </div>
                @endif
            </x-filament::section>

            {{-- Audience by country --}}
            <x-filament::section>
                <x-slot name="heading">Audience by country</x-slot>
                <x-slot name="description">Where this month's visitors came from.</x-slot>
                <x-slot name="afterHeader">{{ $this->editGa4CountriesAction }}</x-slot>

                @if ($countries->isEmpty())
                    <p {!! $muted !!} data-ga4-countries-empty>No country data recorded for {{ $period }} yet.</p>
                @else
                    <div {!! $tableWrap !!}>
                        <table {!! $table !!} data-ga4-countries>
                            <thead><tr><th {!! $th !!}>Country</th><th {!! $thNum !!}>Active users</th><th {!! $thNum !!}>New users</th><th {!! $thNum !!}>Sessions</th><th {!! $thNum !!}>Engagement rate</th></tr></thead>
                            <tbody>
                                @foreach ($countries as $country)
                                    <tr data-ga4-country="{{ $country->country }}">
                                        <td {!! $tdStrong !!}>{{ $country->country }}</td>
                                        <td {!! $tdNum !!} style="font-weight: var(--font-weight-semibold)">{{ $int($country->active_users) }}</td>
                                        <td {!! $tdNum !!}>{{ $int($country->new_users) }}</td>
                                        <td {!! $tdNum !!}>{{ $int($country->sessions) }}</td>
                                        <td {!! $tdNum !!}>{{ $num($country->engagement_rate, '%') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        </div>

        {{-- 3. Site authority --}}
        <div id="authority">
            <x-filament::section>
                <x-slot name="heading">Site authority</x-slot>
                <x-slot name="description">These are site-wide authority metrics. Monthly link-building work is tracked separately under Backlinks.</x-slot>
                <x-slot name="afterHeader">
                    <div {!! $row !!}>
                        <x-filament::badge :color="$authority?->source->getColor() ?? 'gray'" data-authority-source="{{ $authority?->source->value ?? 'manual' }}">{{ $authority?->source->getLabel() ?? 'Manual Entry' }}</x-filament::badge>
                        {{ $this->editAuthorityAction }}
                    </div>
                </x-slot>

                @if ($authority)
                    @php
                        $groups = [
                            ['key' => 'moz', 'label' => 'Moz', 'metrics' => [
                                ['label' => 'Domain Authority', 'value' => $int($authority->moz_domain_authority), 'attr' => 'data-authority-da="'.e($authority->moz_domain_authority ?? '').'"'],
                                ['label' => 'Linking root domains', 'value' => $int($authority->moz_linking_root_domains), 'attr' => ''],
                            ]],
                            ['key' => 'ahrefs', 'label' => 'Ahrefs', 'metrics' => [
                                ['label' => 'Domain Rating', 'value' => $num($authority->ahrefs_domain_rating), 'attr' => 'data-authority-dr="'.e($authority->ahrefs_domain_rating ?? '').'"'],
                                ['label' => 'URL Rating', 'value' => $num($authority->ahrefs_url_rating), 'attr' => ''],
                            ]],
                            ['key' => 'link-profile', 'label' => 'Link profile', 'metrics' => [
                                ['label' => 'Known backlinks', 'value' => $int($authority->backlinks_count), 'attr' => 'data-authority-backlinks="'.e($authority->backlinks_count ?? '').'"'],
                                ['label' => 'Referring domains', 'value' => $int($authority->referring_domains_count), 'attr' => ''],
                            ]],
                        ];
                    @endphp
                    <div {{ $grid(['default' => 1, 'md' => 3], 4, ['data-authority' => $authority->getKey()]) }}>
                        @foreach ($groups as $group)
                            <div class="fi-wi-stats-overview-stat" style="padding: calc(var(--spacing) * 4); display: grid; gap: calc(var(--spacing) * 3)" data-authority-group="{{ $group['key'] }}">
                                <div {!! $label !!}>{{ $group['label'] }}</div>
                                <dl style="display: grid; gap: calc(var(--spacing) * 2); margin: 0">
                                    @foreach ($group['metrics'] as $metric)
                                        <div style="display: flex; align-items: baseline; justify-content: space-between; gap: calc(var(--spacing) * 3)">
                                            <dt class="fi-section-header-description" style="font-size: var(--text-sm)">{{ $metric['label'] }}</dt>
                                            <dd class="fi-in-text-item" style="margin: 0; font-size: var(--text-xl); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums" {!! $metric['attr'] !!}>{{ $metric['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endforeach
                    </div>
                    @if ($authority->notes)
                        <p class="fi-in-text-item" style="margin-top: calc(var(--spacing) * 3); font-size: var(--text-sm); white-space: pre-line" data-authority-notes>{{ $authority->notes }}</p>
                    @endif
                    <p class="fi-section-header-description" style="margin-top: calc(var(--spacing) * 3); font-size: var(--text-xs)">Entered by {{ $authority->enteredBy?->name ?? 'unknown' }} · {{ $authority->updated_at?->format('j M Y H:i') }}</p>
                @else
                    <div style="display: grid; gap: calc(var(--spacing) * 2); padding: calc(var(--spacing) * 2) 0" data-authority-empty>
                        <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)">No authority metrics yet</div>
                        <p {!! $muted !!}>Add this month's Moz, Ahrefs and link profile figures for {{ $period }}.</p>
                        @if ($canManage)
                            <div><x-filament::button size="sm" icon="heroicon-o-plus" wire:click="mountAction('editAuthority')" data-authority-add>Add authority data</x-filament::button></div>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
