@props([
    'reviewed' => false,
    'modelId',
])

@if ($reviewed)
    {{-- Reviewed: a checked, read-only checkbox --}}
    <span
        role="img"
        aria-label="{{ __('general.reviewed') }}"
        title="{{ __('general.reviewed') }}"
        class="inline-flex size-5 items-center justify-center rounded-inset bg-ink text-on-ink"
    >
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 13l4 4L19 7" />
        </svg>
    </span>
@else
    {{-- Not reviewed: an empty checkbox; ticking it confirms the HR review --}}
    <button
        type="button"
        role="checkbox"
        aria-checked="false"
        wire:click="confirmHrReview({{ $modelId }})"
        aria-label="{{ __('general.mark-reviewed') }}"
        title="{{ __('general.mark-reviewed') }}"
        class="inline-flex size-5 items-center justify-center rounded-inset border-2 border-line bg-card text-transparent transition hover:border-ink-2 hover:text-line"
    >
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M5 13l4 4L19 7" />
        </svg>
    </button>
@endif
