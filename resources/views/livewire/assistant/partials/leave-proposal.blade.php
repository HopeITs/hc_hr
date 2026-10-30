{{--
    A leave request the assistant drafted. Shown from the copy kept in the
    session; the buttons send back only its id, never the values below.
    $proposal = ['id', 'state' => pending|submitted|cancelled|failed, 'data' => AssistantReply::$leaveProposal]
--}}
@php
    $data = $proposal['data'] ?? [];
    $state = $proposal['state'] ?? 'pending';
    $rows = array_filter([
        __('assistant.leave.fields.type') => $data['leave_type'] ?? null,
        __('assistant.leave.fields.from') => $data['start'] ?? null,
        __('assistant.leave.fields.to') => $data['end'] ?? null,
        __('assistant.leave.fields.duration') => $data['duration'] ?? null,
        __('assistant.leave.fields.reason') => $data['reason'] ?? null,
        __('assistant.leave.fields.alternative') => $data['alternative_employee'] ?? null,
    ], fn ($value) => filled($value));
    $titleId = 'assistant-leave-'.$mode.'-'.$proposal['id'];
@endphp

<section
    wire:key="proposal-{{ $proposal['id'] }}"
    aria-labelledby="{{ $titleId }}"
    @class([
        'rounded-surface border p-4',
        'border-ink bg-card' => $state === 'pending',
        'border-line-soft bg-soft' => $state !== 'pending',
    ])
    data-testid="assistant-leave-proposal"
    data-state="{{ $state }}"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="{{ $titleId }}" class="flex items-center gap-2 text-body font-semibold text-ink">
            <x-icons.my-leaves class="size-4 text-ink-2" />
            {{ __('assistant.leave.title') }}
        </h3>
        @if ($state === 'pending')
            <x-status tone="attn" icon="pen" :label="__('assistant.leave.states.pending')" />
        @elseif ($state === 'submitted')
            <x-status tone="done" icon="check" :label="__('assistant.leave.states.submitted')" />
        @elseif ($state === 'cancelled')
            <x-status tone="stop" icon="ban" :label="__('assistant.leave.states.cancelled')" />
        @else
            <x-status tone="stop" icon="alert" :label="__('assistant.leave.states.failed')" />
        @endif
    </div>

    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-small">
        @foreach ($rows as $label => $value)
            <dt class="text-ink-2">{{ $label }}</dt>
            <dd class="min-w-0 break-words font-medium text-ink num">{{ $value }}</dd>
        @endforeach
    </dl>

    @if ($state === 'pending')
        <p class="mt-3 text-caption text-ink-3">{{ __('assistant.leave.check') }}</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <x-button variant="primary" size="sm" icon="check" wire:click="confirmLeave('{{ $proposal['id'] }}')" data-testid="assistant-leave-confirm">
                {{ __('assistant.leave.confirm') }}
            </x-button>
            <x-button variant="outline" size="sm" wire:click="cancelLeave('{{ $proposal['id'] }}')" data-testid="assistant-leave-cancel">
                {{ __('assistant.leave.cancel') }}
            </x-button>
        </div>
    @endif
</section>
