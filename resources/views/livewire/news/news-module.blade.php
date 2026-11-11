<x-slot:title>
    {{ __('news.title') }}
</x-slot:title>

<div>
    <x-page-header :title="__('news.title')" :lead="__('news.list.lead')">
        <x-slot:actions>
            {{-- The page's live card on the dashboard (Dashboard\AddToDashboard). --}}
            <livewire:dashboard.add-to-dashboard tile="news" />
            @if ($canCreate)
                <x-button :href="route('news.create')" icon="plus">{{ __('news.list.new') }}</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($externalPublishingPending)
        <p class="mb-4 flex items-start gap-2 text-small text-ink-3">
            <x-ic name="info" class="mt-0.5 size-4 shrink-0" />
            <span>{{ __('news.list.linkedin_note') }}</span>
        </p>
    @endif

    <livewire:news.news-table />
</div>
