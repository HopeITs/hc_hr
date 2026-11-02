{{-- Earlier conversations, shared by the page and the panel (livewire/assistant/chat). Shown with the History button. --}}
<section x-show="history" x-cloak aria-labelledby="assistant-history-{{ $mode }}" data-testid="assistant-history">
    <h2 id="assistant-history-{{ $mode }}" class="mb-2 text-small font-semibold text-ink-2">{{ __('assistant.history.title') }}</h2>

    @if ($conversations->isEmpty())
        <p class="rounded-surface border border-line-soft bg-soft px-4 py-3 text-small text-ink-2">{{ __('assistant.history.empty') }}</p>
    @else
        <ul class="divide-y divide-line-soft overflow-hidden rounded-surface border border-line-soft bg-card">
            @foreach ($conversations as $conversation)
                @php $isCurrent = (int) $currentId === $conversation->id; @endphp
                <li wire:key="conversation-{{ $conversation->id }}" @class(['flex items-center gap-1', 'bg-surface' => $isCurrent])>
                    <button
                        type="button"
                        wire:click="openConversation({{ $conversation->id }})"
                        x-on:click="history = false"
                        class="flex min-h-11 min-w-0 flex-1 flex-col items-start px-3 py-2 text-start transition-colors hover:bg-hover"
                        @if ($isCurrent) aria-current="true" @endif
                        data-conversation="{{ $conversation->id }}"
                    >
                        <span class="w-full truncate text-small font-medium text-ink">{{ $conversation->title }}</span>
                        <span class="text-caption text-ink-3">
                            {{ $conversation->updated_at->diffForHumans() }}
                            @if ($isCurrent) · {{ __('assistant.history.current') }} @endif
                        </span>
                    </button>
                    <button
                        type="button"
                        x-on:click="if (await $confirm({ title: @js(__('assistant.history.delete-confirm')) })) $wire.deleteConversation({{ $conversation->id }})"
                        class="me-1 inline-flex size-11 shrink-0 items-center justify-center rounded-control text-ink-3 transition-colors hover:bg-hover hover:text-ink"
                        aria-label="{{ __('assistant.history.delete') }}: {{ $conversation->title }}"
                        title="{{ __('assistant.history.delete') }}"
                    >
                        <x-ic name="trash" />
                    </button>
                </li>
            @endforeach
        </ul>
    @endif
</section>
