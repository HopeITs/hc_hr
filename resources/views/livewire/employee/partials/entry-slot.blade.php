{{--
    On their own profile the employee adds a new entry for $section, saved at
    once (meeting 19/11). Shown to the active owner only ($canAdd). A proposal
    sent to HR before that decision still shows its state here, to the owner
    alone ($proposals is empty for anyone else), until HR decides it.
--}}
@if ($canAdd)
    @php($state = $proposals[$section] ?? null)
    <div class="mb-3 flex flex-wrap items-center gap-2" data-entry="{{ $section }}">
        <x-button variant="outline" size="sm" icon="plus" wire:click="openEntry('{{ $section }}')" data-testid="add-{{ $section }}">
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
