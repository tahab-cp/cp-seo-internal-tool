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
        $n = fn ($v) => $v === null ? '—' : number_format((float) $v);
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ $cycle->periodLabel() }} report</x-slot>
        <x-slot name="description">{{ $project->name }}@if ($project->client) · {{ $project->client->name }}@endif</x-slot>

        <div class="grid gap-6 md:grid-cols-4" data-report-status="{{ $report->status->value }}" data-readiness="{{ $readiness->percentage() }}">
            <div>
                <div class="text-xs text-gray-500">Status</div>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$report->status->getColor()">{{ $report->status->getLabel() }}</x-filament::badge>
                    <span class="text-sm font-semibold" data-report-version="{{ $report->version }}">{{ $isFinal ? 'Current version' : 'Preparing' }}: {{ $report->versionLabel() }}</span>
                </div>
                @if ($cycle->isLocked())
                    <div class="mt-1 text-xs text-gray-500" data-period-locked>Reporting period locked</div>
                @endif
                @if ($isFinal && Illuminate\Support\Facades\Gate::allows('unlock', $report))
                    <div class="mt-3">{{ $this->unlockAction }}</div>
                @endif
            </div>
            <div>
                <div class="text-xs text-gray-500">Readiness</div>
                <div class="text-2xl font-semibold" data-readiness-percentage>{{ $readiness->percentage() }}%</div>
                <div class="text-xs text-gray-500" data-readiness-label>{{ $readiness->completedRequiredCount() }} / {{ $readiness->requiredCount() }} required sections complete</div>
            </div>
            <div class="md:col-span-2">
                @if ($isFinal)
                    <div class="text-xs text-gray-500">Finalized</div>
                    <div class="text-sm" data-finalized-by>{{ $report->finalizedBy?->name ?? 'Unknown' }}</div>
                    <div class="text-xs text-gray-500" data-finalized-at>{{ $report->finalized_at?->format('j M Y H:i') }}</div>
                @elseif (! $readiness->isReady())
                    <div class="text-xs text-gray-500">Missing before this report can be marked ready</div>
                    <ul class="mt-1 list-disc pl-5 text-sm" data-missing-sections>
                        @foreach ($readiness->missing() as $missing)
                            <li data-missing="{{ $missing->key->value }}"><span class="font-medium">{{ $missing->title }}</span> <span class="text-gray-500">— {{ $missing->reason }}</span></li>
                        @endforeach
                    </ul>
                @else
                    <div class="text-sm text-success-600" data-report-ready>All required sections are complete.
                        @if ($report->isDraft()) Use “Mark Ready for Review”.@elseif ($report->isReadyForReview()) A manager can now finalize.@endif
                    </div>
                @endif
            </div>
        </div>

        @if (Illuminate\Support\Facades\Gate::allows('reviewNotes', $report) || (filled($report->review_notes) && Illuminate\Support\Facades\Gate::allows('finalize', $report)))
            <div class="mt-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <div class="text-xs font-medium text-gray-500">Internal review notes (never shown to the client)</div>
                    {{ $this->editReviewNotesAction }}
                </div>
                <p class="mt-1 whitespace-pre-line text-sm" data-review-notes>{{ $report->review_notes ?? '—' }}</p>
            </div>
        @endif
    </x-filament::section>

    @if ($report->isCorrection() && $revisions->isNotEmpty())
        @php $previous = $revisions->first(); @endphp
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-500/40 dark:bg-warning-500/10" data-correction-banner>
            <div class="font-semibold text-warning-800 dark:text-warning-200">Correction in progress</div>
            <div class="mt-2 grid gap-4 md:grid-cols-2">
                <div>
                    <div class="text-xs text-gray-500">Previous final</div>
                    <div class="font-medium" data-previous-version="{{ $previous->version }}">Version {{ $previous->version }}</div>
                    <div class="text-xs text-gray-500">Finalized {{ $previous->finalized_at?->format('j M Y H:i') }}@if ($previous->finalizedBy) by {{ $previous->finalizedBy->name }}@endif</div>
                    <div class="text-xs text-gray-500">Unlock reason: {{ $previous->unlock_reason }}</div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <x-filament::link :href="$this->revisionPreviewUrl($previous)" target="_blank" size="sm">View Previous Final</x-filament::link>
                        @if ($previous->hasPdf())
                            <x-filament::link :href="$this->revisionPdfUrl($previous)" target="_blank" size="sm">Download Previous PDF</x-filament::link>
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-xs text-gray-500">Current</div>
                    <div class="font-medium">Version {{ $report->version }} {{ $report->status->getLabel() }}</div>
                    <div class="text-xs text-gray-500">Correct the month's data, then mark the report ready and finalize it again.</div>
                </div>
            </div>
        </div>
    @endif

    {{-- Executive summary --}}
    <x-filament::section>
        <x-slot name="heading">Executive summary</x-slot>
        <x-slot name="description">Client-facing narrative. Wins, challenges and observations from Monthly Work are added automatically to the rendered report.</x-slot>
        @if ($canPrepare)
            <x-slot name="afterHeader">{{ $this->editExecutiveSummaryAction }}</x-slot>
        @endif

        @if (filled($report->executive_summary))
            <p class="whitespace-pre-line text-sm" data-executive-summary>{{ $report->executive_summary }}</p>
        @else
            <p class="text-sm text-gray-500" data-executive-summary-empty>No executive summary yet.</p>
        @endif
    </x-filament::section>

    {{-- Sections in snapshot order --}}
    <x-filament::section>
        <x-slot name="heading">Report sections</x-slot>
        <x-slot name="description">Snapshotted when this report was started. Disabled sections are omitted from the client report. Numbers come from the month's source data.</x-slot>

        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($sections as $section)
                @php
                    $live = $readiness->section($section->section_key->value);
                    $data = $sectionData[$section->section_key->value]['data'] ?? [];
                    $summary = match ($section->section_key->value) {
                        'executive_summary' => filled($report->executive_summary) ? 'Summary written · '.count($data['wins'] ?? []).' wins, '.(count($data['challenges'] ?? []) + count($data['observations'] ?? [])).' challenges/observations' : 'No summary yet',
                        'site_authority' => ($data['available'] ?? false) ? 'DA '.$n($data['moz_domain_authority']).' · DR '.($data['ahrefs_domain_rating'] ?? '—').' · '.$n($data['backlinks_count']).' backlinks' : 'No authority metrics',
                        'organic_search' => ($data['available'] ?? false) ? $n($data['clicks']).' clicks · '.$n($data['impressions']).' impressions' : 'No GSC summary',
                        'website_traffic' => ($data['available'] ?? false) ? $n($data['active_users']).' active users · '.$n($data['sessions']).' sessions' : 'No GA4 summary',
                        'top_keywords' => count($data['queries'] ?? []).' queries',
                        'landing_pages' => count($data['pages'] ?? []).' landing pages',
                        'rankings' => ($data['tracked_count'] ?? 0).' tracked keywords · '.($data['summary']['improved'] ?? 0).' improved, '.($data['summary']['declined'] ?? 0).' declined',
                        'audience_country' => count($data['countries'] ?? []).' countries',
                        'backlinks' => 'Backlinks '.($data['progress']['backlinks']['display'] ?? '—').' · Guest posts '.($data['progress']['guest_posts']['display'] ?? '—').' · '.($data['totals']['recorded'] ?? 0).' links recorded',
                        'recommendations' => count($data['recommendations'] ?? []).' recommendations · '.count($data['next_month_focus'] ?? []).' next-month focus',
                        default => '',
                    };
                @endphp
                <li class="flex flex-wrap items-start justify-between gap-3 py-3" data-report-section="{{ $section->section_key->value }}" data-section-order="{{ $section->sort_order }}" data-section-enabled="{{ $section->is_enabled ? 1 : 0 }}">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ $section->title }}</span>
                            @if (! $section->is_enabled)
                                <x-filament::badge color="gray" size="sm">Disabled</x-filament::badge>
                            @else
                                <x-filament::badge :color="$section->is_required ? 'warning' : 'gray'" size="sm">{{ $section->is_required ? 'Required' : 'Optional' }}</x-filament::badge>
                                <x-filament::badge :color="$live?->complete ? 'success' : 'danger'" size="sm" data-section-state="{{ $live?->complete ? 'complete' : 'missing' }}">{{ $live?->complete ? 'Complete' : 'Missing' }}</x-filament::badge>
                            @endif
                        </div>
                        @if ($section->is_enabled)
                            <div class="mt-1 text-xs text-gray-500">{{ $summary }}@if ($live && ! $live->complete && $live->reason) · {{ $live->reason }}@endif</div>
                            @if (filled($section->custom_text))
                                <p class="mt-2 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200" data-section-text="{{ $section->section_key->value }}">{{ $section->custom_text }}</p>
                            @endif
                        @endif
                    </div>
                    @if ($section->is_enabled && $canPrepare && $section->section_key !== \App\Enums\ReportSectionKey::ExecutiveSummary)
                        <div>{{ ($this->editSectionTextAction)(['section' => $section->getKey()]) }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    {{-- Version history --}}
    <x-filament::section>
        <x-slot name="heading">Version history</x-slot>
        <x-slot name="description">Every superseded final is preserved with its own snapshot and PDF. Archived versions are read-only evidence and never rebuilt from live data.</x-slot>

        <table class="w-full text-sm" data-version-history>
            <thead class="text-left text-xs text-gray-500">
                <tr><th class="py-1">Version</th><th class="py-1">State</th><th class="py-1">Finalized</th><th class="py-1">By</th><th class="py-1">Notes</th><th class="py-1 text-right">Actions</th></tr>
            </thead>
            <tbody>
                <tr class="border-t border-gray-100 dark:border-gray-800" data-version-row="{{ $report->version }}" data-version-state="{{ $isFinal ? 'final' : $report->status->value }}">
                    <td class="py-2 font-semibold">{{ $report->versionLabel() }}</td>
                    <td class="py-2"><x-filament::badge :color="$report->status->getColor()" size="sm">{{ $isFinal ? 'Final' : $report->status->getLabel() }}</x-filament::badge></td>
                    <td class="py-2">{{ $report->finalized_at?->format('j M Y H:i') ?? '—' }}</td>
                    <td class="py-2">{{ $report->finalizedBy?->name ?? '—' }}</td>
                    <td class="py-2 text-xs text-gray-500">{{ $isFinal ? 'Current final report' : 'In preparation' }}</td>
                    <td class="py-2 text-right">
                        @if ($isFinal)
                            <x-filament::link :href="$this->previewUrl()" target="_blank" size="sm">View</x-filament::link>
                            @if ($report->hasPdf())· <x-filament::link :href="$this->pdfUrl()" target="_blank" size="sm">PDF</x-filament::link>@endif
                        @endif
                    </td>
                </tr>
                @foreach ($revisions as $revision)
                    <tr class="border-t border-gray-100 dark:border-gray-800" data-version-row="{{ $revision->version }}" data-version-state="superseded" data-revision="{{ $revision->getKey() }}">
                        <td class="py-2 font-semibold">{{ $revision->versionLabel() }}</td>
                        <td class="py-2"><x-filament::badge color="gray" size="sm">Superseded</x-filament::badge></td>
                        <td class="py-2">{{ $revision->finalized_at?->format('j M Y H:i') }}</td>
                        <td class="py-2">{{ $revision->finalizedBy?->name ?? '—' }}</td>
                        <td class="py-2 text-xs text-gray-500">Unlock reason: {{ $revision->unlock_reason }} <span class="text-gray-400">({{ $revision->archivedBy?->name ?? 'Unknown' }}, {{ $revision->archived_at?->format('j M Y H:i') }})</span></td>
                        <td class="py-2 text-right">
                            <x-filament::link :href="$this->revisionPreviewUrl($revision)" target="_blank" size="sm">View</x-filament::link>
                            @if ($revision->hasPdf())· <x-filament::link :href="$this->revisionPdfUrl($revision)" target="_blank" size="sm">PDF</x-filament::link>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    {{-- Audit timeline --}}
    <x-filament::section>
        <x-slot name="heading">Audit history</x-slot>
        <x-slot name="description">Immutable record of who marked this report ready, finalized it, or unlocked it for correction.</x-slot>

        @if ($auditEvents->isEmpty())
            <p class="text-sm text-gray-500" data-audit-empty>No lifecycle events yet.</p>
        @else
            <ol class="space-y-2 text-sm" data-audit-timeline>
                @foreach ($auditEvents as $event)
                    <li class="flex flex-wrap items-baseline gap-2" data-audit-event="{{ $event->event_type->value }}" data-audit-version="{{ $event->version() ?? '' }}">
                        <span class="w-32 shrink-0 text-xs text-gray-500">{{ $event->created_at?->format('j M Y H:i') }}</span>
                        <x-filament::badge :color="$event->event_type->getColor()" size="sm">{{ $event->event_type->getLabel() }}</x-filament::badge>
                        <span>Report v{{ $event->version() ?? '?' }} by {{ $event->user?->name ?? 'Unknown' }}</span>
                        @if ($event->reason)
                            <span class="text-gray-500">— Reason: {{ $event->reason }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-panels::page>
