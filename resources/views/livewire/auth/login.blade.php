<x-slot:title>
    {{ __('auth.sign-in') }}
</x-slot:title>

<section class="min-h-[86.7dvh]  rtl:text-right bg-neutral-100 flex items-center justify-center px-4">
    <x-auth-card-wrapper>

        <div class="mb-6 text-center">
            <x-app-logo class="mx-auto w-[207px]" />
        </div>

        <x-auth-card class="space-y-6 w-96">

            <div class="space-y-1 text-center">
                <h1 class="text-2xl font-bold text-neutral-900">{{ __('auth.sign-in') }}</h1>
                <p class="text-sm text-neutral-500">{{ __('auth.sign-in-text') }}</p>
            </div>

            <x-auth-session-status class="mb-2 text-center text-neutral-600 text-body" :status="session('status')" />

            <form
                x-data
                x-on:submit.prevent="
                    $wire.set('email', document.getElementById('email').value, false);
                    $wire.set('password', document.getElementById('password').value, false);
                    $wire.login()
                "
                class="space-y-4"
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

                <div class="flex items-center justify-between text-body text-secondary-medium">
                    <x-forms.checkbox-input type="checkbox" id="remember" wire:model="remember">
                    {{ __('auth.remember-me') }}
                    </x-forms.checkbox-input>

                    @if (request()->has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-primary hover:underline">
                            {{ __('auth.forgot-password') }}
                        </a>
                    @endif
                </div>
                <x-primary-button class="w-full">
                    {{ __('auth.sign-in') }}
                </x-primary-button>


            </form>

        </x-auth-card>

    </x-auth-card-wrapper>
</section>
