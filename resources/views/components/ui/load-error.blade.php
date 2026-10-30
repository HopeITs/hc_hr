{{--
    Recoverable load failure with a Retry button.

    retry: public Livewire method to call (default retryLoad, from HandlesLoadFailure)
    message: optional override; defaults to shared copy. Never pass exception text.
    Fixed min-height so swapping it in for a list/skeleton does not shift the layout.
--}}
@props(['retry' => 'retryLoad', 'message' => null])

<div {{ $attributes->class(['flex min-h-48 flex-col items-center justify-center gap-4 rounded-surface border-[1.5px] border-dashed border-line bg-card p-6 text-center']) }} role="alert">
    <div class="max-w-sm">
        <h2 class="text-h4 font-bold text-ink">{{ __('general.state-error-title') }}</h2>
        <p class="mt-2 text-body text-ink-2">{{ $message ?? __('general.state-error-help') }}</p>
    </div>
    <button
        type="button"
        wire:click="{{ $retry }}"
        wire:loading.attr="disabled"
        wire:target="{{ $retry }}"
        class="hc-btn hc-btn--primary"
    >
        <span wire:loading.remove wire:target="{{ $retry }}">{{ __('general.retry') }}</span>
        <span wire:loading wire:target="{{ $retry }}" role="status">{{ __('general.loading') }}…</span>
    </button>
</div>
