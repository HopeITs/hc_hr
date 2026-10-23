{{--
    Recoverable load failure with a Retry button.

    retry: public Livewire method to call (default retryLoad, from HandlesLoadFailure)
    message: optional override; defaults to shared copy. Never pass exception text.
    Fixed min-height so swapping it in for a list/skeleton does not shift the layout.
--}}
@props(['retry' => 'retryLoad', 'message' => null])

<div {{ $attributes->class(['flex min-h-48 flex-col items-center justify-center gap-4 rounded-surface border border-dashed border-red-300 bg-white p-6 text-center dark:border-red-800 dark:bg-neutral-900']) }} role="alert">
    <div class="max-w-sm">
        <h2 class="text-h4 font-bold text-secondary-dark dark:text-white">{{ __('general.state-error-title') }}</h2>
        <p class="mt-2 text-body leading-relaxed text-secondary-light">{{ $message ?? __('general.state-error-help') }}</p>
    </div>
    <button
        type="button"
        wire:click="{{ $retry }}"
        wire:loading.attr="disabled"
        wire:target="{{ $retry }}"
        class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-lg bg-primary px-5 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-60"
    >
        <span wire:loading.remove wire:target="{{ $retry }}">{{ __('general.retry') }}</span>
        <span wire:loading wire:target="{{ $retry }}" role="status">{{ __('general.loading') }}…</span>
    </button>
</div>
