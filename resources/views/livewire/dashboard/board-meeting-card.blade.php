{{--
    "Next board meeting" tile (BoardMeetingCard): the day and time, a countdown, the viewer's own place at it, and
    the follow-up rows that wait on them. Nothing scheduled: one quiet line.
--}}
<div class="flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm" data-testid="board-meeting-tile">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.board-meeting') }}</h2>
            <x-ic name="calendar" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>

        <div class="flex min-h-0 flex-1 flex-col gap-3 px-4 pb-4">
            @if ($meeting === null)
                <p class="hc-nt-empty">{{ __('board.tile.none') }}</p>
            @else
                <a href="{{ route('board.meetings.show', $meeting) }}" wire:navigate class="flex items-baseline gap-3 hover:underline">
                    <span class="hc-nt-big num">{{ $days === 0 ? __('board.tile.today') : $days }}</span>
                    <span class="min-w-0">
                        @if ($days !== 0)<span class="block text-small font-bold text-ink-2">{{ trans_choice('board.tile.days-left', $days, ['count' => $days]) }}</span>@endif
                        <span class="block truncate text-body font-bold text-ink">
                            {{ __('board.meeting-number', ['number' => $meeting->number]) }} ·
                            <time datetime="{{ $meeting->starts_at->toIso8601String() }}">{{ $meeting->starts_at->translatedFormat('l j F') }} {{ $meeting->starts_at->format('H:i') }}</time>
                        </span>
                    </span>
                </a>
                @if ($mine?->absence_request === 'pending')
                    <x-status tone="wait" icon="clock" :label="__('board.absence.waiting')" />
                @elseif ($mine?->status === 'absent')
                    <x-status tone="stop" icon="x" :label="__('board.tile.excused')" />
                @endif
            @endif

            @if ($followUps->isNotEmpty())
                <div class="border-t border-line-soft pt-2">
                    <p class="text-small font-bold text-ink">{{ __('board.tile.my-follow-ups') }}</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($followUps as $f)
                            <li class="truncate text-small text-ink-2">
                                <a href="{{ route('board.meetings.show', $f->board_meeting_id) }}" wire:navigate class="hover:underline">{{ $f->topic }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
