{{-- The policies: a search over what they say, then the list. Open to every signed-in employee. --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="policies-index">
    <x-page-header :title="__('policies.title')" :lead="__('policies.lead')" />

    <div>
        <label for="policies-search" class="sr-only">@lang('policies.search-label')</label>
        <input id="policies-search" type="search" autocomplete="off" wire:model.live.debounce.300ms="q" maxlength="80"
               placeholder="{{ __('policies.search-placeholder') }}"
               class="h-11 w-full rounded-full border border-line bg-card px-5 text-body text-ink placeholder:text-ink-3" data-testid="policies-search">
    </div>

    @if (mb_strlen($term) >= 2)
        <section class="space-y-3" data-testid="policies-results" aria-live="polite">
            <h2 class="text-lg font-semibold text-ink">@lang('policies.results')</h2>
            @forelse ($hits as $hit)
                <x-card wire:key="hit-{{ $hit['id'] }}">
                    <a href="{{ route('policies.show', ['document' => $hit['id'], 'q' => $term, 'p' => $hit['position']]) }}" wire:navigate class="font-semibold text-ink hover:underline" data-testid="policy-hit">
                        <bdi>{{ $hit['title'] }}</bdi>
                    </a>
                    <p class="mt-1 text-small text-ink-2"><bdi>{!! \App\Support\Search\Highlight::html($hit['snippet'], $marks) !!}</bdi></p>
                </x-card>
            @empty
                <x-card><p class="text-body text-ink-2">{{ __('policies.no-results', ['term' => $term]) }}</p></x-card>
            @endforelse
        </section>
    @endif

    <section class="space-y-2" data-testid="policies-list">
        <h2 class="text-lg font-semibold text-ink">@lang('policies.all')</h2>
        @forelse ($documents as $document)
            <x-card wire:key="doc-{{ $document->id }}">
                <a href="{{ route('policies.show', $document->id) }}" wire:navigate class="font-semibold text-ink hover:underline"><bdi>{{ $document->title }}</bdi></a>
            </x-card>
        @empty
            <x-card><p class="text-body text-ink-2">@lang('policies.none')</p></x-card>
        @endforelse
    </section>
</div>
