<x-filament-panels::page>
    @php
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $gsc = $this->getGscSummary();
        $queries = $this->getGscQueries();
        $pages = $this->getGscPages();
        $ga4 = $this->getGa4Summary();
        $countries = $this->getGa4Countries();
        $authority = $this->getAuthority();
        $label = $cycle?->periodLabel() ?? 'this month';
        $fmt = fn ($value, $suffix = '') => $value === null ? '—' : number_format((float) $value, is_int($value) ? 0 : 2).$suffix;
        $int = fn ($value) => $value === null ? '—' : number_format((int) $value);
    @endphp

    <x-filament::section>
        <x-slot name="heading">Reporting month</x-slot>
        <x-slot name="description">Analytics belong to the month they describe. Switching months shows that month's own figures.</x-slot>

        <div class="flex flex-wrap items-center gap-6">
            <div class="w-56">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedCycle">
                        @foreach ($cycles as $option)
                            <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($cycle)
                <div class="text-sm text-gray-600 dark:text-gray-300" data-selected-cycle="{{ $cycle->getKey() }}">
                    {{ $cycle->periodLabel() }}
                    @if ($cycle->isLocked())
                        <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                        <span class="text-xs text-gray-500">Read-only until a Super Admin unlocks the month.</span>
                    @else
                        <x-filament::badge color="success" size="sm">{{ $cycle->status->getLabel() }}</x-filament::badge>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">This project has no monthly cycles yet.</p>
            @endif
        </div>
    </x-filament::section>

    @if ($cycle)
        {{-- 1. Google Search Console --}}
        <x-filament::section>
            <x-slot name="heading">Google Search Console</x-slot>
            <x-slot name="description">Organic search performance for {{ $label }}. Source: <x-filament::badge color="{{ $gsc?->source->getColor() ?? 'gray' }}" size="sm">{{ $gsc?->source->getLabel() ?? 'Manual Entry' }}</x-filament::badge></x-slot>
            <x-slot name="afterHeader">{{ $this->editGscSummaryAction }}</x-slot>

            @if ($gsc)
                <dl class="grid grid-cols-2 gap-4 md:grid-cols-4" data-gsc-summary="{{ $gsc->getKey() }}">
                    <div><dt class="text-xs text-gray-500">Clicks</dt><dd class="text-2xl font-semibold" data-gsc-clicks="{{ $gsc->clicks }}">{{ $int($gsc->clicks) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Impressions</dt><dd class="text-2xl font-semibold" data-gsc-impressions="{{ $gsc->impressions }}">{{ $int($gsc->impressions) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">CTR</dt><dd class="text-2xl font-semibold" data-gsc-ctr="{{ $gsc->ctr ?? '' }}">{{ $fmt($gsc->ctr, '%') }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Average position</dt><dd class="text-2xl font-semibold" data-gsc-position="{{ $gsc->average_position ?? '' }}">{{ $fmt($gsc->average_position) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-gray-500">Entered by {{ $gsc->enteredBy?->name ?? 'unknown' }} · {{ $gsc->updated_at?->format('j M Y H:i') }}</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400" data-gsc-empty>No GSC data for {{ $label }}.</p>
            @endif

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold">Top queries</h3>
                        {{ $this->editGscQueriesAction }}
                    </div>
                    @if ($queries->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">No queries recorded for {{ $label }}.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Query</th><th class="py-1 text-right">Clicks</th><th class="py-1 text-right">Impr.</th><th class="py-1 text-right">CTR</th><th class="py-1 text-right">Pos.</th></tr></thead>
                                <tbody>
                                    @foreach ($queries as $row)
                                        <tr class="border-t border-gray-100 dark:border-gray-800" data-gsc-query="{{ $row->query }}">
                                            <td class="py-1">{{ $row->query }}</td>
                                            <td class="py-1 text-right">{{ $int($row->clicks) }}</td>
                                            <td class="py-1 text-right">{{ $int($row->impressions) }}</td>
                                            <td class="py-1 text-right">{{ $fmt($row->ctr, '%') }}</td>
                                            <td class="py-1 text-right">{{ $fmt($row->average_position) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div>
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold">Landing pages</h3>
                        {{ $this->editGscPagesAction }}
                    </div>
                    @if ($pages->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">No landing pages recorded for {{ $label }}.</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Page</th><th class="py-1 text-right">Clicks</th><th class="py-1 text-right">Impr.</th><th class="py-1 text-right">CTR</th><th class="py-1 text-right">Pos.</th></tr></thead>
                                <tbody>
                                    @foreach ($pages as $row)
                                        <tr class="border-t border-gray-100 dark:border-gray-800" data-gsc-page="{{ $row->page_url }}" data-gsc-page-mapping="{{ $row->page_id ?? '' }}">
                                            <td class="py-1">
                                                <a href="{{ $row->page_url }}" target="_blank" rel="noopener" class="underline">{{ \Illuminate\Support\Str::limit($row->page_url, 60) }}</a>
                                                @if ($row->page)
                                                    <span class="ml-1 text-xs text-gray-500">→ {{ $row->page->displayName() }}</span>
                                                @endif
                                            </td>
                                            <td class="py-1 text-right">{{ $int($row->clicks) }}</td>
                                            <td class="py-1 text-right">{{ $int($row->impressions) }}</td>
                                            <td class="py-1 text-right">{{ $fmt($row->ctr, '%') }}</td>
                                            <td class="py-1 text-right">{{ $fmt($row->average_position) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </x-filament::section>

        {{-- 2. Google Analytics --}}
        <x-filament::section>
            <x-slot name="heading">Google Analytics</x-slot>
            <x-slot name="description">Website traffic for {{ $label }}. Source: <x-filament::badge color="{{ $ga4?->source->getColor() ?? 'gray' }}" size="sm">{{ $ga4?->source->getLabel() ?? 'Manual Entry' }}</x-filament::badge></x-slot>
            <x-slot name="afterHeader">{{ $this->editGa4SummaryAction }}</x-slot>

            @if ($ga4)
                <dl class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5" data-ga4-summary="{{ $ga4->getKey() }}">
                    <div><dt class="text-xs text-gray-500">Active users</dt><dd class="text-xl font-semibold" data-ga4-active-users="{{ $ga4->active_users ?? '' }}">{{ $int($ga4->active_users) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">New users</dt><dd class="text-xl font-semibold">{{ $int($ga4->new_users) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Sessions</dt><dd class="text-xl font-semibold" data-ga4-sessions="{{ $ga4->sessions ?? '' }}">{{ $int($ga4->sessions) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Organic sessions</dt><dd class="text-xl font-semibold">{{ $int($ga4->organic_sessions) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Engaged sessions</dt><dd class="text-xl font-semibold">{{ $int($ga4->engaged_sessions) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Engagement rate</dt><dd class="text-xl font-semibold" data-ga4-engagement-rate="{{ $ga4->engagement_rate ?? '' }}">{{ $fmt($ga4->engagement_rate, '%') }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Avg. engagement time</dt><dd class="text-xl font-semibold">{{ $ga4->average_engagement_time_seconds === null ? '—' : gmdate('i:s', $ga4->average_engagement_time_seconds) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Event count</dt><dd class="text-xl font-semibold">{{ $int($ga4->event_count) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Key events</dt><dd class="text-xl font-semibold">{{ $int($ga4->key_events) }}</dd></div>
                </dl>
                <p class="mt-2 text-xs text-gray-500">Entered by {{ $ga4->enteredBy?->name ?? 'unknown' }} · {{ $ga4->updated_at?->format('j M Y H:i') }}</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400" data-ga4-empty>No GA4 data for {{ $label }}.</p>
            @endif

            <div class="mt-6">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">Audience by country</h3>
                    {{ $this->editGa4CountriesAction }}
                </div>
                @if ($countries->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No country data recorded for {{ $label }}.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs text-gray-500"><tr><th class="py-1">Country</th><th class="py-1 text-right">Active</th><th class="py-1 text-right">New</th><th class="py-1 text-right">Sessions</th><th class="py-1 text-right">Engaged</th><th class="py-1 text-right">Eng. rate</th><th class="py-1 text-right">Events</th><th class="py-1 text-right">Key events</th></tr></thead>
                            <tbody>
                                @foreach ($countries as $row)
                                    <tr class="border-t border-gray-100 dark:border-gray-800" data-ga4-country="{{ $row->country }}">
                                        <td class="py-1">{{ $row->country }}</td>
                                        <td class="py-1 text-right">{{ $int($row->active_users) }}</td>
                                        <td class="py-1 text-right">{{ $int($row->new_users) }}</td>
                                        <td class="py-1 text-right">{{ $int($row->sessions) }}</td>
                                        <td class="py-1 text-right">{{ $int($row->engaged_sessions) }}</td>
                                        <td class="py-1 text-right">{{ $fmt($row->engagement_rate, '%') }}</td>
                                        <td class="py-1 text-right">{{ $int($row->event_count) }}</td>
                                        <td class="py-1 text-right">{{ $int($row->key_events) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- 3. Authority --}}
        <x-filament::section>
            <x-slot name="heading">Authority</x-slot>
            <x-slot name="description">Site-wide authority figures from external tools for {{ $label }}. Source: <x-filament::badge color="{{ $authority?->source->getColor() ?? 'gray' }}" size="sm">{{ $authority?->source->getLabel() ?? 'Manual Entry' }}</x-filament::badge></x-slot>
            <x-slot name="afterHeader">{{ $this->editAuthorityAction }}</x-slot>

            @if ($authority)
                <dl class="grid grid-cols-2 gap-4 md:grid-cols-3" data-authority="{{ $authority->getKey() }}">
                    <div><dt class="text-xs text-gray-500">Moz Domain Authority</dt><dd class="text-xl font-semibold" data-authority-da="{{ $authority->moz_domain_authority ?? '' }}">{{ $int($authority->moz_domain_authority) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Moz linking root domains</dt><dd class="text-xl font-semibold">{{ $int($authority->moz_linking_root_domains) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Ahrefs Domain Rating</dt><dd class="text-xl font-semibold" data-authority-dr="{{ $authority->ahrefs_domain_rating ?? '' }}">{{ $authority->ahrefs_domain_rating === null ? '—' : number_format((float) $authority->ahrefs_domain_rating, 1) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Ahrefs URL Rating</dt><dd class="text-xl font-semibold">{{ $authority->ahrefs_url_rating === null ? '—' : number_format((float) $authority->ahrefs_url_rating, 1) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Backlinks (tool total)</dt><dd class="text-xl font-semibold" data-authority-backlinks="{{ $authority->backlinks_count ?? '' }}">{{ $int($authority->backlinks_count) }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Referring domains</dt><dd class="text-xl font-semibold">{{ $int($authority->referring_domains_count) }}</dd></div>
                </dl>
                @if ($authority->notes)
                    <p class="mt-3 whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">{{ $authority->notes }}</p>
                @endif
                <p class="mt-2 text-xs text-gray-500">Entered by {{ $authority->enteredBy?->name ?? 'unknown' }} · {{ $authority->updated_at?->format('j M Y H:i') }}. Tool totals only; the monthly Backlinks target is tracked under Project → Backlinks.</p>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400" data-authority-empty>No authority data for {{ $label }}.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
