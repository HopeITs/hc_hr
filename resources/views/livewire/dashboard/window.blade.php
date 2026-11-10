{{--
    One card in a window of its own (Dashboard\Window). The card fills the
    window and is the size container "tile", as in the grid, so it lays itself
    out for the window's size. A card that reads the home screen's state
    (editing, styleOf) finds the same names here.

    Fresh: every card in the window is asked again every minute while the
    window is in view. Signed out (the session ended): the card is covered by
    one line that says so, with the way to sign in again in the main window,
    rather than old figures or Livewire's "page expired" dialog.
--}}
<div class="hc-window" data-testid="dashboard-window"
     x-data="{ editing: false, styleOf: () => @js($look), signedOut: false }"
     x-on:hc-window-signed-out.window="signedOut = true">
    <div class="hc-window-card">
        @livewire($component, $params, key('win-'.$tile))
    </div>

    <div class="hc-window-out" x-show="signedOut" x-cloak role="alert">
        <p class="text-body font-bold text-ink">{{ __('dashboard.window.signed-out') }}</p>
        <a href="{{ route('login') }}" target="_blank" rel="noopener" class="hc-btn hc-btn--primary hc-btn--sm">{{ __('dashboard.window.sign-in') }}</a>
    </div>
</div>

@script
<script>
    const MINUTE = 60 * 1000;

    // Every card in the window, read again while the window is in view.
    const timer = setInterval(() => {
        if (document.hidden) return;
        Livewire.all().forEach((component) => {
            if (component.name !== 'dashboard.window') component.$wire.$refresh();
        });
    }, MINUTE);
    document.addEventListener('livewire:navigating', () => clearInterval(timer), { once: true });

    // The session ended (signed out, or it expired): say so instead of the expired-page dialog.
    Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            if (status === 401 || status === 419) {
                preventDefault();
                clearInterval(timer);
                window.dispatchEvent(new CustomEvent('hc-window-signed-out'));
            }
        });
    });
</script>
@endscript
