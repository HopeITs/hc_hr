{{-- HR: which policy documents a new volunteer must read and agree to (track 7). --}}
<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="required-policies">
    <x-page-header :title="__('onboarding.required-policies.title')" :lead="__('onboarding.required-policies.lead')" />

    <x-card>
        <ul class="divide-y divide-line-soft">
            @forelse ($documents as $document)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="doc-{{ $document->id }}">
                    <span class="font-medium text-ink">{{ $document->title }}</span>
                    <label class="flex items-center gap-2 text-small text-ink-2">
                        <input type="checkbox" class="rounded border-line" @checked($document->required_for_onboarding)
                            wire:change="setRequired({{ $document->id }}, $event.target.checked)"
                            data-testid="required-{{ $document->id }}">
                        @lang('onboarding.required-policies.required')
                    </label>
                </li>
            @empty
                <li class="py-3 text-body text-ink-2">@lang('onboarding.required-policies.none')</li>
            @endforelse
        </ul>
    </x-card>
</div>
