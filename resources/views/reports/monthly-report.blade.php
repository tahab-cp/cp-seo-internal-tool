@php
    /** @var array<string, mixed> $snapshot */
    $forPdf = $forPdf ?? false;
    $client = $snapshot['client'] ?? [];
    $project = $snapshot['project'] ?? [];
    $period = $snapshot['period'] ?? [];
    $targets = $snapshot['targets'] ?? [];
    $sections = collect($snapshot['sections'] ?? [])->filter(fn (array $s): bool => (bool) ($s['enabled'] ?? false))->sortBy('sort_order')->values();
    $isFinal = ($snapshot['report']['status'] ?? 'draft') === 'final';

    $n = fn ($value) => $value === null ? '—' : number_format((float) $value);
    $d = fn ($value, string $suffix = '') => $value === null ? '—' : number_format((float) $value, 2).$suffix;
    $d1 = fn ($value) => $value === null ? '—' : number_format((float) $value, 1);
    $pos = fn ($value) => $value === null ? 'Not ranking' : (string) $value;
    $time = fn ($seconds) => $seconds === null ? '—' : gmdate('i:s', (int) $seconds);
    $typeLabel = fn (string $type) => \App\Enums\BacklinkType::tryFrom($type)?->getLabel() ?? ucfirst(str_replace('_', ' ', $type));
    $statusLabel = fn (string $status) => \App\Enums\BacklinkStatus::tryFrom($status)?->getLabel() ?? ucfirst($status);
    $movementClass = fn (?string $direction) => match ($direction) {
        'improved', 'entered' => 'up',
        'declined', 'dropped' => 'down',
        default => 'flat',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $project['name'] ?? 'Project' }} — SEO Report — {{ $period['label'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 16mm 14mm 18mm 14mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Arial, Helvetica, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #1f2937;
            background: #ffffff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .page { max-width: 190mm; margin: 0 auto; padding: 12mm 0; }
        @media print { .page { max-width: none; padding: 0; } }
        h1, h2, h3 { margin: 0; line-height: 1.2; color: #111827; }
        h1 { font-size: 26pt; font-weight: 700; }
        h2 { font-size: 15pt; font-weight: 700; padding-bottom: 4pt; border-bottom: 2px solid #d97706; margin-bottom: 10pt; }
        h3 { font-size: 11.5pt; font-weight: 600; margin: 10pt 0 5pt; color: #374151; }
        p { margin: 0 0 8pt; }
        .muted { color: #6b7280; }
        .small { font-size: 9pt; }
        .section { margin-top: 20pt; page-break-inside: avoid; }
        .section.long { page-break-inside: auto; }
        .section + .section { break-before: auto; }
        .cover { min-height: 240mm; display: flex; flex-direction: column; justify-content: space-between; page-break-after: always; }
        .cover .brand { font-size: 10pt; letter-spacing: .12em; text-transform: uppercase; color: #d97706; font-weight: 700; }
        .cover .period { font-size: 16pt; color: #374151; margin-top: 6pt; }
        .cover dl { display: grid; grid-template-columns: 120pt 1fr; row-gap: 4pt; margin: 18pt 0 0; font-size: 10.5pt; }
        .cover dt { color: #6b7280; }
        .cover dd { margin: 0; font-weight: 600; }
        .draft-banner { border: 1px dashed #d97706; background: #fffbeb; color: #92400e; padding: 6pt 10pt; font-size: 9.5pt; margin-bottom: 14pt; }
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8pt; margin: 8pt 0 10pt; }
        .cards.three { grid-template-columns: repeat(3, 1fr); }
        .card { border: 1px solid #e5e7eb; border-radius: 6pt; padding: 8pt 10pt; background: #f9fafb; page-break-inside: avoid; }
        .card .label { font-size: 8.5pt; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
        .card .value { font-size: 16pt; font-weight: 700; color: #111827; margin-top: 2pt; }
        .card .sub { font-size: 8.5pt; color: #6b7280; margin-top: 1pt; }
        table { width: 100%; border-collapse: collapse; font-size: 9.5pt; margin: 4pt 0 8pt; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { padding: 4pt 6pt; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 600; color: #374151; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        .url { word-break: break-all; }
        .badge { display: inline-block; padding: 1pt 6pt; border-radius: 9pt; font-size: 8.5pt; font-weight: 600; }
        .up { background: #dcfce7; color: #166534; }
        .down { background: #fee2e2; color: #991b1b; }
        .flat { background: #f3f4f6; color: #374151; }
        .note { border-left: 3px solid #d97706; padding: 4pt 8pt; margin: 4pt 0; background: #fffbeb; page-break-inside: avoid; }
        .note .title { font-weight: 600; }
        .note.challenge { border-color: #ef4444; background: #fef2f2; }
        .note.observation { border-color: #9ca3af; background: #f9fafb; }
        .note.next { border-color: #2563eb; background: #eff6ff; }
        .narrative { white-space: pre-line; }
        .empty { color: #6b7280; font-style: italic; }
        .targets { margin-top: 8pt; }
        .footer { margin-top: 24pt; padding-top: 8pt; border-top: 1px solid #e5e7eb; font-size: 8.5pt; color: #6b7280; }
    </style>
</head>
<body>
<div class="page" data-report-snapshot-version="{{ $snapshot['schema_version'] ?? '' }}" data-report-status="{{ $snapshot['report']['status'] ?? '' }}">

    {{-- Cover --}}
    <section class="cover" data-section="cover">
        <div>
            <div class="brand">Monthly SEO Report</div>
            <h1 style="margin-top:10pt">{{ $project['name'] ?? 'Project' }}</h1>
            <div class="period">{{ $period['label'] ?? '' }}</div>

            @unless ($isFinal)
                <div class="draft-banner" style="margin-top:14pt">Preview — {{ ucfirst(str_replace('_', ' ', $snapshot['report']['status'] ?? 'draft')) }}. Figures reflect current data and may change until the report is finalized.</div>
            @endunless

            <dl>
                <dt>Client</dt><dd>{{ $client['name'] ?? '—' }}</dd>
                @if (!empty($client['company_name']))
                    <dt>Company</dt><dd>{{ $client['company_name'] }}</dd>
                @endif
                <dt>Website</dt><dd class="url">{{ $project['website_url'] ?? '—' }}</dd>
                @if (!empty($project['target_location']))
                    <dt>Target location</dt><dd>{{ $project['target_location'] }}</dd>
                @endif
                @if (!empty($project['package']['name']))
                    <dt>Package</dt><dd>{{ $project['package']['name'] }}</dd>
                @endif
                <dt>Reporting period</dt><dd>{{ $period['starts_on'] ?? '' }} to {{ $period['ends_on'] ?? '' }}</dd>
            </dl>

            @if ($targets !== [])
                <h3>Monthly targets</h3>
                <table class="targets" data-targets>
                    <thead><tr><th>Deliverable</th><th class="num">Target</th><th class="num">Delivered</th><th class="num">Progress</th></tr></thead>
                    <tbody>
                        @foreach ($targets as $target)
                            <tr data-target="{{ $target['key'] }}">
                                <td>{{ $target['label'] }}</td>
                                <td class="num">{{ $n($target['target']) }}</td>
                                <td class="num">{{ $target['actual'] === null ? '—' : $n($target['actual']) }}</td>
                                <td class="num">{{ $target['percentage'] === null ? '—' : $target['percentage'].'%' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="small muted">
            @if ($isFinal && !empty($snapshot['finalized_at']))
                Finalized {{ \Carbon\Carbon::parse($snapshot['finalized_at'])->format('j F Y') }}@if (!empty($snapshot['finalized_by']['name'])) by {{ $snapshot['finalized_by']['name'] }}@endif.
            @else
                Generated {{ \Carbon\Carbon::parse($snapshot['generated_at'] ?? now())->format('j F Y H:i') }}.
            @endif
        </div>
    </section>

    @foreach ($sections as $section)
        @php $data = $section['data'] ?? []; @endphp

        {{-- Executive Summary --}}
        @if ($section['key'] === 'executive_summary')
            <section class="section" data-section="executive_summary">
                <h2>{{ $section['title'] }}</h2>
                @if (filled($data['summary'] ?? null))
                    <p class="narrative" data-executive-summary>{{ $data['summary'] }}</p>
                @else
                    <p class="empty">No executive summary has been written for this month.</p>
                @endif
                @if (!empty($data['wins']))
                    <h3>Highlights</h3>
                    @foreach ($data['wins'] as $note)
                        <div class="note" data-note="win">@if ($note['title'])<div class="title">{{ $note['title'] }}</div>@endif<div class="narrative">{{ $note['body'] }}</div></div>
                    @endforeach
                @endif
                @if (!empty($data['challenges']) || !empty($data['observations']))
                    <h3>Challenges &amp; observations</h3>
                    @foreach ($data['challenges'] ?? [] as $note)
                        <div class="note challenge" data-note="challenge">@if ($note['title'])<div class="title">{{ $note['title'] }}</div>@endif<div class="narrative">{{ $note['body'] }}</div></div>
                    @endforeach
                    @foreach ($data['observations'] ?? [] as $note)
                        <div class="note observation" data-note="observation">@if ($note['title'])<div class="title">{{ $note['title'] }}</div>@endif<div class="narrative">{{ $note['body'] }}</div></div>
                    @endforeach
                @endif
                @if (filled($section['custom_text'] ?? null))
                    <p class="narrative" data-custom-text="executive_summary">{{ $section['custom_text'] }}</p>
                @endif
            </section>

        {{-- Site Authority --}}
        @elseif ($section['key'] === 'site_authority')
            <section class="section" data-section="site_authority">
                <h2>{{ $section['title'] }}</h2>
                @if ($data['available'] ?? false)
                    <div class="cards three">
                        <div class="card"><div class="label">Moz Domain Authority</div><div class="value" data-metric="moz_domain_authority">{{ $n($data['moz_domain_authority']) }}</div></div>
                        <div class="card"><div class="label">Ahrefs Domain Rating</div><div class="value" data-metric="ahrefs_domain_rating">{{ $d1($data['ahrefs_domain_rating']) }}</div></div>
                        <div class="card"><div class="label">Ahrefs URL Rating</div><div class="value">{{ $d1($data['ahrefs_url_rating']) }}</div></div>
                        <div class="card"><div class="label">Linking root domains (Moz)</div><div class="value">{{ $n($data['moz_linking_root_domains']) }}</div></div>
                        <div class="card"><div class="label">Total backlinks</div><div class="value" data-metric="backlinks_count">{{ $n($data['backlinks_count']) }}</div></div>
                        <div class="card"><div class="label">Referring domains</div><div class="value">{{ $n($data['referring_domains_count']) }}</div></div>
                    </div>
                    @if (filled($data['notes'] ?? null))<p class="narrative small muted">{{ $data['notes'] }}</p>@endif
                @else
                    <p class="empty">No authority metrics were recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="site_authority">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Organic Search --}}
        @elseif ($section['key'] === 'organic_search')
            <section class="section" data-section="organic_search">
                <h2>{{ $section['title'] }}</h2>
                @if ($data['available'] ?? false)
                    <div class="cards">
                        <div class="card"><div class="label">Clicks</div><div class="value" data-metric="clicks">{{ $n($data['clicks']) }}</div></div>
                        <div class="card"><div class="label">Impressions</div><div class="value" data-metric="impressions">{{ $n($data['impressions']) }}</div></div>
                        <div class="card"><div class="label">CTR</div><div class="value">{{ $d($data['ctr'], '%') }}</div></div>
                        <div class="card"><div class="label">Average position</div><div class="value">{{ $d($data['average_position']) }}</div></div>
                    </div>
                    <p class="small muted">Source: Google Search Console.</p>
                @else
                    <p class="empty">No Search Console summary was recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="organic_search">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Website Traffic --}}
        @elseif ($section['key'] === 'website_traffic')
            <section class="section" data-section="website_traffic">
                <h2>{{ $section['title'] }}</h2>
                @if ($data['available'] ?? false)
                    <div class="cards">
                        <div class="card"><div class="label">Active users</div><div class="value" data-metric="active_users">{{ $n($data['active_users']) }}</div></div>
                        <div class="card"><div class="label">New users</div><div class="value">{{ $n($data['new_users']) }}</div></div>
                        <div class="card"><div class="label">Sessions</div><div class="value" data-metric="sessions">{{ $n($data['sessions']) }}</div></div>
                        <div class="card"><div class="label">Organic sessions</div><div class="value">{{ $n($data['organic_sessions']) }}</div></div>
                        <div class="card"><div class="label">Engaged sessions</div><div class="value">{{ $n($data['engaged_sessions']) }}</div></div>
                        <div class="card"><div class="label">Engagement rate</div><div class="value">{{ $d($data['engagement_rate'], '%') }}</div></div>
                        <div class="card"><div class="label">Avg. engagement time</div><div class="value">{{ $time($data['average_engagement_time_seconds']) }}</div></div>
                        <div class="card"><div class="label">Key events</div><div class="value">{{ $n($data['key_events']) }}</div><div class="sub">{{ $n($data['event_count']) }} events</div></div>
                    </div>
                    <p class="small muted">Source: Google Analytics 4.</p>
                @else
                    <p class="empty">No Google Analytics summary was recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="website_traffic">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Top Keywords (GSC queries) --}}
        @elseif ($section['key'] === 'top_keywords')
            <section class="section long" data-section="top_keywords">
                <h2>{{ $section['title'] }}</h2>
                @if (!empty($data['queries']))
                    <table>
                        <thead><tr><th>Query</th><th class="num">Clicks</th><th class="num">Impressions</th><th class="num">CTR</th><th class="num">Avg. position</th></tr></thead>
                        <tbody>
                            @foreach ($data['queries'] as $row)
                                <tr data-query="{{ $row['query'] }}"><td>{{ $row['query'] }}</td><td class="num">{{ $n($row['clicks']) }}</td><td class="num">{{ $n($row['impressions']) }}</td><td class="num">{{ $d($row['ctr'], '%') }}</td><td class="num">{{ $d($row['average_position']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="small muted">Source: Google Search Console queries.</p>
                @else
                    <p class="empty">No search queries were recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="top_keywords">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Landing Pages --}}
        @elseif ($section['key'] === 'landing_pages')
            <section class="section long" data-section="landing_pages">
                <h2>{{ $section['title'] }}</h2>
                @if (!empty($data['pages']))
                    <table>
                        <thead><tr><th>Page</th><th class="num">Clicks</th><th class="num">Impressions</th><th class="num">CTR</th><th class="num">Avg. position</th></tr></thead>
                        <tbody>
                            @foreach ($data['pages'] as $row)
                                <tr data-page="{{ $row['page_url'] }}"><td class="url">{{ $row['page_url'] }}@if (!empty($row['page_title']))<div class="small muted">{{ $row['page_title'] }}</div>@endif</td><td class="num">{{ $n($row['clicks']) }}</td><td class="num">{{ $n($row['impressions']) }}</td><td class="num">{{ $d($row['ctr'], '%') }}</td><td class="num">{{ $d($row['average_position']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="empty">No landing pages were recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="landing_pages">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Rankings --}}
        @elseif ($section['key'] === 'rankings')
            <section class="section long" data-section="rankings">
                <h2>{{ $section['title'] }}</h2>
                @if (!empty($data['keywords']))
                    @php $s = $data['summary'] ?? []; @endphp
                    <div class="cards">
                        <div class="card"><div class="label">Tracked keywords</div><div class="value">{{ $data['tracked_count'] ?? count($data['keywords']) }}</div></div>
                        <div class="card"><div class="label">Improved</div><div class="value" data-metric="improved">{{ $s['improved'] ?? 0 }}</div></div>
                        <div class="card"><div class="label">Declined</div><div class="value">{{ $s['declined'] ?? 0 }}</div></div>
                        <div class="card"><div class="label">Unchanged</div><div class="value">{{ $s['unchanged'] ?? 0 }}</div></div>
                    </div>
                    <table>
                        <thead><tr><th>Keyword</th><th>Location</th><th class="num">Start of month</th><th class="num">Latest</th><th>Movement</th></tr></thead>
                        <tbody>
                            @foreach ($data['keywords'] as $row)
                                <tr data-keyword="{{ $row['keyword'] }}">
                                    <td>{{ $row['keyword'] }}@if (($row['role'] ?? null) === 'primary') <span class="badge flat">Primary</span>@endif</td>
                                    <td>{{ $row['location'] ?? '—' }}</td>
                                    <td class="num">{{ $pos($row['month_start_position']) }}</td>
                                    <td class="num">{{ $pos($row['latest_position']) }}</td>
                                    <td><span class="badge {{ $movementClass($row['movement']['direction'] ?? null) }}">{{ $row['movement']['label'] ?? '—' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="empty">No active keywords are tracked for this project.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="rankings">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Audience by Country --}}
        @elseif ($section['key'] === 'audience_country')
            <section class="section long" data-section="audience_country">
                <h2>{{ $section['title'] }}</h2>
                @if (!empty($data['countries']))
                    <table>
                        <thead><tr><th>Country</th><th class="num">Active users</th><th class="num">New users</th><th class="num">Sessions</th><th class="num">Engaged</th><th class="num">Engagement rate</th><th class="num">Key events</th></tr></thead>
                        <tbody>
                            @foreach ($data['countries'] as $row)
                                <tr data-country="{{ $row['country'] }}"><td>{{ $row['country'] }}</td><td class="num">{{ $n($row['active_users']) }}</td><td class="num">{{ $n($row['new_users']) }}</td><td class="num">{{ $n($row['sessions']) }}</td><td class="num">{{ $n($row['engaged_sessions']) }}</td><td class="num">{{ $d($row['engagement_rate'], '%') }}</td><td class="num">{{ $n($row['key_events']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="empty">No audience-by-country data was recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="audience_country">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Backlinks --}}
        @elseif ($section['key'] === 'backlinks')
            <section class="section long" data-section="backlinks">
                <h2>{{ $section['title'] }}</h2>
                @php $progress = $data['progress'] ?? []; $totals = $data['totals'] ?? []; @endphp
                <div class="cards">
                    <div class="card"><div class="label">Live backlinks</div><div class="value" data-metric="backlinks_progress">{{ $progress['backlinks']['display'] ?? '—' }}</div><div class="sub">delivered / monthly target</div></div>
                    <div class="card"><div class="label">Guest posts</div><div class="value">{{ $progress['guest_posts']['display'] ?? '—' }}</div><div class="sub">delivered / monthly target</div></div>
                    <div class="card"><div class="label">Links recorded</div><div class="value">{{ $n($totals['recorded'] ?? 0) }}</div></div>
                    <div class="card"><div class="label">Live this month</div><div class="value">{{ $n($totals['live'] ?? 0) }}</div></div>
                </div>
                @if (!empty($data['links']))
                    <table>
                        <thead><tr><th>Published</th><th>URL</th><th>Anchor</th><th>Type</th><th>Status</th><th class="num">DA</th><th class="num">DR</th></tr></thead>
                        <tbody>
                            @foreach ($data['links'] as $link)
                                <tr data-backlink="{{ $link['published_url'] }}"><td>{{ $link['published_date'] ?? '—' }}</td><td class="url">{{ $link['published_url'] }}</td><td>{{ $link['anchor_text'] ?? '—' }}</td><td>{{ $typeLabel($link['type']) }}</td><td>{{ $statusLabel($link['status']) }}</td><td class="num">{{ $link['domain_authority'] ?? '—' }}</td><td class="num">{{ $link['domain_rating'] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="empty">No links were recorded for this month.</p>
                @endif
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="backlinks">{{ $section['custom_text'] }}</p>@endif
            </section>

        {{-- Recommendations --}}
        @elseif ($section['key'] === 'recommendations')
            <section class="section" data-section="recommendations">
                <h2>{{ $section['title'] }}</h2>
                @if (filled($section['custom_text'] ?? null))<p class="narrative" data-custom-text="recommendations">{{ $section['custom_text'] }}</p>@endif
                @if (!empty($data['recommendations']))
                    <h3>Recommendations</h3>
                    @foreach ($data['recommendations'] as $note)
                        <div class="note" data-note="recommendation">@if ($note['title'])<div class="title">{{ $note['title'] }}</div>@endif<div class="narrative">{{ $note['body'] }}</div></div>
                    @endforeach
                @endif
                @if (!empty($data['next_month_focus']))
                    <h3>Next month's focus</h3>
                    @foreach ($data['next_month_focus'] as $note)
                        <div class="note next" data-note="next_month_focus">@if ($note['title'])<div class="title">{{ $note['title'] }}</div>@endif<div class="narrative">{{ $note['body'] }}</div></div>
                    @endforeach
                @endif
                @if (empty($data['recommendations']) && empty($data['next_month_focus']) && blank($section['custom_text'] ?? null))
                    <p class="empty">No recommendations were recorded for this month.</p>
                @endif
            </section>
        @endif
    @endforeach

    <div class="footer">
        {{ $project['name'] ?? '' }} · {{ $period['label'] ?? '' }} · Prepared for {{ $client['name'] ?? '' }}
    </div>
</div>
</body>
</html>
