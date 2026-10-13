@if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !auth()->user()->hasVerifiedEmail())
    <div class="text-body bg-neutral-50 dark:bg-neutral-900/20 text-neutral-800 dark:text-neutral-100 px-4 py-3 rounded-surface border border-neutral-300 dark:border-neutral-600">
        {{ __('general.profile.email-unverified') }}
        <flux:link
            wire:click.prevent="resendVerificationNotification"
            class="ms-1 underline font-medium text-primary hover:text-secondary-dark cursor-pointer">
            {{ __('general.profile.resend-email') }}
        </flux:link>

        @if (session('status') === 'verification-link-sent')
            <div class="mt-1 font-medium text-neutral-600 dark:text-neutral-400">
                {{ __('general.profile.email-link-code') }}
            </div>
        @endif
    </div>
@endif
