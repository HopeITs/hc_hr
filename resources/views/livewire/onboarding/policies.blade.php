{{-- The policies a new volunteer reads and agrees to (track 7). --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="onboarding-policies">
    <x-page-header :title="__('onboarding.policies.title')" :lead="__('onboarding.policies.lead')" />

    @forelse ($status['required'] as $document)
        @php $agreed = $status['acknowledged']->contains($document->id); @endphp
        <x-card wire:key="policy-{{ $document->id }}" data-testid="policy-{{ $document->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h2 class="font-semibold text-ink">{{ $document->title }}</h2>
                @if ($agreed)
                    <x-status tone="done" icon="check-circle" :label="__('onboarding.policies.agreed')" />
                @else
                    <x-status tone="attn" icon="alert" :label="__('onboarding.policies.pending')" />
                @endif
            </div>

            @if ($document->source_url)
                <p class="mt-2"><x-button variant="link" size="sm" :href="$document->source_url" target="_blank" rel="noopener">@lang('onboarding.policies.open-source')</x-button></p>
            @endif

            @if ($document->content)
                <div class="mt-3 max-h-80 overflow-y-auto whitespace-pre-line rounded-control border border-line-soft bg-soft p-3 text-body text-ink">{{ $document->content }}</div>
            @endif

            @unless ($agreed)
                <div class="mt-3">
                    <x-button size="sm" icon="check" wire:click="acknowledge({{ $document->id }})" data-testid="acknowledge-{{ $document->id }}">@lang('onboarding.policies.acknowledge')</x-button>
                </div>
            @endunless
        </x-card>
    @empty
        <x-card><p class="text-body text-ink-2">@lang('onboarding.policies.none')</p></x-card>
    @endforelse
</div>
