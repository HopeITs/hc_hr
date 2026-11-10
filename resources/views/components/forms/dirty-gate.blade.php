@props([
    // Livewire property path whose state decides whether there is anything to
    // save, e.g. "educationForm".
    'watch',
    // Create mode keeps plain, always-enabled buttons: on a blank form there
    // is nothing to compare against, and a disabled submit with no
    // explanation reads as broken. The slot still gets a `dirty` to bind to,
    // and leaving the page with typed entries still asks first (B2).
    'active' => true,
])

{{--
    Exposes a reactive `dirty` to everything in the slot, so a save button
    can bind `x-bind:disabled="! dirty"`.

    The baseline is a snapshot of the form when it was first shown, NOT
    Livewire's own `wire:dirty` (which compares against the last server sync).
    The difference matters for file inputs: uploading a file is itself a round
    trip, so wire:dirty goes clean again the moment the upload lands, and the
    save button would lock right after picking a file.

    The baseline lives in the unsaved-changes registry (resources/js/unsaved.js),
    per component and form, so it survives this tab being re-rendered: coming
    back to a tab with unsaved entries keeps them counted as unsaved. Only the
    component's `form-saved` event (after a save that succeeded) moves it.
--}}
<div {{ $attributes->class(['m1-action-bar']) }}
    x-data="{
        gated: @js((bool) $active),
        entry: null,
        tick: 0,
        init() {
            this.entry = $unsaved.watchForm($wire.$id, @js($watch));
        },
        get dirty() {
            if (! this.gated) return true;
            this.tick;
            {{-- Reactive read: re-evaluates whenever the watched form changes. --}}
            $wire.$get(@js($watch), true);
            return this.entry.dirty();
        },
    }"
    x-on:form-saved.window="
        if ($event.detail?.form === @js($watch)) setTimeout(() => tick++, 1);
    ">
    {{ $slot }}
</div>
