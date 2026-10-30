@php
    $pageName = $paginator->getPageName();
    $currentPage = $paginator->currentPage();
    $lastPage = $paginator->lastPage();
@endphp

<nav
    class="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-line-soft bg-soft/70 px-3 py-2.5"
    role="navigation"
    aria-label="{{ __('access.pagination.label') }}"
>
    <p class="whitespace-nowrap text-xs font-semibold text-ink-2">
        @if ($paginator->total() > 0)
            <span class="tabular-nums text-ink">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            {{ __('access.pagination.of') }}
            <span class="tabular-nums text-ink">{{ $paginator->total() }}</span>
            <span class="hidden @sm/directory:inline">{{ __('access.pagination.employees') }}</span>
        @else
            {{ __('access.pagination.no-results') }}
        @endif
    </p>

    @if ($paginator->hasPages())
        <div class="flex items-center gap-1.5">
            @if ($paginator->onFirstPage())
                <span
                    class="inline-flex size-11 shrink-0 cursor-not-allowed items-center justify-center rounded-lg border border-line-soft bg-card text-ink-3"
                    aria-hidden="true"
                >
                    <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                    </svg>
                </span>
            @else
                <button
                    type="button"
                    wire:click="previousPage('{{ $pageName }}')"
                    wire:loading.attr="disabled"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg border border-line-soft bg-card text-ink-2 transition hover:border-line hover:bg-hover hover:text-ink disabled:pointer-events-none disabled:opacity-50"
                    aria-label="{{ __('access.pagination.previous') }}"
                >
                    <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                    </svg>
                </button>
            @endif

            <div
                class="relative"
                x-data="{ open: false }"
                x-on:click.outside="open = false"
                x-on:keydown.escape.window="open = false"
            >
                <button
                    id="access-employees-page"
                    type="button"
                    x-on:click="
                        open = ! open;
                        if (open) {
                            $nextTick(() => document.getElementById('access-page-option-{{ $currentPage }}')?.scrollIntoView({ block: 'nearest' }));
                        }
                    "
                    class="inline-flex min-h-11 w-[4.25rem] items-center justify-between rounded-lg border border-line-soft bg-card px-2.5 text-sm font-bold tabular-nums text-ink transition hover:border-line hover:bg-hover"
                    aria-label="{{ __('access.pagination.page') }}"
                    aria-haspopup="listbox"
                    x-bind:aria-expanded="open"
                >
                    <span>{{ $currentPage }}</span>
                    <svg class="size-3.5 text-ink-2 transition-transform" x-bind:class="{ 'rotate-180': open }"
                        viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M5.22 7.22a.75.75 0 0 1 1.06 0L10 10.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.28a.75.75 0 0 1 0-1.06Z"
                            clip-rule="evenodd" />
                    </svg>
                </button>

                <div
                    x-cloak
                    x-show="open"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="translate-y-1 opacity-0"
                    x-transition:enter-end="translate-y-0 opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="translate-y-0 opacity-100"
                    x-transition:leave-end="translate-y-1 opacity-0"
                    class="absolute bottom-[calc(100%+0.375rem)] start-0 z-30 max-h-[11.25rem] w-[4.25rem] overflow-y-auto overscroll-contain rounded-lg border border-line-soft bg-card shadow-lg"
                    role="listbox"
                    aria-labelledby="access-employees-page"
                >
                    @foreach (range(1, $lastPage) as $page)
                        <button
                            id="access-page-option-{{ $page }}"
                            type="button"
                            wire:key="access-page-option-{{ $page }}"
                            wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                            x-on:click="open = false"
                            @class([
                                'flex h-9 w-full items-center justify-center text-sm font-bold tabular-nums transition focus-visible:z-10',
                                'bg-primary text-on-brand' => $page === $currentPage,
                                'bg-card text-ink hover:bg-hover hover:text-ink' => $page !== $currentPage,
                            ])
                            role="option"
                            aria-selected="{{ $page === $currentPage ? 'true' : 'false' }}"
                        >
                            {{ $page }}
                        </button>
                    @endforeach
                </div>
            </div>

            <span class="whitespace-nowrap text-xs font-semibold tabular-nums text-ink-2">
                {{ __('access.pagination.of') }} {{ $lastPage }}
            </span>

            @if ($paginator->hasMorePages())
                <button
                    type="button"
                    wire:click="nextPage('{{ $pageName }}')"
                    wire:loading.attr="disabled"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-lg border border-line-soft bg-card text-ink-2 transition hover:border-line hover:bg-hover hover:text-ink disabled:pointer-events-none disabled:opacity-50"
                    aria-label="{{ __('access.pagination.next') }}"
                >
                    <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                    </svg>
                </button>
            @else
                <span
                    class="inline-flex size-11 shrink-0 cursor-not-allowed items-center justify-center rounded-lg border border-line-soft bg-card text-ink-3"
                    aria-hidden="true"
                >
                    <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                    </svg>
                </span>
            @endif
        </div>
    @endif
</nav>
