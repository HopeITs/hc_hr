<x-slot:title>
    {{ __('general.news') }}
</x-slot:title>

<div>
    <div class="flex justify-between items-center mb-6">
        <livewire:layout.breadcrumbs :title="__('general.news')" :crumbs="[['label' => __('general.news-management')]]" :showButton="true" />
        @if (auth()->user()?->activeRoleIs('HR', 'super-admin', 'general-manager', 'manager'))
            <x-primary-button :href="route('news.create')">@lang('general.add-new')</x-primary-button>
        @endif
    </div>

    <livewire:news.news-table />
</div>
