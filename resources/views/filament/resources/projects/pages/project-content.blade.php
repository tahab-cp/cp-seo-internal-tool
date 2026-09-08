<x-filament-panels::page>
    @php
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $blogs = $this->getBlogsProgress();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Monthly progress</x-slot>
        <x-slot name="description">Only published blogs attributed to the month count. The target is the month's snapshot.</x-slot>

        <div class="flex flex-wrap items-start gap-8">
            <div class="w-64">
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">View</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedView">
                        <option value="all">All content</option>
                        <option value="unscheduled">Unscheduled (project-level)</option>
                        @foreach ($cycles as $option)
                            <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($cycle && $blogs)
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        Blogs published — {{ $cycle->periodLabel() }}
                        @if ($cycle->isLocked())
                            <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                        @endif
                    </div>
                    <div class="text-2xl font-semibold" data-blogs-actual="{{ $blogs->actual }}" data-blogs-target="{{ $blogs->target ?? '' }}">
                        {{ $blogs->format() }}
                    </div>
                    <div class="text-xs {{ $blogs->isOverTarget() ? 'text-success-600' : 'text-gray-500 dark:text-gray-400' }}" data-blogs-remaining="{{ $blogs->remaining() ?? '' }}">
                        {{ $blogs->remainingLabel() ?? 'No target configured for this month' }}
                    </div>
                </div>
            @elseif ($this->selectedView === 'unscheduled')
                <p class="self-center text-sm text-gray-500 dark:text-gray-400">
                    Unscheduled view: ideas and long-term items not yet attributed to a reporting month. They count toward no monthly target.
                </p>
            @else
                <p class="self-center text-sm text-gray-500 dark:text-gray-400">
                    All content for this project. Select a month to see Blogs Published against its target.
                </p>
            @endif
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
