<x-slot:title>
    {{ __('auth.forgot-password') }}
</x-slot:title>

<section class="min-h-[90dvh] rtl:text-right bg-neutral-100 flex items-center justify-center px-4">
    <x-auth-card-wrapper>

        {{-- Logo --}}
        <div class="text-center mb-12">
            <x-app-logo class="mx-auto w-36" />
        </div>

        {{-- Forgot Password Card --}}
        <x-auth-card class="w-96 space-y-6">
            {{-- Title --}}
            <div class="text-center space-y-1">
                <h2 class="text-h3 font-bold text-neutral-900">{{ __('auth.forgot-password') }}</h2>
                <p class="text-body text-secondary-medium">
                    {{ __('auth.forgot-password-text') }}
                </p>
            </div>

            {{-- Status --}}
            <x-auth-session-status class="text-center text-neutral-600 text-body" :status="session('status')" />

            {{-- Form --}}
            <form wire:submit.prevent="sendPasswordResetLink" class="space-y-4">
                {{-- Email --}}
                <div>
                    <x-forms.input-label for="email" required :value="__('auth.email-address')" />
                    <x-forms.input
                        id="email"
                        type="email"
                        model="email"
                        placeholder="email@example.com"
                        autocomplete="email"
                    />
                    <x-forms.input-error field="email" />
                </div>

                {{-- Submit --}}
                <x-primary-button class="w-full">
                    {{ __('auth.submit') }}
                </x-primary-button>
            </form>
        </x-auth-card>

        {{-- Back to Login --}}
        <div class="text-center text-secondary-medium text-body mt-6">
            {{ __('auth.back-to') }}
            <a href="{{ route('login') }}" class="text-primary hover:underline">
                {{ __('auth.sign-in') }}
            </a>
        </div>

    </x-auth-card-wrapper>
</section>
