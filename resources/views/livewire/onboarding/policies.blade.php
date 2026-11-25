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


                    @if ($document->content)
                        <div class="mt-3 max-h-80 overflow-y-auto whitespace-pre-line rounded-control border border-line-soft bg-soft p-3 text-body text-ink">{{ $document->content }}</div>
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
