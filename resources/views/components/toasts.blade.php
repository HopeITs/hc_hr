{{--
    The single toast region, mounted once in the app layout. Fed by
    $store.toasts (resources/js/feedback.js); see WithFeedback::toast().

    Also renders what the previous request flashed: toasts raised just before a
    redirect, and the conventional session('status') message.

    The live region is in the DOM before any toast arrives, which is what makes
    a screen reader announce the toast rather than ignore it. "polite" waits for
    the user to finish what they are doing, errors included: a failed save also
    leaves its inline field errors, which announce themselves.
--}}
@php
    $flashed = collect(session(\App\Livewire\Features\SupportToasts::SESSION_KEY, []));

    // Status codes are read by the page that set them (the email verification
    // notice turns this one into a sentence); only messages become toasts.
    $status = session('status');

    if (is_string($status) && $status !== '' && $status !== 'verification-link-sent') {
        $flashed->push(['type' => 'success', 'message' => $status, 'undo' => null]);
    }
@endphp

<div x-data
     x-init="@js($flashed->values()).forEach((toast) => $store.toasts.push(toast))"
     role="status"
     aria-live="polite"
     class="pointer-events-none fixed inset-x-4 bottom-4 z-[9500] flex flex-col gap-2 sm:inset-x-auto sm:end-6 sm:bottom-6 sm:w-96">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-on:mouseenter="$store.toasts.pause(toast.id)"
             x-on:mouseleave="$store.toasts.resume(toast.id)"
             x-on:focusin="$store.toasts.pause(toast.id)"
             x-on:focusout="$store.toasts.resume(toast.id)"
             x-transition:enter="transition duration-200 ease-fluid"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition duration-150 ease-in"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             data-testid="toast"
             :data-type="toast.type"
             class="pointer-events-auto flex items-start gap-3 rounded-surface border border-s-4 border-neutral-200 bg-white p-3 shadow-e3"
             :class="{
                 'border-s-neutral-800': toast.type === 'success',
                 'border-s-primary': toast.type === 'error',
                 'border-s-secondary-light': toast.type === 'info',
             }">
            {{-- The icon tells the variants apart without relying on colour alone. --}}
            <span aria-hidden="true"
                  class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-round text-white"
                  :class="{
                      'bg-neutral-800': toast.type === 'success',
                      'bg-primary': toast.type === 'error',
                      'bg-secondary-light': toast.type === 'info',
                  }">
                <svg x-show="toast.type === 'success'" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                <svg x-show="toast.type === 'error'" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 6v7M12 17.5v.5"/></svg>
                <svg x-show="toast.type === 'info'" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M12 11v7M12 6v.5"/></svg>
            </span>

            <p class="min-w-0 flex-1 py-0.5 text-body font-medium text-neutral-800">
                <span class="sr-only"
                      x-text="@js([
                          'success' => __('general.toast.success'),
                          'error' => __('general.toast.error'),
                          'info' => __('general.toast.info'),
                      ])[toast.type]"></span>
                <span x-text="toast.message"></span>
            </p>

            <template x-if="toast.undo">
                <button type="button"
                        x-on:click="$store.toasts.undo(toast.id)"
                        class="shrink-0 rounded-control px-2 py-0.5 text-body font-semibold text-primary transition hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    {{ __('general.toast.undo') }}
                </button>
            </template>

            {{-- A browser-side action, such as Try again after a lost connection (G-B-2). --}}
            <template x-if="toast.action">
                <button type="button"
                        x-on:click="$store.toasts.act(toast.id)"
                        x-text="toast.action.label"
                        class="shrink-0 rounded-control px-2 py-0.5 text-body font-semibold text-primary transition hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"></button>
            </template>

            <button type="button"
                    x-on:click="$store.toasts.dismiss(toast.id)"
                    aria-label="{{ __('general.toast.dismiss') }}"
                    class="-m-1 grid size-8 shrink-0 place-items-center rounded-control text-secondary-medium transition hover:bg-neutral-100 hover:text-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    </template>
</div>
