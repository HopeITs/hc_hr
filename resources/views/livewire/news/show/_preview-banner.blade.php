{{-- Shown to a manager of the item on the preview route, or on the details
     page while the item is not released (draft, scheduled, archived). --}}
<section class="mb-6 rounded-surface border border-ink bg-card p-4 shadow-e1 sm:p-5"
    aria-label="{{ __('news.show.preview') }}">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-ink text-on-ink">
                <x-ic name="eye" />
            </span>
            <div class="min-w-0">
                <p class="font-extrabold text-ink">
                    {{ __('news.show.preview') }}
                    <span class="font-normal text-ink-2">— {{ __('news.show.preview_lead') }}</span>
                </p>
                <p class="mt-1.5 flex flex-wrap items-center gap-2 text-small text-ink-2">
                    <span>{{ __('news.show.status') }}</span>
                    <x-status :status="$state" />
                    @if ($scheduledFor)
                        <span>{{ __('news.show.scheduled_for', ['date' => $scheduledFor]) }}</span>
                    @endif
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-control border border-line bg-card p-0.5" role="group"
                aria-label="{{ __('news.show.language') }}">
                @foreach (['en' => 'EN', 'ar' => 'AR'] as $code => $short)
                    <button type="button" wire:click="$set('locale', '{{ $code }}')"
                        lang="{{ $code }}"
                        aria-pressed="{{ $locale === $code ? 'true' : 'false' }}"
                        title="{{ __('news.show.lang_'.$code) }}"
                        @class([
                            'min-h-9 min-w-11 rounded-[inherit] px-3 text-small font-bold',
                            'bg-ink text-on-ink' => $locale === $code,
                            'text-ink-2 hover:bg-hover hover:text-ink' => $locale !== $code,
                        ])>
                        <span aria-hidden="true">{{ $short }}</span>
                        <span class="sr-only">{{ __('news.show.lang_'.$code) }}</span>
                    </button>
                @endforeach
            </div>
            <x-button variant="primary" size="sm" icon="pen" :href="route('news.edit', $news)">
                {{ __('general.edit') }}
            </x-button>
            <x-button variant="outline" size="sm" :href="route('news.index')">
                {{ __('news.show.back_to_list') }}
            </x-button>
        </div>
    </div>
</section>
