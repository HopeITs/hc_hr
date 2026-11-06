{{--
    One organization event (News & Events) on a day: a link to its page. Told
    apart from leave, holidays and birthdays by shape and words, never colour:
    a card with a solid ink edge on the reading-start side and a calendar
    icon. The time shows on the day it starts unless it is a whole-day event.
    $inline: the list's pill form (touch-sized).
--}}
@php($inline ??= false)
<a href="{{ $event['url'] }}" wire:navigate
   @class([
       'flex min-w-0 items-center gap-1 truncate border border-line-soft border-s-[3px] border-s-ink bg-card text-caption text-ink transition hover:bg-hover hover:underline underline-offset-2',
       'rounded-md px-1.5 py-0.5' => ! $inline,
       'min-h-11 rounded-control px-3 text-small sm:min-h-9' => $inline,
   ])
   data-testid="calendar-event"
   title="{{ $event['title'] }}{{ $event['time'] ? ' · '.$event['time'] : '' }}"
   aria-label="{{ __('team_calendar.events.open', ['title' => $event['title']]) }}{{ $event['time'] ? ', '.$event['time'] : '' }}">
    <x-ic name="calendar" class="size-3 shrink-0" />
    @if ($event['time'])
        <bdi dir="ltr" class="num shrink-0 font-semibold">{{ $event['time'] }}</bdi>
    @endif
    <span class="truncate font-semibold">{{ $event['title'] }}</span>
    @unless ($event['first'])
        <span class="shrink-0 text-ink-2">· {{ __('team_calendar.events.continues') }}</span>
    @endunless
    <span class="sr-only">· {{ __('team_calendar.event') }}</span>
</a>
