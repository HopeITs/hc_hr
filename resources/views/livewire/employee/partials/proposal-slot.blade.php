{{--
    D1 (profile-contract.md §5.1): on their own profile, the employee proposes
    a new entry for $section; HR approves it before it appears. Shown to the
    active owner only ($canPropose); the waiting note and the last refusal are
    theirs alone ($proposals is empty for anyone else).
--}}
@if ($canPropose)
    @php($state = $proposals[$section] ?? null)
    <div class="mb-3 flex flex-wrap items-center gap-2" data-proposal="{{ $section }}">
        <x-button variant="outline" size="sm" icon="plus" wire:click="openProposal('{{ $section }}')" data-testid="propose-{{ $section }}">
            {{ $section === 'photo' ? __('employees.profile.change.propose-photo') : __('employees.profile.change.propose') }}
            @unless ($section === 'photo')
                <span class="sr-only">{{ __('employees.profile.change.sections.'.$section) }}</span>
            @endunless
        </x-button>
        @if ($state['pending'] ?? false)
            <x-status tone="wait" icon="clock" data-testid="proposal-pending-{{ $section }}" :label="__('employees.profile.change.pending', ['what' => __('employees.profile.change.sections.'.$section)])" />
        @elseif ($state['rejected'] ?? null)
            <span class="text-caption text-ink-2" data-testid="proposal-rejected-{{ $section }}">
                {{ __('employees.profile.change.last-rejected', ['reason' => $state['rejected']]) }}
            </span>
        @endif
    </div>
@endif
