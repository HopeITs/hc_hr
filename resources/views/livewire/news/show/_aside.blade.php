{{-- Facts beside the article (below it on small screens). Labels are in the
     app locale; only the article follows the preview's language switch. --}}
@if ($article['isEvent'])
    <x-card :title="__('news.show.event')">
        <dl class="space-y-3">
            <div>
                <dt class="text-caption font-bold text-ink-3">{{ __('news.show.starts') }}</dt>
                <dd class="num mt-0.5 font-semibold text-ink">{{ $article['eventStart'] ?? __('general.na') }}</dd>
            </div>
            @if ($article['eventEnd'])
                <div>
                    <dt class="text-caption font-bold text-ink-3">{{ __('news.show.ends') }}</dt>
                    <dd class="num mt-0.5 font-semibold text-ink">{{ $article['eventEnd'] }}</dd>
                </div>
            @endif
            @if ($article['timing'])
                <div>
                    <dt class="sr-only">{{ __('news.show.timing') }}</dt>
                    <dd>
                        <x-tag :variant="$article['timing'] === 'today' ? 'act' : 'soft'"
                            :icon="$article['timing'] === 'today' ? 'calendar' : null"
                            :label="__('news.timing.'.$article['timing'])" />
                    </dd>
                </div>
            @endif
        </dl>
    </x-card>
@endif

<x-card :title="__('news.show.context')">
    <dl class="space-y-1">
        <dt class="text-caption font-bold text-ink-3">{{ __('news.show.related_to') }}</dt>
        <dd class="flex flex-wrap items-center gap-2 font-semibold text-ink">
            <x-ic :name="$article['relatedIcon']" class="text-ink-3" />
            <span class="min-w-0">{{ $news->relatedTo()->label() }}</span>
            @if ($news->relatedTo()->needsEntity() && $news->newsable)
                <span class="text-ink-3" aria-hidden="true">·</span>
                <bdi class="min-w-0">{{ $news->newsable->name ?? __('general.na') }}</bdi>
            @endif
        </dd>
    </dl>
    <p class="mt-3 text-small text-ink-3">{{ __('news.show.related_hint') }}</p>
</x-card>

@if ($canManage)
    <x-card :title="__('news.show.audience')">
        <p class="flex items-start gap-2 font-semibold text-ink">
            <x-ic :name="$news->audience_type->icon()" class="mt-1 text-ink-3" />
            <span class="min-w-0" dir="auto">{{ $audienceSummary }}</span>
        </p>
        <p class="mt-3 text-small text-ink-3">{{ __('news.show.audience_hint') }}</p>
    </x-card>

    <x-card :title="__('news.show.linkedin')">
        @if (! $externalAvailable)
            <p class="flex items-start gap-2 text-small text-ink-2">
                <x-ic name="info" class="mt-1 text-ink-3" />
                <span>{{ __('news.show.linkedin_unavailable') }}</span>
            </p>
        @else
            @php($linkedin = $externalPublications->firstWhere('platform', 'linkedin'))
            <p class="flex items-start gap-2 text-small text-ink-2">
                <x-ic name="send" class="mt-1 text-ink-3" />
                <span>
                    @if ($linkedin?->status === \App\Models\NewsExternalPublication::STATUS_PUBLISHED)
                        {{ __('news.show.linkedin_published') }}
                        @if ($linkedin->external_url)
                            <a href="{{ $linkedin->external_url }}" target="_blank" rel="noopener noreferrer"
                                class="font-semibold text-ink underline underline-offset-2">{{ __('news.show.linkedin_open') }}</a>
                        @endif
                    @elseif ($linkedin?->status === \App\Models\NewsExternalPublication::STATUS_FAILED)
                        {{ __('news.show.linkedin_failed') }}
                    @else
                        {{ __('news.show.linkedin_not_yet') }}
                    @endif
                </span>
            </p>
        @endif
    </x-card>
@endif
