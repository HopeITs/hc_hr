{{--
    A record the assistant drafted (a leave request, a news item, an
    employee, a contract...). Shown from the copy saved with the conversation;
    the buttons send back only its id, never the values below.
    $proposal = ['id', 'state' => pending|submitted|cancelled|failed, 'type', 'data']
--}}
@php
    $card = $this->draftCard($proposal);
    $state = $proposal['state'] ?? 'pending';
    $titleId = 'assistant-draft-'.$mode.'-'.$proposal['id'];
@endphp

<section
    wire:key="proposal-{{ $proposal['id'] }}"
    aria-labelledby="{{ $titleId }}"
    @class([
        'rounded-surface border p-4',
        'border-ink bg-card' => $state === 'pending',
        'border-line-soft bg-soft' => $state !== 'pending',
    ])
    data-testid="assistant-draft"
    data-type="{{ $proposal['type'] ?? 'leave' }}"
    data-state="{{ $state }}"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="{{ $titleId }}" class="flex items-center gap-2 text-body font-semibold text-ink">
            <x-ic :name="$card['icon']" class="text-ink-2" />
            {{ $card['title'] }}
        </h3>
        @if ($state === 'pending')
            <x-status tone="attn" icon="pen" :label="__('assistant.draft.states.pending')" />
        @elseif ($state === 'submitted')
            <x-status tone="done" icon="check" :label="__('assistant.draft.states.submitted')" />
        @elseif ($state === 'cancelled')
            <x-status tone="stop" icon="ban" :label="__('assistant.draft.states.cancelled')" />
        @else
            <x-status tone="stop" icon="alert" :label="__('assistant.draft.states.failed')" />
        @endif
    </div>

    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-small">
        @foreach ($card['rows'] as $label => $value)
            <dt class="text-ink-2">{{ $label }}</dt>
            <dd class="min-w-0 whitespace-pre-line break-words font-medium text-ink num">{{ $value }}</dd>
        @endforeach
    </dl>

    @if ($state === 'pending')
        <p class="mt-3 text-caption text-ink-3">{{ __('assistant.draft.check') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <x-button variant="primary" size="sm" icon="check" wire:click="confirmDraft('{{ $proposal['id'] }}')" data-testid="assistant-draft-confirm">
                {{ __('assistant.draft.confirm') }}
            </x-button>
            <x-button variant="outline" size="sm" wire:click="cancelDraft('{{ $proposal['id'] }}')" data-testid="assistant-draft-cancel">
                {{ __('assistant.draft.cancel') }}
            </x-button>
        </div>
    @endif
</section>
