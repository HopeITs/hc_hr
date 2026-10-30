{{--
    Lazy placeholder for dashboard cards. Same footprint as the loaded card (min-height),
    so hydrating it does not shift the grid. aria-busy + sr-only text announce the wait;
    the skeleton itself is decorative.

    title: the card heading, shown immediately so the card is recognisable while loading
--}}

<div
    class="flex h-full min-h-64 w-full flex-col gap-4 overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm px-6 py-6"
    aria-busy="true"
    data-state="loading"
>
    <h2 class="truncate text-h4 font-extrabold text-ink-2">{{ $title }}</h2>
    <span class="sr-only" role="status">{{ __('general.loading') }}…</span>
    <x-ui.skeleton variant="line" :lines="4" />
    <x-ui.skeleton variant="line" :lines="3" />
</div>
