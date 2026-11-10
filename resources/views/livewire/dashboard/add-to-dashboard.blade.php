{{--
    "Add to dashboard" (Dashboard\AddToDashboard), everywhere in the site (2026-11-09): a square
    with the dashboard icon that opens on hover or keyboard focus to say "Add to dashboard"
    (hc-btn--reveal, the same motion as the "+" buttons); on a page heading and on a profile
    section alike ($compact is kept for callers, the look is the same). Pressed = the card is on
    the dashboard: a check, "On dashboard", ink fill, never red; pressing it again takes it off.
    Adding flies a copy into the sidebar's dashboard icon (fly-to-dashboard.js). Not drawn at
    all for a card the person may not have.
--}}
<div class="contents">
    @if ($allowed)
        @php($label = $on ? __('dashboard.add.on') : __('dashboard.add.add'))
        <x-button variant="outline" size="sm" wire:click="toggle" :icon="$on ? 'check' : 'layout'"
                  aria-pressed="{{ $on ? 'true' : 'false' }}" data-testid="add-to-dashboard"
                  x-on:click="{{ $on ? '' : 'window.hcFlyToDashboard?.($el)' }}"
                  :class="'hc-btn--reveal shrink-0'.($on ? ' !border-ink !bg-ink !text-on-ink' : '')"><span class="hc-reveal-label">{{ $label }}</span></x-button>
    @endif
</div>
