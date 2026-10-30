{{--
    "Birthdays this week" tile (BirthdaysCard): faces, names and the weekday,
    never a year or an age. The list scrolls inside the tile; a wide tile
    lays it out in two columns, a narrow one drops the weekday under the name.
--}}
@php
    $locale = app()->getLocale();
@endphp
<div class="hc-bdays flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.birthdays') }}</h2>
            @if ($people->isNotEmpty())
                <span class="num text-small font-bold text-ink-2">{{ $people->count() }}</span>
            @endif
            <x-ic name="cake" class="hc-nt-icon ms-auto" aria-hidden="true" />
        </header>

        @if ($people->isEmpty())
            <p class="hc-nt-empty">{{ __('dashboard.birthdays.empty') }}</p>
        @else
            <ul class="hc-bdays-list" role="list">
                @foreach ($people as $person)
                    <li wire:key="bday-{{ $person['id'] }}">
                        <img src="{{ $person['avatar'] }}" alt="" loading="lazy" class="hc-nt-av">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-small font-bold text-ink">{{ $person['name'] }}</span>
                            <span class="block truncate text-caption text-ink-2">
                                <time datetime="--{{ $person['date']->format('m-d') }}">{{ $person['date']->locale($locale)->isoFormat('dddd D MMMM') }}</time>
                            </span>
                        </span>
                        @if ($person['date']->toDateString() === $today)
                            <x-tag variant="soft" icon="cake" class="shrink-0">{{ __('dashboard.birthdays.today') }}</x-tag>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
