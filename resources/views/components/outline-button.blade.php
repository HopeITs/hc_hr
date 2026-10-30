{{-- Legacy API, identity look: <x-button variant="outline"> (card fill, ink border). --}}
<button wire:loading.attr="disabled"
        wire:loading.delay.none.attr="aria-busy"
    {{$attributes->merge([
    'class' => 'hc-btn hc-btn--outline me-2 mb-2'
])}}>
    <span role="status" class="inline-flex" wire:loading>
        <span class="hc-spin" aria-hidden="true"></span>
        <span class="sr-only">{{ __("general.a11y.loading") }}</span>
    </span>

    {{$slot}}
</button>
