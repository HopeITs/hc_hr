{{-- The policies: a search over what they say (grouped by policy), then the list. Open to every signed-in
     employee; HR also sees where policies are managed and which are still being read. --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="policies-index">
    <x-page-header :title="__('policies.title')" :lead="__('policies.lead')">
        @if ($canManage)
            <x-slot:actions>
                <x-button :href="$folderUrl" variant="primary" size="sm" data-testid="policies-manage">
                    <x-ic name="plus" /> @lang('policies.manage')
                </x-button>
                <x-button :href="route('onboarding.required-policies')" variant="outline" size="sm" data-testid="policies-settings">
                    @lang('policies.settings')
                </x-button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($canManage && $unfinished->isNotEmpty())
        <x-card data-testid="policies-unfinished">
            <h2 class="font-semibold text-ink">@lang('policies.unfinished')</h2>
            <ul class="mt-2 space-y-1 text-small">
                @foreach ($unfinished as $pending)
                    <li class="flex flex-wrap items-center gap-2" wire:key="pending-{{ $pending->id }}">
                        <bdi class="text-ink">{{ $pending->title }}</bdi>
                        @if ($pending->read_status === \App\Models\PolicyDocument::READING)
                            <x-status tone="work" icon="clock" :label="__('policies.status.reading')" />
                        @else
                            <x-status tone="stop" icon="warning" :label="__('policies.status.failed')" />
                            <span class="text-ink-2">{{ __('policies.error.'.($pending->read_error ?? 'unreadable')) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

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
                    <p class="mt-1 text-small text-ink-2" dir="auto">{!! \App\Support\Search\Highlight::html($hit['snippet'], $marks) !!}</p>
                    @if ($hit['more'] !== [])
                        <ul class="mt-2 space-y-1 border-s-2 border-line-soft ps-3" data-testid="policy-hit-more">
                            @foreach ($hit['more'] as $more)
                                <li>
                                    <a href="{{ route('policies.show', ['document' => $hit['id'], 'q' => $term, 'p' => $more['position']]) }}" wire:navigate class="text-small text-ink-2 hover:text-ink hover:underline" dir="auto">
                                        {!! \App\Support\Search\Highlight::html($more['snippet'], $marks) !!}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
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
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-ic name="file" class="shrink-0 text-ink-3" />
                        <div class="min-w-0">
                            <a href="{{ route('policies.show', $document->id) }}" wire:navigate class="font-semibold text-ink hover:underline"><bdi>{{ $document->title }}</bdi></a>
                            <p class="text-caption text-ink-3">
                                {{ $document->media ? strtoupper((string) $document->media->extension) : __('policies.text-only') }}
                                @if ($document->source_modified_at) · {{ __('policies.updated', ['date' => $document->source_modified_at->translatedFormat('j F Y')]) }} @endif
                            </p>
                        </div>
                    </div>
                    @if (in_array($document->id, $outdated, true))
                        <x-tag variant="act">@lang('policies.new-version')</x-tag>
                    @endif
                </div>
            </x-card>
        @empty
            <x-card><p class="text-body text-ink-2">@lang('policies.none')</p></x-card>
        @endforelse
    </section>
</div>
