@props([
    'title',
    'subtitle' => null,
    'icon' => null,
    'private' => false,
])

<section
    x-data="{ open: false }"
    {{ $attributes->only(['data-tour', 'data-section']) }}
    class="rounded-surface border {{ $private ? 'border-ink' : 'border-line-soft' }} bg-card p-4 shadow-e1 sm:rounded-surface sm:p-9"
>
    {{-- D1: an optional action (e.g. Edit) beside the heading, outside the phone toggle button. --}}
    <div class="flex items-start gap-2">
    <button
        type="button"
        class="flex w-full items-center gap-3 text-start sm:mb-4 sm:cursor-default"
        x-on:click="if (window.innerWidth < 640) open = !open"
        x-bind:aria-expanded="open"
    >
        @if($icon)
            <div class="rounded-surface bg-surface p-2 text-ink sm:rounded-surface sm:p-3">
                <x-dynamic-component :component="$icon" class="h-4 w-4 !me-0 sm:h-6 sm:w-6"/>
            </div>
        @endif

        <div class="min-w-0 flex-1">
            <h2 class="text-h4 font-extrabold text-ink">{{ $title }}</h2>
            @if($subtitle)
                <p class="mt-1 text-small text-ink-2 sm:mt-1 sm:text-body">{{ $subtitle }}</p>
            @endif
        </div>

        <svg
            class="h-4 w-4 shrink-0 text-ink-2 transition-transform duration-200 sm:hidden"
            x-bind:class="{ 'rotate-180': open }"
            viewBox="0 0 20 20"
            fill="none"
            aria-hidden="true"
        >
            <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    @isset($action)
        <div class="shrink-0">{{ $action }}</div>
    @endisset
    </div>

    <div x-cloak x-show="open" x-collapse class="mt-4 sm:hidden">
        {{ $slot }}
    </div>

    <div class="hidden sm:block">
        {{ $slot }}
    </div>
</section>
