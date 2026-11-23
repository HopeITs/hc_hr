{{--
    The one pager for every paginated list: plain ->links() in Livewire components
    and the livewire-tables data tables (which @include this with scrollTo => false).
    Separate square buttons on the identity tokens, so it flips with dark mode and RTL;
    the current page is the one solid ink square. Phones get previous / "page x of y" / next.
--}}
@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
        JS
        : '';

    $pageName = $paginator->getPageName();
    $duskSuffix = $pageName == 'page' ? '' : '.'.$pageName;

    $cell = 'inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-lg border px-3 text-[15px] tabular-nums transition';
    $idle = $cell.' border-line-soft bg-card text-ink hover:border-line hover:bg-hover disabled:pointer-events-none disabled:opacity-50';
    $off = $cell.' cursor-not-allowed border-line-soft bg-card text-ink-3 opacity-60';
    $current = $cell.' border-ink bg-ink font-bold text-on-ink';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('pagination.navigation') }}"
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">

            <p class="text-small text-ink-2">
                {!! __('pagination.showing', [
                    'first' => '<span class="font-bold tabular-nums text-ink">'.e($paginator->firstItem() ?? $paginator->count()).'</span>',
                    'last' => '<span class="font-bold tabular-nums text-ink">'.e($paginator->lastItem() ?? $paginator->count()).'</span>',
                    'total' => '<span class="font-bold tabular-nums text-ink">'.e($paginator->total()).'</span>',
                ]) !!}
            </p>

            <div class="flex w-full items-center justify-between gap-1.5 sm:w-auto sm:justify-end">
                {{-- Previous --}}
                @if ($paginator->onFirstPage())
                    <span class="{{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                        <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                        </svg>
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $pageName }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        dusk="previousPage{{ $duskSuffix }}.after" rel="prev"
                        class="{{ $idle }}" aria-label="{{ __('pagination.previous') }}">
                        <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" />
                        </svg>
                    </button>
                @endif

                {{-- Phones: where you are instead of the page numbers --}}
                <span class="text-small font-semibold tabular-nums text-ink-2 sm:hidden">
                    {{ __('pagination.page_of', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}
                </span>

                {{-- Page numbers --}}
                <div class="hidden items-center gap-1.5 sm:flex">
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="{{ $cell }} border-transparent text-ink-3 select-none" aria-hidden="true">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span wire:key="paginator-{{ $pageName }}-page{{ $page }}"
                                        class="{{ $current }}" aria-current="page">{{ $page }}</span>
                                @else
                                    <button type="button" wire:key="paginator-{{ $pageName }}-page{{ $page }}"
                                        wire:click="gotoPage({{ $page }}, '{{ $pageName }}')"
                                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                                        class="{{ $idle }}"
                                        aria-label="{{ __('pagination.go_to_page', ['page' => $page]) }}">
                                        {{ $page }}
                                    </button>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                </div>

                {{-- Next --}}
                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $pageName }}')"
                        x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                        dusk="nextPage{{ $duskSuffix }}.after" rel="next"
                        class="{{ $idle }}" aria-label="{{ __('pagination.next') }}">
                        <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                        </svg>
                    </button>
                @else
                    <span class="{{ $off }}" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                        <svg class="size-4 rtl:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                        </svg>
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>
