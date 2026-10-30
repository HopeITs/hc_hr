@props(['section'])

{{--
    Not a confirm button: the section is already valid and already held in state
    by the time this appears. It is a shortcut for "collapse this and open the
    next", offered because it sits where the eye lands after the last field.
    Clicking any other section header does the same thing.
--}}
<div class="mt-5 flex justify-end border-t border-line-soft pt-4"
     x-cloak x-show="isValid('{{ $section }}') && !isLastSection('{{ $section }}')">
    <x-button variant="outline" :loading="false"
        x-on:click="nextSection()"
    >
        {{ __('general.composer.continue') }}
        <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 20 20" fill="none" stroke="currentColor"
             stroke-width="2" aria-hidden="true">
            <path d="M7.5 4.5l5.5 5.5-5.5 5.5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </x-button>
</div>
