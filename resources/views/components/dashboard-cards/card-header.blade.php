@props([
    'title',
    'count' => null,
    'showIcon' => false,
])

<header {{ $attributes->class('flex min-h-16 shrink-0 items-center justify-start gap-4 px-6 pt-2 text-start') }}>
    <div class="flex min-w-0 items-center gap-2">
        <h2 class="truncate whitespace-nowrap text-base font-semibold leading-none text-secondary-dark">
            {{ $title }}
        </h2>

        @if($count !== null)
            {{-- bdi: the count may be a date (today's events), which must not
                 be reordered inside an Arabic heading. --}}
            <bdi class="text-base font-medium leading-none text-secondary-light">{{ $count }}</bdi>
        @endif

        @isset($icon)
            @if($showIcon)
                <span class="flex size-4 shrink-0 items-center justify-center text-primary">
                    {{ $icon }}
                </span>
            @endif
        @endisset
    </div>

    @isset($actions)
        <div class="ms-auto min-w-0">
            {{ $actions }}
        </div>
    @endisset
</header>
