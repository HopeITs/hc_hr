{{--
    The preview: the item as employees will read it (<x-news.article>, the
    details page's body), in either language. Opened by openPreview(), which
    validates first; nothing here writes.
--}}
<x-modal.dialog id="news-preview" :title="__('news.form.preview.title')" wire:model="previewing" max-width="5xl">
    @if ($preview)
        <div x-data="{ lang: @js(app()->getLocale() === 'ar' ? 'ar' : 'en') }" class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-control bg-soft p-3">
                <p class="flex items-center gap-2 text-small text-ink-2">
                    <x-ic name="eye" class="shrink-0" />
                    <span>@lang('news.form.preview.note')</span>
                </p>
                <div class="inline-flex gap-1 rounded-control border border-line bg-card p-1" role="group"
                     aria-label="{{ __('news.form.preview.language') }}">
                    @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $name)
                        <button type="button" lang="{{ $code }}"
                                class="min-h-9 rounded-inset px-3 text-small font-bold text-ink-2 transition hover:text-ink"
                                x-on:click="lang = '{{ $code }}'"
                                x-bind:aria-pressed="lang === '{{ $code }}'"
                                x-bind:class="lang === '{{ $code }}' && 'bg-ink !text-on-ink'">{{ $name }}</button>
                    @endforeach
                </div>
            </div>

            @foreach ($preview as $code => $props)
                <div x-show="lang === '{{ $code }}'" @if ($code !== (app()->getLocale() === 'ar' ? 'ar' : 'en')) x-cloak @endif
                     wire:key="news-preview-{{ $code }}">
                    <x-news.article
                        :locale="$props['locale']"
                        :title="$props['title']"
                        :description="$props['description']"
                        :dir="$props['dir']"
                        :cover-url="$props['coverUrl']"
                        :type-label="$props['typeLabel']"
                        :type-icon="$props['typeIcon']"
                        :state="$props['state']"
                        :state-label="$props['stateLabel']"
                        :published-label="$props['publishedLabel']"
                        :event-label="$props['eventLabel']"
                        :tags="$props['tags']"
                        :audience-label="$props['audienceLabel']"
                        :audience-icon="$props['audienceIcon']"
                        :author-name="$props['authorName']" />
                </div>
            @endforeach

            <div class="flex justify-end border-t border-line-soft pt-4">
                <x-button variant="outline" icon="pen" wire:click="closePreview">@lang('news.form.preview.back')</x-button>
            </div>
        </div>
    @endif
</x-modal.dialog>
