<x-slot:title>
    {{ __('auth.reset-password') }}
</x-slot:title>

<section class="min-h-[90dvh] rtl text-right bg-neutral-100 flex items-center justify-center px-4">
    <x-auth-card-wrapper>

        {{-- Logo --}}
        <div class="text-center mb-12">
            <x-app-logo class="mx-auto" />
        </div>

        {{-- Reset Password Card --}}
        <x-auth-card class="w-96 space-y-6">
            <div class="text-center space-y-1">
                <h2 class="text-h3 font-bold text-neutral-900">{{ __('auth.reset-password') }}</h2>
                <p class="text-body text-secondary-medium">
                    {{ __('auth.reset-password-text') }}
                </p>
            </div>

            <x-auth-session-status class="text-center text-neutral-600 text-body" :status="session('status')" />

            <form wire:submit.prevent="resetPassword" class="space-y-4">
                {{-- Hidden Email --}}
                <input type="hidden" wire:model="email" />

                {{-- New Password --}}
                <div>
                    <x-forms.input-label for="password" required :value="__('auth.password')" />

                    <x-forms.password-input
                        id="password"
                        model="password"
                        placeholder="••••••••"
                        autocomplete="new-password"
                        x-on:input="checkPasswordStrength($event.target.value)"
                    />

                    {{-- Password Strength Bar --}}
                    <div class="flex mt-2 gap-2" id="passwordStrengthBar">
                        <span class="w-1/4 h-[5px] rounded bg-neutral-200"></span>
                        <span class="w-1/4 h-[5px] rounded bg-neutral-200"></span>
                        <span class="w-1/4 h-[5px] rounded bg-neutral-200"></span>
                        <span class="w-1/4 h-[5px] rounded bg-neutral-200"></span>
                    </div>

                    {{-- Hint --}}
                    <p class="text-small text-secondary-medium mt-1">
                        {{ __('auth.password-text') }}
                    </p>

                    <x-forms.input-error field="password" />
                </div>

                {{-- Confirm Password --}}
                <div>
                    <x-forms.input-label for="password_confirmation" required :value="__('auth.confirm-password')" />
                    <x-forms.password-input
                        id="password_confirmation"
                        model="password_confirmation"
                        placeholder="••••••••"
                        autocomplete="new-password"
                    />
                    <x-forms.input-error field="password_confirmation" />
                </div>

                {{-- Submit --}}
                <x-primary-button class="w-full">
                    {{ __('auth.confirm') }}
                </x-primary-button>
            </form>
        </x-auth-card>
    </x-auth-card-wrapper>
</section>
