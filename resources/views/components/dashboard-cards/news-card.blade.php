{{-- newsItems: the past week's news, loaded by Dashboard\NewsCard. --}}
@props(['newsItems' => collect()])

<div x-data="{ open: false, selected: null }" class="relative flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    <x-dashboard-cards.card-header :title="__('general.news')" :count="$newsItems->count()">
        <x-slot:icon>
            <img class="block size-full object-contain" src="{{ asset('assets/icons/news.svg') }}" alt="">
        </x-slot:icon>
    </x-dashboard-cards.card-header>

    <div class="flex-1 min-h-0 px-6 py-4 space-y-5 overflow-y-auto custom-scrollbar">
        @forelse($newsItems as $news)
            @php
                $targetType = $news->newsable_type ? strtolower(class_basename($news->newsable_type)) : 'all';
                $authorName = $news->creator?->profile?->name ?? $news->creator?->email ?? __('general.unknown');
                $title = $news->localizedTitle();
                $description = $news->localizedDescription();
            @endphp
            <div class="pb-3 border-b border-b-line-soft last:border-b-0 last:pb-0">
                <p class="text-caption font-light text-ink-3">
                    {{ $news->published_at?->diffForHumans() ?? __('dashboard.just-now') }}
                </p>
                <p class="text-sm font-bold text-ink">
                    {{ $title }}
                </p>
                @if(filled($description))
                    <p class="mt-1 text-xs font-light text-ink-2 line-clamp-2">
                        {{ $description }}
                    </p>
                @endif
                <button type="button"
                    x-on:click="selected = {
                        title: @js($title),
                        description: @js($description),
                        image: @js($news->getFirstMediaUrl('news-image') ?: null),
                        publishedAt: @js($news->published_at?->translatedFormat('d M Y') ?? __('general.unknown')),
                        author: @js($authorName),
                        eventDate: @js($news->event_date?->translatedFormat('d M Y')),
                        target: @js($news->newsable_name),
                        targetType: @js($targetType),
                    }; open = true"
                    class="flex mt-2 text-xs font-semibold underline ms-auto text-ink hover:text-ink-2">
                    @lang('general.read-more') &rarr;
                </button>
            </div>
        @empty
            <div data-tile-empty class="flex flex-col items-center justify-center h-full text-sm font-light text-ink-3">
                <span class="text-small">@lang('general.no-available-data')</span>
            </div>
        @endforelse
    </div>

    <template x-teleport="body">
        <div x-cloak
            x-show="open"
            x-transition.opacity.duration.200ms
            class="fixed inset-0 z-50 flex items-center justify-center p-4 font-sans bg-accent/55 backdrop-blur-sm sm:p-6"
            role="dialog"
            aria-modal="true"
            x-on:click.self="open = false">
            <div x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-y-4 scale-95 opacity-0"
                x-transition:enter-end="translate-y-0 scale-100 opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-y-0 scale-100 opacity-100"
                x-transition:leave-end="translate-y-4 scale-95 opacity-0"
                class="relative max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-surface border border-line-soft bg-card shadow-e3">
                <button type="button"
                    x-on:click="open = false"
                    class="absolute z-10 flex items-center justify-center transition border rounded-full shadow-sm end-4 top-4 size-9 border-line-soft bg-card/95 text-ink-2 hover:border-line hover:text-primary"
                    aria-label="{{ __('general.cancel') }}">
                    <x-icons.x-mark class="size-4 shrink-0" />
                </button>

                <div class="custom-scrollbar max-h-[90vh] overflow-y-auto">
                    <template x-if="selected?.image">
                        <div class="aspect-[16/7] w-full overflow-hidden bg-surface">
                            <img :src="selected.image" :alt="selected.title || ''" class="object-cover w-full h-full">
                        </div>
                    </template>

                    <div class="p-6 space-y-4 sm:p-8">
                        <p class="text-xs font-semibold tracking-wide uppercase text-ink-2">@lang('general.news')</p>
                        <h3 class="text-2xl font-extrabold leading-normal text-ink sm:text-3xl" x-text="selected?.title"></h3>
                        <div class="flex flex-wrap items-center mt-2 mb-4 text-xs leading-4 gap-x-4 gap-y-2 text-ink-2">
                            <span class="group flex items-center gap-1.5 transition-colors hover:text-ink">
                                <x-icons.clock class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <span class="flex items-center gap-1">
                                    <span class="font-semibold transition-colors text-ink group-hover:text-ink">@lang('general.published_at'):</span>
                                    <span x-text="selected?.publishedAt"></span>
                                </span>
                            </span>

                            <span class="group flex items-center gap-1.5 transition-colors hover:text-ink">
                                <x-icons.employee class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <span class="flex items-center gap-1">
                                    <span class="font-semibold transition-colors text-ink group-hover:text-ink">@lang('general.created_by'):</span>
                                    <span x-text="selected?.author"></span>
                                </span>
                            </span>

                            <template x-if="selected?.eventDate">
                                <span class="group flex items-center gap-1.5 transition-colors hover:text-ink">
                                    <x-icons.calendar class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                    <span class="flex items-center gap-1">
                                        <span class="font-semibold transition-colors text-ink group-hover:text-ink">@lang('general.event_date'):</span>
                                        <span x-text="selected.eventDate"></span>
                                    </span>
                                </span>
                            </template>

                            <span class="group flex items-center gap-1.5 transition-colors hover:text-ink">
                                <x-icons.team x-show="selected?.targetType === 'all'" class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <x-icons.employee x-show="selected?.targetType === 'employee'" class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <x-icons.center x-show="selected?.targetType === 'center'" class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <x-icons.program x-show="selected?.targetType === 'program'" class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <x-icons.city x-show="selected?.targetType === 'city'" class="size-3.5 shrink-0 text-ink-3 transition-colors group-hover:text-ink" />
                                <span class="flex items-center gap-1">
                                    <span class="font-semibold transition-colors text-ink group-hover:text-ink">@lang('general.target_group'):</span>
                                    <span x-text="selected?.target || @js(__('general.all_org'))"></span>
                                </span>
                            </span>
                        </div>
                        <div class="h-px bg-surface"></div>
                        <p class="text-sm font-normal leading-7 whitespace-pre-line text-ink-2 sm:text-base sm:leading-8"
                            x-text="selected?.description || ''"></p>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<style>
    .custom-scrollbar::-webkit-scrollbar {
        width: 4px;
    }
    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: rgba(88, 89, 91, 0.22) transparent;
        scrollbar-gutter: stable;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: transparent;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: rgba(88, 89, 91, 0.22);
        border-radius: 999px;
    }
</style>
