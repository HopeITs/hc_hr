<div class="flex h-full min-h-64 w-full flex-col overflow-hidden rounded-surface bg-white ring-1 ring-neutral-200 shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.upcoming-leaves')" :count="$loadFailed ? null : $upcomingLeaves->count()" />

    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 pb-5 pt-2 text-start scrollbar-thin scrollbar-thumb-[var(--color-secondary-light)] scrollbar-track-transparent">
        @if ($loadFailed)
            <x-ui.load-error class="min-h-40" />
        @else
        @forelse($upcomingLeaves as $entry)
            @php
                $from = \Carbon\Carbon::parse($entry['from']);
                $to = \Carbon\Carbon::parse($entry['to']);
            @endphp
            {{-- A TeamCoverage projection (C5): opaque key at colleague level, no reason, no balance. --}}
            <div wire:key="upcoming-leave-{{ $entry['key'] }}" class="flex items-start gap-3" data-testid="upcoming-leave">
                <div class="flex size-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-control bg-neutral-100 pt-1 text-center text-secondary-dark" aria-hidden="true">
                    <span class="block text-base font-bold leading-none">{{ $from->format('d') }}</span>
                    <span class="block max-w-full truncate px-0.5 text-caption font-medium leading-none">{{ $from->copy()->locale(app()->getLocale())->isoFormat('MMM') }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="break-words text-body font-bold leading-5 text-secondary-dark">{{ $entry['name'] }}</p>
                    <p class="mt-1 text-small font-normal leading-5 text-secondary-medium">
                        @if($from->isSameDay($to))
                            @if($from->isToday())
                                {{ __('general.today') }}
                            @elseif($from->isTomorrow())
                                {{ __('general.tomorrow') }}
                            @else
                                {{ $from->copy()->locale(app()->getLocale())->isoFormat('D MMM') }}
                            @endif
                        @else
                            {{ $from->copy()->locale(app()->getLocale())->isoFormat('D MMM') }} – {{ $to->copy()->locale(app()->getLocale())->isoFormat('D MMM') }}
                        @endif
                        @if($entry['hours'])
                            <bdi dir="ltr">{{ $entry['hours'] }}</bdi>
                        @elseif($entry['partial'])
                            · {{ __('team_calendar.partial') }}
                        @endif
                        <span aria-hidden="true"> · </span>{{ $entry['type'] }}
                    </p>
                </div>
            </div>
        @empty
            <x-center-empty-state variant="empty" :title="__('general.no-available-data')" :description="''" class="min-h-40! border-0 shadow-none">
                @if (auth()->user()->hasActivePermission('leaves.view-own'))
                    <x-slot:action>
                        <a href="{{ route('leaves.index') }}" wire:navigate class="inline-flex min-h-11 items-center justify-center rounded-control px-3 text-small font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('general.my-leaves') }}</a>
                    </x-slot:action>
                @endif
            </x-center-empty-state>
        @endforelse
        @endif
    </div>

    @if(! $loadFailed && $upcomingLeaves->count() > 3)
        <div class="flex shrink-0 justify-center bg-white px-6 pb-2">
            <a href="{{ $calendarUrl }}" wire:navigate class="inline-flex min-h-11 items-center justify-center gap-1 rounded-control px-3 text-small font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('general.view-all') }}<span aria-hidden="true" class="rtl:rotate-180">→</span>
            </a>
        </div>
    @endif
</div>
