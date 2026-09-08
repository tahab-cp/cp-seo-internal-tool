<x-filament-panels::page>
    @php
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $progress = $this->getPagesOptimisedProgress();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Monthly progress</x-slot>
        <x-slot name="description">Pages Optimised counts each page once per month, against that month's snapshotted target.</x-slot>

        @if ($cycles->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No monthly cycles yet. Progress appears once the project has a reporting month.
            </p>
        @else
            <div class="flex flex-wrap items-end gap-6">
                <div class="w-56">
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Reporting month</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select wire:model.live="selectedCycleId">
                            @foreach ($cycles as $option)
                                <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @if ($cycle && $progress)
                    <div>
                        <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ $progress->label }} — {{ $cycle->periodLabel() }}
                            @if ($cycle->isLocked())
                                <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                            @endif
                        </div>
                        <div class="text-2xl font-semibold" data-pages-optimised="{{ $progress->actual }}" data-pages-target="{{ $progress->target ?? '' }}">
                            {{ $progress->format() }}
                        </div>
                        @if (! $progress->hasTarget())
                            <div class="text-xs text-gray-500 dark:text-gray-400">No pages-optimised target was configured when this month was created.</div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
