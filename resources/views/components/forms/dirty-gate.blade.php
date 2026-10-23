@props([
    // Livewire property path whose state decides whether there is anything to
    // save, e.g. "educationForm".
    'watch',
    // Create mode keeps plain, always-enabled buttons: on a blank form there
    // is nothing to compare against, and a disabled submit with no
    // explanation reads as broken. The slot still gets a `dirty` to bind to.
    'active' => true,
])

@unless ($active)
    <div {{ $attributes->class(['m1-action-bar']) }} x-data="{ dirty: true }">
        {{ $slot }}
    </div>
@else
    {{--
        Exposes a reactive `dirty` to everything in the slot, so a save button
        can bind `x-bind:disabled="! dirty"`.

        The baseline is a snapshot taken when the tab renders, NOT Livewire's
        own `wire:dirty` (which compares against the last server sync). The
        difference matters for file inputs: uploading a file is itself a round
        trip, so wire:dirty goes clean again the moment the upload lands, and
        the save button would lock right after picking a file.
    --}}
    <div {{ $attributes->class(['m1-action-bar']) }}
        x-data="{
            watching: @js($watch),
            baseline: null,
            init() {
                this.baseline = this.snapshot();
            },
            snapshot(reactive = false) {
                return JSON.stringify(this.$wire.$get(this.watching, reactive) ?? null);
            },
            get dirty() {
                return this.snapshot(true) !== this.baseline;
            },
        }"
        x-on:form-saved.window="
            if ($event.detail?.form === watching) {
                $nextTick(() => baseline = snapshot());
            }
        ">
        {{ $slot }}
    </div>
@endunless
