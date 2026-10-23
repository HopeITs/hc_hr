{{--
    The single confirmation dialog, mounted once in the app layout. Fed by
    $store.confirm (resources/js/feedback.js):

      - from a Livewire action: $this->confirm('delete', ['id' => $id])
        (WithFeedback), which calls delete() back once the user agrees;
      - from markup: x-on:click="if (await $confirm()) $wire.deleteRole()".

    With no options it asks the standard delete question. Focus starts on
    Cancel, so a stray Enter never confirms a destructive action, and returns
    to the triggering control when the dialog closes (x-trap).
--}}
{{--
    One x-init on purpose: an element keeps only the first of two identically
    named attributes, so a second x-init (the focus fallback) was silently dropped.
--}}
<div x-data
     x-init="
         $store.confirm.defaults = @js([
             'title' => __('general.delete-item'),
             'message' => __('general.confirm-delete-item'),
             'confirmLabel' => __('general.delete'),
             'cancelLabel' => __('general.cancel'),
             'danger' => true,
         ]);
         {{-- Leaving a page with unsaved changes (resources/js/unsaved.js): A5-M's wording. --}}
         window.hcUnsaved && (window.hcUnsaved.labels = @js([
             'title' => __('general.unsaved.title'),
             'message' => __('general.unsaved.message'),
             'discard' => __('general.unsaved.discard'),
             'keepEditing' => __('general.unsaved.keep-editing'),
         ]));
         {{-- x-trap places focus 15ms after opening; if the panel was not painted yet, focus would stay behind. --}}
         $watch('$store.confirm.open', open => open && [60, 200, 500].forEach(ms => setTimeout(() => {
             if ($store.confirm.open && ! $refs.panel.contains(document.activeElement)) $refs.cancel?.focus();
         }, ms)))
     "
     x-show="$store.confirm.open"
     x-on:keydown.escape.window="$store.confirm.open && $store.confirm.settle(false)"
     {{-- Opened over a form modal, this root was hidden by that modal's x-trap.inert; un-hide it or a screen reader never hears the question. --}}
     x-effect="$store.confirm.open && $el.removeAttribute('aria-hidden')"
     class="fixed inset-0 z-[9000] flex items-center justify-center p-4"
     style="display: none;">
    <div x-show="$store.confirm.open"
         x-transition.opacity.duration.200ms
         x-on:click="$store.confirm.settle(false)"
         aria-hidden="true"
         class="absolute inset-0 bg-neutral-900/60"></div>

    <div x-show="$store.confirm.open"
         x-ref="panel"
         x-trap.inert.noscroll="$store.confirm.open"
         x-transition:enter="transition duration-200 ease-fluid"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition duration-150 ease-in"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         role="alertdialog"
         aria-modal="true"
         aria-labelledby="confirm-dialog-title"
         aria-describedby="confirm-dialog-message"
         data-testid="confirm-dialog"
         class="relative w-full max-w-md rounded-surface bg-white p-6 shadow-e3">
        <div class="flex items-start gap-4">
            {{-- Grey, never tinted red: a low-opacity brand red reads as pink (app.css). --}}
            <span aria-hidden="true"
                  class="grid size-10 shrink-0 place-items-center rounded-round bg-neutral-100"
                  :class="$store.confirm.danger ? 'text-primary' : 'text-neutral-700'">
                <svg x-show="$store.confirm.danger" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>
                <svg x-show="! $store.confirm.danger" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17v.5"/></svg>
            </span>

            <div class="min-w-0 flex-1">
                <h2 id="confirm-dialog-title"
                    class="text-h4 font-semibold text-neutral-900"
                    x-text="$store.confirm.title"></h2>

                <div id="confirm-dialog-message" class="mt-2 text-body text-secondary-medium">
                    <p x-show="! $store.confirm.html" x-text="$store.confirm.message"></p>
                    {{-- Server-rendered Blade only; see WithFeedback::confirm(). --}}
                    <div x-show="$store.confirm.html" x-html="$store.confirm.html"></div>
                </div>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-end gap-3">
            {{-- The label comes from the store: Blade does not compile @js() inside a
                 component tag's attribute, which once left this reading "Cancel". --}}
            <x-secondary-button x-ref="cancel" x-on:click="$store.confirm.settle(false)" class="!w-auto"
                                x-text="$store.confirm.cancelLabel">
                {{ __('general.cancel') }}
            </x-secondary-button>

            <x-primary-button type="button"
                              :showSpinner="false"
                              x-on:click="$store.confirm.settle(true)"
                              x-text="$store.confirm.confirmLabel"
                              x-bind:class="$store.confirm.danger ? '' : '!bg-neutral-800 hover:!bg-neutral-900'"
                              data-testid="confirm-dialog-accept"
                              class="!w-auto"></x-primary-button>
        </div>
    </div>
</div>
