<x-filament-panels::page>
    @php
        $cycles = $this->getCycles();
        $cycle = $this->getSelectedCycle();
        $backlinks = $this->getBacklinksProgress();
        $guestPosts = $this->getGuestPostsProgress();
        $breakdown = $this->getLiveTypeBreakdown();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Monthly progress</x-slot>
        <x-slot name="description">Only Live links count. A live guest post counts toward both Backlinks and Guest Posts. Targets are the month's snapshot.</x-slot>

        <div class="flex flex-wrap items-start gap-8">
            <div class="w-56">
                <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Reporting month</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="selectedCycle">
                        <option value="all">All time</option>
                        @foreach ($cycles as $option)
                            <option value="{{ $option->getKey() }}">{{ $option->periodLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($cycle && $backlinks && $guestPosts)
                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        Live backlinks — {{ $cycle->periodLabel() }}
                        @if ($cycle->isLocked())
                            <x-filament::badge color="gray" size="sm">Locked</x-filament::badge>
                        @endif
                    </div>
                    <div class="text-2xl font-semibold" data-backlinks-actual="{{ $backlinks->actual }}" data-backlinks-target="{{ $backlinks->target ?? '' }}">
                        {{ $backlinks->format() }}
                    </div>
                    <div class="text-xs {{ $backlinks->isOverTarget() ? 'text-success-600' : 'text-gray-500 dark:text-gray-400' }}" data-backlinks-remaining="{{ $backlinks->remaining() ?? '' }}">
                        {{ $backlinks->remainingLabel() ?? 'No target configured for this month' }}
                    </div>
                </div>

                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Guest posts</div>
                    <div class="text-2xl font-semibold" data-guest-posts-actual="{{ $guestPosts->actual }}" data-guest-posts-target="{{ $guestPosts->target ?? '' }}">
                        {{ $guestPosts->format() }}
                    </div>
                    <div class="text-xs {{ $guestPosts->isOverTarget() ? 'text-success-600' : 'text-gray-500 dark:text-gray-400' }}" data-guest-posts-remaining="{{ $guestPosts->remaining() ?? '' }}">
                        {{ $guestPosts->remainingLabel() ?? 'No target configured for this month' }}
                    </div>
                </div>

                <div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">Live links by type</div>
                    @if ($breakdown === [])
                        <div class="text-sm text-gray-500 dark:text-gray-400">No live links this month.</div>
                    @else
                        <dl class="mt-1 grid grid-cols-2 gap-x-6 gap-y-0.5 text-sm">
                            @foreach ($breakdown as $label => $count)
                                <dt class="text-gray-600 dark:text-gray-300">{{ $label }}</dt>
                                <dd class="text-right font-semibold" data-type-breakdown="{{ \Illuminate\Support\Str::slug($label, '_') }}">{{ $count }}</dd>
                            @endforeach
                        </dl>
                    @endif
                </div>
            @else
                <p class="self-center text-sm text-gray-500 dark:text-gray-400">
                    All-time view: every backlink recorded for this project. Select a month to see progress against its targets.
                </p>
            @endif
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
