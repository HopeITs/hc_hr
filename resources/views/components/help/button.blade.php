{{--
    Header "Help" button (D3): opens the page-context help panel on the list,
    this page's topics first. No server call, so it is safe over any form.
    Hidden below `sm`: at 375px one more 44px button pushed the menu button off
    the screen (M1). Phones open help from the account menu and the "?" buttons.
--}}
<button type="button"
        x-data
        x-on:click="$dispatch('help-open')"
        data-help-button
        aria-haspopup="dialog"
        title="{{ __('help.panel.open') }}"
        aria-label="{{ __('help.panel.open') }}"
        {{ $attributes->merge(['class' => 'relative max-sm:hidden inline-flex items-center justify-center transition rounded-[14px] size-11 sm:size-9 text-secondary-light hover:bg-white hover:text-secondary-dark hover:shadow-e1 focus:outline-none focus:ring-2 focus:ring-neutral-200']) }}>
    <svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>
    </svg>
</button>
