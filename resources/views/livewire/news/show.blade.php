<x-slot:title>
    {{ $article['title'] }} · {{ __('news.title') }}
</x-slot:title>

{{-- News & Events details page (news.show) and its manager preview (news.preview). --}}
<div class="mx-auto w-full max-w-6xl">
    <a href="{{ $backUrl }}" wire:navigate
        class="mb-4 inline-flex min-h-11 items-center gap-1.5 font-semibold text-ink-2 hover:text-ink">
        <x-ic name="chevron" class="ltr:-scale-x-100" />
        <span>{{ $backLabel }}</span>
    </a>

    @if ($showPreview)
        @include('livewire.news.show._preview-banner')
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8">
        <div class="min-w-0 lg:col-span-2">
            @include('livewire.news.show._article')
        </div>

        <aside class="min-w-0 space-y-4" aria-label="{{ __('news.show.details') }}">
            @include('livewire.news.show._aside')
        </aside>
    </div>

    @if ($related->isNotEmpty())
        @include('livewire.news.show._related')
    @endif
</div>
