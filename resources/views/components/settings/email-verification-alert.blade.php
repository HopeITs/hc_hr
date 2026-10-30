@if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && !auth()->user()->hasVerifiedEmail())
    <div class="text-body text-ink bg-soft px-4 py-3 rounded-control border-s-4 border-ink">
        {{ __('general.profile.email-unverified') }}
        <flux:link
            wire:click.prevent="resendVerificationNotification"
            class="ms-1 underline font-medium text-ink hover:text-ink-2 cursor-pointer">
            {{ __('general.profile.resend-email') }}
        </flux:link>

        @if (session('status') === 'verification-link-sent')
            <div class="mt-1 font-semibold text-ink">
                {{ __('general.profile.email-link-code') }}
            </div>
        @endif
    </div>
@endif
