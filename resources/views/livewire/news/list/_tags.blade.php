{{-- Tags cell: up to three chips, then "+n" for the rest; nothing when there are none. --}}
@php
    $shown = array_slice($tags, 0, 3);
    $more = count($tags) - count($shown);
@endphp
@if ($shown !== [])
    <ul class="flex max-w-64 flex-wrap items-center gap-1" role="list">
        @foreach ($shown as $tag)
            <li class="max-w-full"><x-tag variant="soft" class="max-w-full truncate"><bdi>{{ $tag }}</bdi></x-tag></li>
        @endforeach
        @if ($more > 0)
            <li class="text-caption font-semibold text-ink-2 num"
                title="{{ implode(', ', array_slice($tags, 3)) }}">
                <span aria-hidden="true">+{{ $more }}</span>
                <span class="sr-only">{{ __('news.list.more_tags', ['count' => $more]) }}</span>
            </li>
        @endif
    </ul>
@else
    <span class="text-ink-3" aria-hidden="true">—</span>
    <span class="sr-only">{{ __('news.list.no_tags') }}</span>
@endif
