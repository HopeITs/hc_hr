@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('pagination.navigation') }}" class="flex justify-between">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <span class="relative inline-flex items-center px-4 py-2 text-body font-medium text-secondary-medium bg-white border border-neutral-300 cursor-default leading-5 rounded-md">
                {!! __('pagination.previous') !!}
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="relative inline-flex items-center px-4 py-2 text-body font-medium text-neutral-800 bg-white border border-neutral-300 leading-5 rounded-md hover:text-secondary-medium focus:outline-none focus:ring ring-neutral-300 focus:border-ink active:bg-neutral-100 active:text-neutral-800 transition ease-in-out duration-150">
                {!! __('pagination.previous') !!}
            </a>
        @endif

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="relative inline-flex items-center px-4 py-2 text-body font-medium text-neutral-800 bg-white border border-neutral-300 leading-5 rounded-md hover:text-secondary-medium focus:outline-none focus:ring ring-neutral-300 focus:border-ink active:bg-neutral-100 active:text-neutral-800 transition ease-in-out duration-150">
                {!! __('pagination.next') !!}
            </a>
        @else
            <span class="relative inline-flex items-center px-4 py-2 text-body font-medium text-secondary-medium bg-white border border-neutral-300 cursor-default leading-5 rounded-md">
                {!! __('pagination.next') !!}
            </span>
        @endif
    </nav>
@endif
