{{--
    Project workspace header: status / website / package meta row and the
    module navigation grid (2 / sm:3 / lg:5 / xl:6 per row). Presentation
    only; the page passes the project and the pre-built module list.

    @var \App\Models\Project $project
    @var list<array{key: string, label: string, icon: string, url: string, current: bool}> $modules
--}}
@php
    $website = preg_replace('#^https?://(www\.)?#i', '', rtrim((string) $project->website_url, '/'));
@endphp

<div style="display: flex; flex-wrap: wrap; align-items: center; gap: calc(var(--spacing) * 3)" data-project-header data-project-status="{{ $project->status->value }}">
    <x-filament::badge :color="$project->status->getColor()" size="lg">{{ $project->status->getLabel() }}</x-filament::badge>
    @if ($project->trashed())
        <x-filament::badge color="warning" size="lg">Archived {{ $project->deleted_at?->format('j M Y') }}</x-filament::badge>
    @endif
    @if ($project->website_url)
        <x-filament::link :href="$project->website_url" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square" icon-position="after" size="sm" data-project-website>{{ $website }}</x-filament::link>
    @endif
    @if ($project->package)
        <span class="fi-section-header-description" style="font-size: var(--text-sm)">Package: {{ $project->package->name }}</span>
    @endif
</div>

<nav aria-label="Project modules" {{ (new \Filament\Support\View\ComponentAttributeBag)->grid(['default' => 2, 'sm' => 3, 'lg' => 5, 'xl' => 6])->style(['gap: calc(var(--spacing) * 2)'])->merge(['data-project-modules' => ''], escape: false) }}>
    @foreach ($modules as $module)
        <x-filament::button
            tag="a"
            :href="$module['url']"
            :color="$module['current'] ? 'primary' : 'gray'"
            :outlined="! $module['current']"
            :icon="$module['icon']"
            size="sm"
            style="justify-content: flex-start; width: 100%"
            data-project-module="{{ $module['key'] }}"
            :aria-current="$module['current'] ? 'page' : null"
        >{{ $module['label'] }}</x-filament::button>
    @endforeach
</nav>
