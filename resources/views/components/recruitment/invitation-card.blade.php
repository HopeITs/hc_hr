@props(['invitation', 'token', 'locale'])

{{--
    What an invitation-only vacancy shows above its form: the one question that
    stands between the visitor and applying, or who they were let in as.
--}}
@php $state = $invitation['state']; @endphp

<section class="mt-4 rounded-[16px] border border-secondary-soft bg-white px-6 py-6 shadow-e1 sm:px-9" data-invitation-state="{{ $state }}">
    @if ($state === 'ready')
        <p class="text-small font-semibold uppercase tracking-wide text-primary">{{ __('careers.invitation.invited_as') }}</p>
        <p class="mt-1 text-subtitle font-bold text-secondary-dark" dir="ltr">{{ $invitation['email'] }}</p>
        <p class="mt-4 border-t border-secondary-soft pt-4 text-body leading-7 text-secondary-dark">{{ __('careers.invitation.ready_help') }}</p>
    @else
        <h2 class="text-subtitle font-bold text-secondary-dark">{{ __('careers.invitation.title') }}</h2>
        <p class="mt-2 max-w-xl text-body leading-7 text-secondary-medium">{{ __('careers.invitation.message') }}</p>

        <form method="POST" action="{{ route('careers.invitation', ['token' => $token]) }}" class="mt-5 flex flex-wrap items-end gap-4" novalidate>
            @csrf
            <input type="hidden" name="locale" value="{{ $locale }}">
            <div class="min-w-[240px] flex-1">
                <label for="invitee_email" class="block text-body font-semibold text-secondary-dark">{{ __('careers.invitation.email') }}</label>
                <input
                    id="invitee_email"
                    type="email"
                    name="invitee_email"
                    value="{{ old('invitee_email') }}"
                    dir="ltr"
                    autocomplete="email"
                    required
                    @if ($invitation['error'] ?? null) aria-invalid="true" aria-describedby="invitee_email_error" @endif
                    class="mt-2 block w-full max-w-sm rounded-none border-0 border-b border-neutral-300 bg-transparent px-0 py-2 text-body shadow-none outline-none focus:border-primary focus:ring-0 rtl:text-right"
                >
            </div>
            <button
                type="submit"
                class="inline-flex cursor-pointer items-center rounded-control bg-primary px-6 py-3 font-semibold text-white hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2"
            >
                {{ __('careers.invitation.check') }}
            </button>
        </form>

        @if ($invitation['error'] ?? null)
            <p id="invitee_email_error" class="mt-3 text-body font-semibold text-primary" role="alert">{{ $invitation['error'] }}</p>
        @endif
    @endif
</section>
