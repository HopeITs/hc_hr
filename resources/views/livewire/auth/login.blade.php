<x-slot:title>
    {{ __('auth.sign-in') }}
</x-slot:title>

<section class="flex min-h-[86.7dvh] items-center justify-center bg-surface px-4 py-10 rtl:text-right">
    {{-- 20% wider than the other sign-in pages (the layout reads data-auth-wide); a phone still gets the full width. --}}
    <div class="w-full" data-auth-wide>

        {{-- 10px higher than its place above the card; the card itself does not move. --}}
        <x-app-logo class="mx-auto mb-8 w-[200px] -translate-y-[10px]" />

        {{-- Corners 10px rounder than the 12px card radius (22px), on this card only. --}}
        <x-auth-card class="w-full border border-line-soft p-6 sm:p-9 rounded-[22px]!">

            <header class="mb-7 text-center">
                <h1 class="text-[1.75rem] leading-tight font-extrabold text-balance text-ink">{{ __('auth.sign-in') }}</h1>
                <p class="mt-2 text-small text-ink-2">{{ __('auth.sign-in-text') }}</p>
            </header>

            <x-auth-session-status class="mb-5" :status="session('status')" />

            <form
                x-data
                x-on:submit.prevent="
                    $wire.set('email', document.getElementById('email').value, false);
                    $wire.set('password', document.getElementById('password').value, false);
                    $wire.login()
                "
                class="space-y-5"
            >

                <div>
                    <x-forms.input-label for="email" required :value="__('auth.email-address')" />
                    <x-forms.input
                        id="email"
                        type="email"
                        error-key="email"
                        required
                        wire:model.live.debounce.500ms="email"
                        placeholder="email@example.com"
                        autocomplete="email"
                        dir="ltr"
                    />

                    <x-forms.input-error field="email" input-id="email" />
                </div>

                <div>
                    <x-forms.input-label for="password" required :value="__('auth.password')" />
                    <x-forms.password-input
                        id="password"
                        model="password"
                        error-key="password"
                        required
                        placeholder="••••••••"
                        autocomplete="current-password"
                    />

                    <x-forms.input-error field="password" input-id="password" />
                </div>

                {{-- Wraps onto two lines on a narrow phone instead of running the two labels together. --}}
                <div class="flex flex-wrap items-center justify-between gap-x-4">
                    <x-forms.input
                        type="checkbox"
                        id="remember"
                        wire:model="remember"
                        class="hc-check--brand"
                        label-class="text-small font-medium text-ink-2"
                    >
                        {{ __('auth.remember-me') }}
                    </x-forms.input>

                    @if (Route::has('password.request'))
                        <a
                            href="{{ route('password.request') }}"
                            wire:navigate
                            class="inline-flex min-h-11 items-center text-small font-semibold text-ink underline decoration-line underline-offset-4 hover:decoration-ink"
                        >
                            {{ __('auth.forgot-password') }}
                        </a>
                    @endif
                </div>

                {{-- Corners 5px rounder than the 8px button radius (13px), on this button only. --}}
                <x-primary-button full class="justify-center rounded-[13px]!">
                    {{ __('auth.sign-in') }}
                </x-primary-button>

            </form>

        </x-auth-card>

    </div>
</section>
