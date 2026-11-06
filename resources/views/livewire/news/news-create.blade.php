<x-slot:title>
    {{ $news ? __('news.form.page_edit') : __('news.form.page_create') }}
</x-slot:title>

@php
    $scheduledMode = $form->isScheduled();
    $cancelUrl = $returnTo ?? route('news.index');
@endphp

<div class="mx-auto w-full max-w-5xl">
    <div class="mb-4">
        <livewire:layout.breadcrumbs :title="__('news.title')" :crumbs="[['label' => __('general.news-management')], ['label' => $news ? __('general.edit') : __('general.add-new')]]" />
    </div>

    <x-page-header :title="$news ? __('news.form.page_edit') : __('news.form.page_create')" :lead="__('news.form.lead')">
        @if ($currentState)
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-2 text-small text-ink-2">
                    <span class="sr-only">@lang('news.form.current_state'):</span>
                    <x-status :status="$currentState" />
                    @if ($currentState === \App\Enums\News\NewsState::Scheduled && $news->published_at)
                        <span class="num">{{ __('news.form.scheduled_for', ['date' => $news->published_at->translatedFormat('j F Y, H:i')]) }}</span>
                    @endif
                </div>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form wire:submit.prevent="publish" class="space-y-6" novalidate>
        @include('livewire.news._form-fields')
        @include('livewire.news.form.context')
        @include('livewire.news.form.publishing')

        {{-- Sticky action bar. --}}
        <div class="sticky bottom-0 z-20 -mx-4 border-t border-line-soft bg-card/95 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur-sm sm:mx-0 sm:rounded-surface sm:border sm:shadow-e1">
            <div class="flex flex-wrap items-center gap-2 max-sm:[&>*]:flex-1 max-sm:[&>*]:justify-center">
                <x-button variant="quiet" :loading="false"
                          x-on:click="Livewire.navigate(@js($cancelUrl))">@lang('news.form.actions.cancel')</x-button>
                <span class="hidden flex-1 sm:block" aria-hidden="true"></span>
                <x-button variant="outline" icon="eye" wire:click="openPreview">@lang('news.form.actions.preview')</x-button>
                <x-button variant="outline" icon="pen" wire:click="saveDraft">@lang('news.form.actions.save_draft')</x-button>
                <x-button type="submit" :icon="$scheduledMode ? 'clock' : 'send'" target="publish">
                    {{ $scheduledMode ? __('news.form.actions.schedule') : __('news.form.actions.publish') }}
                </x-button>
            </div>
        </div>
    </form>

    @include('livewire.news.form.preview')
</div>
