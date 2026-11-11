<x-slot:title>
    {{ __('general.news') }}
</x-slot:title>

<div>
    <div class="flex justify-between items-center mb-6">
        <livewire:layout.breadcrumbs :title="__('general.news')" :crumbs="[['label' => __('general.news-management')]]" :showButton="true" />
        <div class="flex flex-wrap items-center gap-2">
            {{-- The page's live card on the dashboard (Dashboard\AddToDashboard). --}}
            <livewire:dashboard.add-to-dashboard tile="news" />
            @if (auth()->user()?->activeRoleIs('HR', 'super-admin', 'general-manager', 'manager'))
                <x-primary-button :href="route('news.create')">@lang('general.add-new')</x-primary-button>
            @endif
        </div>
    </div>

    <livewire:news.news-table />
</div>
