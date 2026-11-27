{{-- The documents a new volunteer goes through: policies to agree to, and guidelines made by other teams to review (track 7). --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="onboarding-policies">
    <x-page-header :title="__('onboarding.policies.title')" :lead="__('onboarding.policies.lead')" />

    @php $anyRequired = collect($sections)->contains(fn ($section) => $section['required']->isNotEmpty()); @endphp

    @foreach ($sections as $kind => $status)
        @continue($status['required']->isEmpty())

        <section class="space-y-4" data-testid="section-{{ $kind }}">
            <h2 class="text-lg font-semibold text-ink">@lang('onboarding.policies.section-'.$kind)</h2>
            <p class="text-small text-ink-2">@lang('onboarding.policies.section-'.$kind.'-hint')</p>

            @foreach ($status['required'] as $document)
                @php $agreed = $status['acknowledged']->contains($document->id); @endphp
                <x-card wire:key="policy-{{ $document->id }}" data-testid="policy-{{ $document->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <h3 class="font-semibold text-ink">{{ $document->title }}</h3>
                        @if ($agreed)
                            <x-status tone="done" icon="check-circle" :label="__('onboarding.policies.done-'.$kind)" />
                        @else
                            <x-status tone="attn" icon="alert" :label="__('onboarding.policies.pending')" />
                        @endif
                    </div>

                    @if (! $agreed && $outdated->contains($document->id) && $document->source_modified_at)
                        <p class="mt-2 text-small text-ink-2" data-testid="new-version-{{ $document->id }}">
                            <x-tag variant="act">{{ __('onboarding.policies.new-version', ['date' => $document->source_modified_at->translatedFormat('j F Y')]) }}</x-tag>
                        </p>
                    @endif

                    @if (filled($document->content))
                        @php $text = $texts[$document->id]; @endphp
                        <div class="relative mt-3">
                            <article dir="{{ $document->direction() }}" lang="{{ $document->direction() === 'rtl' ? 'ar' : 'en' }}"
                                     @class(['policy-body rounded-control border border-line-soft bg-card p-4', 'max-h-80 overflow-hidden' => $text['long']])
                                     data-testid="policy-text-{{ $document->id }}" @if ($text['long']) data-collapsed @endif>{!! $text['html'] !!}</article>
                            @if ($text['long'])
                                <div class="pointer-events-none absolute inset-x-px bottom-px h-16 rounded-b-control bg-gradient-to-t from-card to-transparent" aria-hidden="true"></div>
                            @endif
                        </div>
                        @if ($text['long'])
                            <a href="{{ route('policies.show', $document->id) }}" wire:navigate class="mt-2 inline-block text-small font-medium text-ink underline"
                               data-testid="read-whole-{{ $document->id }}">@lang('onboarding.policies.read-whole')</a>
                        @endif
                    @endif

                    @unless ($agreed)
                        <div class="mt-3">
                            <x-button size="sm" icon="check" wire:click="acknowledge({{ $document->id }})" data-testid="acknowledge-{{ $document->id }}">@lang('onboarding.policies.acknowledge-'.$kind)</x-button>
                        </div>
                    @endunless
                </x-card>
            @endforeach
        </section>
    @endforeach

    @unless ($anyRequired)
        <x-card><p class="text-body text-ink-2">@lang('onboarding.policies.none')</p></x-card>
    @endunless
</div>
