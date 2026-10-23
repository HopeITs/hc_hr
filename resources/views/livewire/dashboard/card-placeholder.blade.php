{{--
    Lazy placeholder for dashboard cards. Same footprint as the loaded card (min-height),
    so hydrating it does not shift the grid. aria-busy + sr-only text announce the wait;
    the skeleton itself is decorative.

    title: the card heading, shown immediately so the card is recognisable while loading
--}}

<div
    class="flex h-full min-h-64 w-full flex-col gap-4 overflow-hidden rounded-surface bg-white px-6 py-6 shadow-e1 ring-1 ring-neutral-200"
    aria-busy="true"
    data-state="loading"
>
    <h2 class="text-h4 font-semibold text-secondary-medium">{{ $title }}</h2>
    <span class="sr-only" role="status">{{ __('general.loading') }}…</span>
    <x-ui.skeleton variant="line" :lines="4" />
    <x-ui.skeleton variant="line" :lines="3" />
</div>
