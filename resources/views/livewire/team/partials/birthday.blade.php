{{--
    One birthday as a day label: the day only, never the year or an age
    (TeamCoverage::birthdays). Neutral, like any other mention: red is kept
    for what waits on the viewer. $inline: the list's pill form.
--}}
@php($inline ??= false)
<span @class([
          'flex min-w-0 items-center gap-1 truncate border border-neutral-200 bg-white text-[11px] text-neutral-700',
          'rounded-md px-1.5 py-0.5' => ! $inline,
          'rounded-full px-2.5 py-0.5' => $inline,
          'font-semibold' => $person['is_self'],
      ])
      data-testid="calendar-birthday"
      title="{{ $person['is_self'] ? __('team_calendar.your_birthday') : __('team_calendar.birthday_of', ['name' => $person['name']]) }}">
    <svg class="size-3 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3.5 17h13M4.5 17v-5.5a1.5 1.5 0 011.5-1.5h8a1.5 1.5 0 011.5 1.5V17M4.5 13.5c1 .8 2.2.8 3 0s2.2-.8 3 0 2.2.8 3 0 1.5-.5 2 0M10 10V7.5M10 5.5c-.8-.7-.8-1.6 0-2.5.8.9.8 1.8 0 2.5z"/></svg>
    <span class="truncate">{{ $person['is_self'] ? __('team_calendar.your_birthday') : $person['name'] }}</span>
    <span class="sr-only">· {{ __('team_calendar.birthday') }}</span>
</span>
