{{--
    D1 (profile-contract.md §5.1): on their own profile, the employee proposes
    a new entry for $section; HR approves it before it appears. Shown to the
    active owner only ($canPropose); the waiting note and the last refusal are
    theirs alone ($proposals is empty for anyone else).
--}}
@if ($canPropose)
    @php($state = $proposals[$section] ?? null)
    <div class="mb-3 flex flex-wrap items-center gap-2" data-proposal="{{ $section }}">
        <button type="button" wire:click="openProposal('{{ $section }}')" data-testid="propose-{{ $section }}"
                class="inline-flex min-h-11 items-center gap-1.5 rounded-control border border-neutral-300 bg-white px-3 text-small font-semibold text-secondary-dark hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
            <span aria-hidden="true">+</span>
            {{ $section === 'photo' ? __('employees.profile.change.propose-photo') : __('employees.profile.change.propose') }}
            @unless ($section === 'photo')
                <span class="sr-only">{{ __('employees.profile.change.sections.'.$section) }}</span>
            @endunless
        </button>
        @if ($state['pending'] ?? false)
            <x-status tone="wait" icon="clock" data-testid="proposal-pending-{{ $section }}" :label="__('employees.profile.change.pending', ['what' => __('employees.profile.change.sections.'.$section)])" />
        @elseif ($state['rejected'] ?? null)
            <span class="text-caption text-secondary-medium" data-testid="proposal-rejected-{{ $section }}">
                {{ __('employees.profile.change.last-rejected', ['reason' => $state['rejected']]) }}
            </span>
        @endif
    </div>
@endif
