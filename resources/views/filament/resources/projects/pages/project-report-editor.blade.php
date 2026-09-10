<x-filament-panels::page>
    @php
        $report = $this->getReport();
        $cycle = $report->monthlyCycle;
        $project = $this->getProject();
        $readiness = $this->getReadiness();
        $sections = $this->getSections();
        $sectionData = $this->getSectionData();
        $canPrepare = $this->canPrepare();
        $isFinal = $report->isFinal();
        $revisions = $this->getRevisions();
        $auditEvents = $this->getAuditEvents();
        $canReviewNotes = \Illuminate\Support\Facades\Gate::allows('reviewNotes', $report) || (filled($report->review_notes) && \Illuminate\Support\Facades\Gate::allows('finalize', $report));
        $canUnlock = $isFinal && \Illuminate\Support\Facades\Gate::allows('unlock', $report);

        $n = fn ($v) => $v === null ? '—' : number_format((float) $v);
        $num = fn ($v, string $suffix = '') => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.').$suffix;
        $pos = fn ($v) => $v === null ? 'Not ranking' : (string) $v;
        $tone = fn (?string $direction) => match ($direction) { 'improved', 'entered' => 'success', 'declined' => 'danger', 'dropped' => 'warning', default => 'gray' };

        $label = 'class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em"';
        $muted = 'class="fi-section-header-description" style="font-size: var(--text-sm)"';
        $row = 'style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)"';
        $grid = fn (array $cols, int $gap = 4, array $attrs = []) => (new \Filament\Support\View\ComponentAttributeBag)->grid($cols)->style(["gap: calc(var(--spacing) * {$gap})"])->merge($attrs, escape: false);
        $th = 'class="fi-section-header-description" style="padding: calc(var(--spacing) * 2) calc(var(--spacing) * 3); font-size: var(--text-xs); font-weight: var(--font-weight-medium); text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap"';
        $thNum = str_replace('white-space: nowrap"', 'white-space: nowrap; text-align: right"', $th);
        $td = 'class="fi-in-text-item" style="padding: calc(var(--spacing) * 2) calc(var(--spacing) * 3); font-size: var(--text-sm); border-top: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent)"';
        $tdNum = str_replace('font-size: var(--text-sm);', 'font-size: var(--text-sm); text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap;', $td);
        $tdStrong = str_replace('font-size: var(--text-sm);', 'font-size: var(--text-sm); font-weight: var(--font-weight-semibold); overflow-wrap: anywhere;', $td);
        $tableWrap = 'style="overflow-x: auto; border: 1px solid color-mix(in oklab, var(--gray-500) 20%, transparent); border-radius: var(--radius-lg)"';
        $table = 'style="width: 100%; border-collapse: collapse; text-align: left"';

        $enabledSections = $sections->filter(fn ($s) => $s->is_enabled);
        $disabledSections = $sections->reject(fn ($s) => $s->is_enabled);
        $rowLimit = 10;
    @endphp

    @include('filament.resources.projects.partials.project-workspace-header', ['project' => $project, 'modules' => $this->getWorkspaceModules('reports')])

    {{-- Report header: period, status, version, workflow, locked state --}}
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: calc(var(--spacing) * 4)" data-report-header data-report-status="{{ $report->status->value }}" data-readiness="{{ $readiness->percentage() }}">
        <div style="display: grid; gap: calc(var(--spacing) * 2); min-width: 0">
            <div {!! $row !!}>
                <h2 class="fi-section-header-heading" style="margin: 0; font-size: var(--text-xl); line-height: var(--text-xl--line-height)">{{ $cycle->periodLabel() }} SEO report</h2>
                @include('filament.reports.partials.status-badge', ['report' => $report, 'size' => 'lg'])
                @if ($cycle->isLocked())
                    <x-filament::badge color="gray" icon="heroicon-m-lock-closed" data-period-locked>Locked</x-filament::badge>
                @endif
            </div>
            <p {!! $muted !!} data-report-meta>{{ implode(' • ', array_filter([$project->name, $project->client?->name])) }}</p>
            <div {!! $row !!}>
                <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium)" data-report-version="{{ $report->version }}">{{ $isFinal ? 'Current version' : 'Version' }} {{ $report->versionLabel() }}{{ $isFinal ? '' : ' · in preparation' }}</span>
                @if ($isFinal)
                    <span {!! $muted !!} data-finalized-by>Finalised by {{ $report->finalizedBy?->name ?? 'Unknown' }}</span>
                    <span {!! $muted !!} data-finalized-at>{{ $report->finalized_at?->format('j M Y, H:i') }}</span>
                @endif
            </div>
            @if ($cycle->isLocked())
                <p {!! $muted !!}>Reporting period locked. Monthly data is read-only.</p>
            @endif
        </div>
        <div style="display: grid; gap: calc(var(--spacing) * 3); justify-items: end">
            @include('filament.reports.partials.workflow-indicator', ['report' => $report])
            @if ($canUnlock)
                <div data-report-unlock>{{ $this->unlockAction }}</div>
            @endif
        </div>
    </div>

    {{-- Correction in progress --}}
    @if ($report->isCorrection() && $revisions->isNotEmpty())
        @php $previous = $revisions->first(); @endphp
        <div style="display: grid; gap: calc(var(--spacing) * 3); padding: calc(var(--spacing) * 4) calc(var(--spacing) * 5); border: 1px solid var(--warning-500); border-left-width: 4px; border-radius: var(--radius-lg); background: color-mix(in oklab, var(--warning-500) 8%, transparent)" data-correction-banner>
            <div class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold)">Correction in progress</div>
            <div {{ $grid(['default' => 1, 'md' => 2], 4) }}>
                <div style="display: grid; gap: calc(var(--spacing) * 1)">
                    <span {!! $label !!}>Current working version</span>
                    <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold)">{{ $report->versionLabel() }} {{ $report->status->getLabel() }}</span>
                    <span {!! $muted !!}>The previous final report has been preserved. Complete the correction, review the report and finalise {{ $report->versionLabel() }}.</span>
                </div>
                <div style="display: grid; gap: calc(var(--spacing) * 1)">
                    <span {!! $label !!}>Previous final</span>
                    <span class="fi-in-text-item" style="font-size: var(--text-sm); font-weight: var(--font-weight-semibold)" data-previous-version="{{ $previous->version }}">{{ $previous->versionLabel().' • Finalised '.$previous->finalized_at?->format('j M Y').($previous->finalizedBy ? ' by '.$previous->finalizedBy->name : '') }}</span>
                    <span {!! $muted !!}>Unlock reason: {{ $previous->unlock_reason }}</span>
                    <div {!! $row !!}>
                        <x-filament::link :href="$this->revisionPreviewUrl($previous)" target="_blank" size="sm" icon="heroicon-m-eye">View Previous Final</x-filament::link>
                        @if ($previous->hasPdf())
                            <x-filament::link :href="$this->revisionPdfUrl($previous)" target="_blank" size="sm" icon="heroicon-m-arrow-down-tray">Download Previous PDF</x-filament::link>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Readiness | Section overview --}}
    <div {{ $grid(['default' => 1, 'xl' => 2], 6) }}>
        <x-filament::section>
            <x-slot name="heading">Readiness</x-slot>
            <x-slot name="description">{{ $isFinal ? 'Readiness at the time of finalisation is preserved in the report.' : 'Whether enough data exists to render every required section.' }}</x-slot>

            @include('filament.reports.partials.readiness-summary', ['readiness' => $readiness, 'report' => $report])
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Sections</x-slot>
            <x-slot name="description">{{ $enabledSections->count() }} sections in this report, in the order they appear.</x-slot>

            <ul style="display: grid; gap: calc(var(--spacing) * 1.5); margin: 0; padding: 0; list-style: none" data-section-nav>
                @foreach ($enabledSections as $section)
                    @php $live = $readiness->section($section->section_key->value); @endphp
                    <li style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: calc(var(--spacing) * 2)" data-section-nav-item="{{ $section->section_key->value }}" data-section-nav-state="{{ $live?->complete ? 'complete' : 'missing' }}">
                        <a href="#section-{{ $section->section_key->value }}" class="fi-in-text-item" style="display: inline-flex; align-items: center; gap: calc(var(--spacing) * 2); font-size: var(--text-sm); text-decoration: none">
                            <span aria-hidden="true" style="display: inline-flex; width: 1.1rem; height: 1.1rem; align-items: center; justify-content: center; border-radius: 999px; font-size: 0.65rem; font-weight: var(--font-weight-semibold); color: #fff; background: {{ $live?->complete ? 'var(--success-500)' : ($section->is_required ? 'var(--warning-500)' : 'var(--gray-400)') }}">{{ $live?->complete ? '✓' : '!' }}</span>
                            {{ $section->title }}
                        </a>
                        <span {!! $row !!} style="display: flex; gap: calc(var(--spacing) * 1.5)">
                            @unless ($section->is_required)
                                <x-filament::badge color="gray" size="sm">Optional</x-filament::badge>
                            @endunless
                            <x-filament::badge :color="$live?->complete ? 'success' : ($section->is_required ? 'warning' : 'gray')" size="sm">{{ $live?->complete ? 'Complete' : 'Missing' }}</x-filament::badge>
                        </span>
                    </li>
                @endforeach
            </ul>
            @if ($disabledSections->isNotEmpty())
                <p {!! $muted !!} style="margin-top: calc(var(--spacing) * 3); font-size: var(--text-xs)" data-sections-disabled>Not included in this report: {{ $disabledSections->pluck('title')->implode(', ') }}.</p>
            @endif
        </x-filament::section>
    </div>

    {{-- Internal review notes --}}
    @if ($canReviewNotes)
        <x-filament::section data-internal-notes style="border-style: dashed">
            <x-slot name="heading">
                <span {!! $row !!} style="display: inline-flex; gap: calc(var(--spacing) * 2)">Internal review notes <x-filament::badge color="gray" size="sm" icon="heroicon-m-lock-closed">Internal only</x-filament::badge></span>
            </x-slot>
            <x-slot name="description">These notes are for the SEO team and will not appear in the client report.</x-slot>
            @if (\Illuminate\Support\Facades\Gate::allows('reviewNotes', $report))
                <x-slot name="afterHeader">{{ $this->editReviewNotesAction }}</x-slot>
            @endif

            @if (filled($report->review_notes))
                <p class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); line-height: 1.6; white-space: pre-line" data-review-notes>{{ $report->review_notes }}</p>
            @else
                <p {!! $muted !!} data-review-notes>No review notes yet.</p>
            @endif
        </x-filament::section>
    @endif

    {{-- Section cards, in snapshot order --}}
    @foreach ($enabledSections as $section)
        @php
            $key = $section->section_key->value;
            $live = $readiness->section($key);
            $data = $sectionData[$key]['data'] ?? [];
            $complete = (bool) $live?->complete;
            $isSummary = $section->section_key === \App\Enums\ReportSectionKey::ExecutiveSummary;
        @endphp
        <div id="section-{{ $key }}">
        <x-filament::section :collapsible="true" :collapsed="false">
            <x-slot name="heading">{{ $section->title }}</x-slot>
            <x-slot name="description">
                @if ($isSummary)
                    Summarise the month's main results, progress and important context.
                @elseif (! $complete && $live?->reason)
                    {{ $live->reason }}
                @else
                    Numbers come from the month's source data.
                @endif
            </x-slot>
            <x-slot name="afterHeader">
                <div {!! $row !!} data-report-section="{{ $key }}" data-section-order="{{ $section->sort_order }}" data-section-enabled="1">
                    @unless ($section->is_required)
                        <x-filament::badge color="gray" size="sm">Optional</x-filament::badge>
                    @endunless
                    <x-filament::badge :color="$complete ? 'success' : ($section->is_required ? 'warning' : 'gray')" size="sm" data-section-state="{{ $complete ? 'complete' : 'missing' }}">{{ $complete ? 'Complete' : 'Missing' }}</x-filament::badge>
                    @if ($canPrepare)
                        {{ $isSummary ? $this->editExecutiveSummaryAction : ($this->editSectionTextAction)(['section' => $section->getKey()]) }}
                    @endif
                </div>
            </x-slot>

            <div style="display: grid; gap: calc(var(--spacing) * 4)">
                {{-- Executive summary --}}
                @if ($isSummary)
                    @if (filled($report->executive_summary))
                        <p class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); line-height: 1.6; white-space: pre-line" data-executive-summary>{{ $report->executive_summary }}</p>
                    @else
                        <p {!! $muted !!} data-executive-summary-empty>No executive summary yet.</p>
                    @endif
                    <div style="display: grid; gap: calc(var(--spacing) * 1)">
                        <span {!! $label !!}>Source notes added to the report</span>
                        <div {!! $row !!} style="display: flex; gap: calc(var(--spacing) * 2)" data-summary-sources>
                            <x-filament::badge color="success">{{ count($data['wins'] ?? []) }} {{ count($data['wins'] ?? []) === 1 ? 'win' : 'wins' }}</x-filament::badge>
                            <x-filament::badge color="danger">{{ count($data['challenges'] ?? []) }} {{ count($data['challenges'] ?? []) === 1 ? 'challenge' : 'challenges' }}</x-filament::badge>
                            <x-filament::badge color="gray">{{ count($data['observations'] ?? []) }} {{ count($data['observations'] ?? []) === 1 ? 'observation' : 'observations' }}</x-filament::badge>
                        </div>
                    </div>

                {{-- Site authority --}}
                @elseif ($key === 'site_authority')
                    @if ($data['available'] ?? false)
                        <dl {{ $grid(['default' => 2, 'sm' => 3, 'lg' => 6], 3) }}>
                            @foreach ([['DA', $n($data['moz_domain_authority'] ?? null)], ['DR', $num($data['ahrefs_domain_rating'] ?? null)], ['URL rating', $num($data['ahrefs_url_rating'] ?? null)], ['Root domains', $n($data['moz_linking_root_domains'] ?? null)], ['Known backlinks', $n($data['backlinks_count'] ?? null)], ['Referring domains', $n($data['referring_domains_count'] ?? null)]] as [$metricLabel, $value])
                                <div style="display: grid; gap: calc(var(--spacing) * 0.5)"><dt {!! $label !!}>{{ $metricLabel }}</dt><dd class="fi-in-text-item" style="margin: 0; font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @else
                        <p {!! $muted !!}>No authority metrics recorded for {{ $cycle->periodLabel() }}.</p>
                    @endif

                {{-- Organic search --}}
                @elseif ($key === 'organic_search')
                    @if ($data['available'] ?? false)
                        <dl {{ $grid(['default' => 2, 'lg' => 4], 3) }}>
                            @foreach ([['Clicks', $n($data['clicks'] ?? null)], ['Impressions', $n($data['impressions'] ?? null)], ['CTR', $num($data['ctr'] ?? null, '%')], ['Avg position', $num($data['average_position'] ?? null)]] as [$metricLabel, $value])
                                <div style="display: grid; gap: calc(var(--spacing) * 0.5)"><dt {!! $label !!}>{{ $metricLabel }}</dt><dd class="fi-in-text-item" style="margin: 0; font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @else
                        <p {!! $muted !!}>No Search Console summary recorded for {{ $cycle->periodLabel() }}.</p>
                    @endif

                {{-- Website traffic --}}
                @elseif ($key === 'website_traffic')
                    @if ($data['available'] ?? false)
                        <dl {{ $grid(['default' => 2, 'lg' => 4], 3) }}>
                            @foreach ([['Users', $n($data['active_users'] ?? null)], ['Sessions', $n($data['sessions'] ?? null)], ['Organic sessions', $n($data['organic_sessions'] ?? null)], ['Engagement', $num($data['engagement_rate'] ?? null, '%')]] as [$metricLabel, $value])
                                <div style="display: grid; gap: calc(var(--spacing) * 0.5)"><dt {!! $label !!}>{{ $metricLabel }}</dt><dd class="fi-in-text-item" style="margin: 0; font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    @else
                        <p {!! $muted !!}>No Google Analytics summary recorded for {{ $cycle->periodLabel() }}.</p>
                    @endif

                {{-- Top keywords --}}
                @elseif ($key === 'top_keywords')
                    @php $rows = collect($data['queries'] ?? []); @endphp
                    @if ($rows->isEmpty())
                        <p {!! $muted !!}>No search queries recorded for {{ $cycle->periodLabel() }}.</p>
                    @else
                        <div {!! $tableWrap !!}>
                            <table {!! $table !!} data-section-table="top_keywords">
                                <thead><tr><th {!! $th !!}>Query</th><th {!! $thNum !!}>Clicks</th><th {!! $thNum !!}>Impressions</th><th {!! $thNum !!}>CTR</th><th {!! $thNum !!}>Avg position</th></tr></thead>
                                <tbody>
                                    @foreach ($rows->take($rowLimit) as $q)
                                        <tr><td {!! $tdStrong !!}>{{ $q['query'] }}</td><td {!! $tdNum !!}>{{ $n($q['clicks'] ?? null) }}</td><td {!! $tdNum !!}>{{ $n($q['impressions'] ?? null) }}</td><td {!! $tdNum !!}>{{ $num($q['ctr'] ?? null, '%') }}</td><td {!! $tdNum !!}>{{ $num($q['average_position'] ?? null) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($rows->count() > $rowLimit)<p {!! $muted !!}>{{ $rows->count() - $rowLimit }} more in the full report.</p>@endif
                    @endif

                {{-- Landing pages --}}
                @elseif ($key === 'landing_pages')
                    @php $rows = collect($data['pages'] ?? []); @endphp
                    @if ($rows->isEmpty())
                        <p {!! $muted !!}>No landing pages recorded for {{ $cycle->periodLabel() }}.</p>
                    @else
                        <div {!! $tableWrap !!}>
                            <table {!! $table !!} data-section-table="landing_pages">
                                <thead><tr><th {!! $th !!}>Page</th><th {!! $thNum !!}>Clicks</th><th {!! $thNum !!}>Impressions</th><th {!! $thNum !!}>CTR</th><th {!! $thNum !!}>Avg position</th></tr></thead>
                                <tbody>
                                    @foreach ($rows->take($rowLimit) as $p)
                                        <tr><td {!! $td !!} style="max-width: 24rem"><div style="display: grid; gap: calc(var(--spacing) * 0.5)">@if (!empty($p['page_title']))<span style="font-weight: var(--font-weight-semibold)">{{ $p['page_title'] }}</span>@endif<span class="fi-section-header-description" style="font-size: var(--text-xs); overflow-wrap: anywhere" title="{{ $p['page_url'] }}">{{ $this->pagePath($p['page_url'] ?? null) }}</span></div></td><td {!! $tdNum !!}>{{ $n($p['clicks'] ?? null) }}</td><td {!! $tdNum !!}>{{ $n($p['impressions'] ?? null) }}</td><td {!! $tdNum !!}>{{ $num($p['ctr'] ?? null, '%') }}</td><td {!! $tdNum !!}>{{ $num($p['average_position'] ?? null) }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($rows->count() > $rowLimit)<p {!! $muted !!}>{{ $rows->count() - $rowLimit }} more in the full report.</p>@endif
                    @endif

                {{-- Rankings --}}
                @elseif ($key === 'rankings')
                    @php $rows = collect($data['keywords'] ?? []); $s = $data['summary'] ?? []; @endphp
                    @if ($rows->isEmpty())
                        <p {!! $muted !!}>No active keywords are tracked for this project.</p>
                    @else
                        <div {!! $row !!} data-rankings-summary>
                            <x-filament::badge color="gray">{{ $data['tracked_count'] ?? $rows->count() }} tracked</x-filament::badge>
                            <x-filament::badge color="success">{{ $s['improved'] ?? 0 }} improved</x-filament::badge>
                            <x-filament::badge color="danger">{{ $s['declined'] ?? 0 }} declined</x-filament::badge>
                            <x-filament::badge color="gray">{{ $s['unchanged'] ?? 0 }} unchanged</x-filament::badge>
                        </div>
                        <div {!! $tableWrap !!}>
                            <table {!! $table !!} data-section-table="rankings">
                                <thead><tr><th {!! $th !!}>Keyword</th><th {!! $thNum !!}>Start</th><th {!! $thNum !!}>Latest</th><th {!! $th !!}>Movement</th></tr></thead>
                                <tbody>
                                    @foreach ($rows->take($rowLimit) as $k)
                                        <tr data-rankings-keyword="{{ $k['keyword'] }}"><td {!! $tdStrong !!}>{{ $k['keyword'] }}@if (!empty($k['location'])) <span class="fi-section-header-description" style="font-size: var(--text-xs); font-weight: var(--font-weight-normal)">· {{ $k['location'] }}</span>@endif</td><td {!! $tdNum !!}>{{ $pos($k['month_start_position'] ?? null) }}</td><td {!! $tdNum !!}>{{ $pos($k['latest_position'] ?? null) }}</td><td {!! $td !!}><x-filament::badge :color="$tone($k['movement']['direction'] ?? null)" size="sm">{{ $k['movement']['label'] ?? '—' }}</x-filament::badge></td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($rows->count() > $rowLimit)<p {!! $muted !!}>{{ $rows->count() - $rowLimit }} more in the full report.</p>@endif
                    @endif

                {{-- Audience by country --}}
                @elseif ($key === 'audience_country')
                    @php $rows = collect($data['countries'] ?? []); @endphp
                    @if ($rows->isEmpty())
                        <p {!! $muted !!}>No country data recorded for {{ $cycle->periodLabel() }}.</p>
                    @else
                        <div {!! $tableWrap !!}>
                            <table {!! $table !!} data-section-table="audience_country">
                                <thead><tr><th {!! $th !!}>Country</th><th {!! $thNum !!}>Active users</th><th {!! $thNum !!}>Sessions</th><th {!! $thNum !!}>Engagement</th></tr></thead>
                                <tbody>
                                    @foreach ($rows->take($rowLimit) as $c)
                                        <tr><td {!! $tdStrong !!}>{{ $c['country'] }}</td><td {!! $tdNum !!}>{{ $n($c['active_users'] ?? null) }}</td><td {!! $tdNum !!}>{{ $n($c['sessions'] ?? null) }}</td><td {!! $tdNum !!}>{{ $num($c['engagement_rate'] ?? null, '%') }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                {{-- Backlinks --}}
                @elseif ($key === 'backlinks')
                    @php $progress = $data['progress'] ?? []; $totals = $data['totals'] ?? []; @endphp
                    <div {{ $grid(['default' => 1, 'sm' => 2], 4) }}>
                        @foreach ([['backlinks', 'Backlinks'], ['guest_posts', 'Guest posts']] as [$pKey, $pLabel])
                            @php $p = $progress[$pKey] ?? []; $pct = $p['percentage'] ?? null; @endphp
                            <div style="display: grid; gap: calc(var(--spacing) * 1.5)" data-backlinks-progress="{{ $pKey }}">
                                <div style="display: flex; justify-content: space-between; align-items: baseline; gap: calc(var(--spacing) * 2)">
                                    <span {!! $label !!}>{{ $pLabel }}</span>
                                    <span class="fi-in-text-item" style="font-size: var(--text-lg); font-weight: var(--font-weight-semibold); font-variant-numeric: tabular-nums">{{ $p['display'] ?? '—' }}@if ($pct !== null) <span class="fi-section-header-description" style="font-size: var(--text-xs)">{{ $pct }}%</span>@endif</span>
                                </div>
                                @if ($pct !== null)
                                    <div role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $pct) }}" aria-label="{{ $pLabel }} {{ $pct }}%" style="height: 6px; border-radius: 999px; background: color-mix(in oklab, var(--gray-500) 22%, transparent); overflow: hidden"><div style="height: 100%; width: {{ min(100, $pct) }}%; border-radius: 999px; background: {{ ($p['actual'] ?? 0) >= ($p['target'] ?? PHP_INT_MAX) ? 'var(--success-500)' : 'var(--primary-500)' }}"></div></div>
                                @else
                                    <span {!! $muted !!}>No target this month</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div {!! $row !!} data-backlinks-breakdown>
                        <span {!! $muted !!}>{{ $n($totals['recorded'] ?? 0) }} links recorded · {{ $n($totals['live'] ?? 0) }} live</span>
                        @foreach (collect($data['live_type_breakdown'] ?? [])->where('count', '>', 0) as $b)
                            <x-filament::badge color="gray" size="sm">{{ \App\Enums\BacklinkType::tryFrom($b['type'])?->getLabel() ?? $b['type'] }} {{ $b['count'] }}</x-filament::badge>
                        @endforeach
                    </div>

                {{-- Recommendations --}}
                @elseif ($key === 'recommendations')
                    @php $recs = $data['recommendations'] ?? []; $focus = $data['next_month_focus'] ?? []; @endphp
                    @if ($recs === [] && $focus === [])
                        <p {!! $muted !!}>No recommendations or next-month focus notes recorded for {{ $cycle->periodLabel() }}.</p>
                    @else
                        <div {{ $grid(['default' => 1, 'md' => 2], 4) }}>
                            @foreach ([['Recommendations', $recs, 'recommendations'], ['Next month focus', $focus, 'next-month-focus']] as [$listLabel, $notes, $listKey])
                                <div style="display: grid; gap: calc(var(--spacing) * 2)" data-report-notes="{{ $listKey }}">
                                    <span {!! $label !!}>{{ $listLabel }}</span>
                                    @if ($notes === [])
                                        <span {!! $muted !!}>None yet.</span>
                                    @else
                                        <ul style="display: grid; gap: calc(var(--spacing) * 1.5); margin: 0; padding-left: 1.1rem">
                                            @foreach ($notes as $note)
                                                <li class="fi-in-text-item" style="font-size: var(--text-sm); line-height: 1.5">@if (!empty($note['title']))<strong>{{ $note['title'] }}</strong> — @endif{{ $note['body'] }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif

                {{-- Commentary (client-facing) --}}
                @unless ($isSummary)
                    <div style="display: grid; gap: calc(var(--spacing) * 1)" data-section-commentary="{{ $key }}">
                        <span {!! $label !!}>Commentary</span>
                        @if (filled($section->custom_text))
                            <p class="fi-in-text-item" style="margin: 0; font-size: var(--text-sm); line-height: 1.6; white-space: pre-line" data-section-text="{{ $key }}">{{ $section->custom_text }}</p>
                        @else
                            <span {!! $muted !!}>No commentary. Add context or explanation for this section if needed.</span>
                        @endif
                    </div>
                @endunless
            </div>
        </x-filament::section>
        </div>
    @endforeach

    {{-- Version history --}}
    <x-filament::section>
        <x-slot name="heading">Version history</x-slot>
        <x-slot name="description">Every superseded final report is kept with its own PDF and can still be viewed.</x-slot>

        <div {!! $tableWrap !!}>
            <table {!! $table !!} data-version-history>
                <thead><tr><th {!! $th !!}>Version</th><th {!! $th !!}>Status</th><th {!! $th !!}>Date</th><th {!! $th !!}>By</th><th {!! $th !!}>Notes</th><th {!! $thNum !!}>Actions</th></tr></thead>
                <tbody>
                    <tr data-version-row="{{ $report->version }}" data-version-state="{{ $isFinal ? 'final' : $report->status->value }}">
                        <td {!! $tdStrong !!}>{{ $report->versionLabel() }}</td>
                        <td {!! $td !!}>@include('filament.reports.partials.status-badge', ['report' => $report, 'size' => 'sm'])</td>
                        <td {!! $td !!}>{{ $report->finalized_at?->format('j M Y, H:i') ?? '—' }}</td>
                        <td {!! $td !!}>{{ $report->finalizedBy?->name ?? '—' }}</td>
                        <td {!! $td !!}><span {!! $muted !!}>{{ $isFinal ? 'Current final report' : 'Current working version' }}</span></td>
                        <td {!! $tdNum !!}>
                            @if ($isFinal)
                                <x-filament::link :href="$this->previewUrl()" target="_blank" size="sm">View</x-filament::link>
                                @if ($report->hasPdf()) <x-filament::link :href="$this->pdfUrl()" target="_blank" size="sm">Download PDF</x-filament::link>@endif
                            @else
                                <span {!! $muted !!}>—</span>
                            @endif
                        </td>
                    </tr>
                    @foreach ($revisions as $revision)
                        <tr data-version-row="{{ $revision->version }}" data-version-state="superseded" data-revision="{{ $revision->getKey() }}">
                            <td {!! $tdStrong !!}>{{ $revision->versionLabel() }}</td>
                            <td {!! $td !!}><x-filament::badge color="gray" size="sm">Superseded</x-filament::badge></td>
                            <td {!! $td !!}>{{ $revision->finalized_at?->format('j M Y, H:i') }}</td>
                            <td {!! $td !!}>{{ $revision->finalizedBy?->name ?? '—' }}</td>
                            <td {!! $td !!}><span {!! $muted !!}>Unlock reason: {{ $revision->unlock_reason }}</span></td>
                            <td {!! $tdNum !!}>
                                <x-filament::link :href="$this->revisionPreviewUrl($revision)" target="_blank" size="sm">View</x-filament::link>
                                @if ($revision->hasPdf()) <x-filament::link :href="$this->revisionPdfUrl($revision)" target="_blank" size="sm">Download PDF</x-filament::link>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- Audit history --}}
    <x-filament::section>
        <x-slot name="heading">Report history</x-slot>
        <x-slot name="description">Who marked this report ready, finalised it, or unlocked it for correction.</x-slot>

        @if ($auditEvents->isEmpty())
            <p {!! $muted !!} data-audit-empty>Nothing has happened to this report yet.</p>
        @else
            <ol style="display: grid; gap: calc(var(--spacing) * 3); margin: 0; padding: 0; list-style: none" data-audit-timeline>
                @foreach ($auditEvents->sortByDesc(fn ($e) => [$e->created_at?->timestamp ?? 0, $e->id]) as $event)
                    @php
                        $actor = $event->user?->name ?? 'Unknown';
                        $sentence = match ($event->event_type) {
                            \App\Enums\AuditEventType::ReportMarkedReady => 'v'.($event->version() ?? '?').' marked ready for review by '.$actor,
                            \App\Enums\AuditEventType::ReportFinalized => 'v'.($event->version() ?? '?').' finalised by '.$actor,
                            \App\Enums\AuditEventType::ReportUnlockedForCorrection => 'v'.($event->metadata_json['superseded_version'] ?? '?').' unlocked for correction by '.$actor.' · v'.($event->version() ?? '?').' started',
                        };
                    @endphp
                    <li style="display: grid; grid-template-columns: 0.75rem 1fr; gap: calc(var(--spacing) * 3); align-items: start" data-audit-event="{{ $event->event_type->value }}" data-audit-version="{{ $event->version() ?? '' }}">
                        <span aria-hidden="true" style="margin-top: 0.4rem; width: 0.75rem; height: 0.75rem; border-radius: 999px; background: var(--{{ $event->event_type->getColor() }}-500)"></span>
                        <div style="display: grid; gap: calc(var(--spacing) * 0.5)">
                            <span class="fi-section-header-description" style="font-size: var(--text-xs)">{{ $event->created_at?->format('j M Y • H:i') }}</span>
                            <span class="fi-in-text-item" style="font-size: var(--text-sm)">{{ $sentence }}</span>
                            @if ($event->reason)
                                <span {!! $muted !!}>Reason: {{ $event->reason }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-panels::page>
