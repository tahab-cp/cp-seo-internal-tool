<x-filament-panels::page>
    @php
        $page = $this->getPage();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Page</x-slot>
        <x-slot name="description">Project master data. Editable regardless of locked months; only monthly optimisation history locks.</x-slot>

        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Title</dt>
                <dd data-page-title>{{ $page->title ?? '—' }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="font-medium text-gray-500 dark:text-gray-400">URL</dt>
                <dd class="break-all"><a href="{{ $page->url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline">{{ $page->url }}</a></dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Path</dt>
                <dd>{{ $page->path ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Page type</dt>
                <dd>{{ $page->page_type ?? '—' }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-500 dark:text-gray-400">Status</dt>
                <dd>
                    <x-filament::badge :color="$page->status->getColor()">
                        {{ $page->status->getLabel() }}
                    </x-filament::badge>
                </dd>
            </div>
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Optimisation history</x-slot>
        <x-slot name="description">Every recorded event stays visible. Monthly progress counts this page once per reporting month.</x-slot>

        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
