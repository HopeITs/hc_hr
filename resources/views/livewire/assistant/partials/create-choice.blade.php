{{--
    Before a new record: continue in the chat, or open the page that adds it
    (DraftRegistry::offerChoice). The link is the header New menu's own entry,
    saved with the answer; only the latest answer keeps its buttons.
    $proposal = ['type' => 'choice', 'data' => ['kind', 'label', 'url', 'chat']]
--}}
@php
    $choice = $proposal['data'] ?? [];
@endphp

@if ($isLatest)
    <div class="flex flex-wrap gap-2" data-testid="assistant-create-choice" data-kind="{{ $choice['kind'] ?? '' }}">
        @if ($choice['chat'] ?? false)
            <x-button variant="primary" size="sm" icon="pen" :loading="false"
                      x-on:click="submit(@js(__('assistant.choice.continue-message')))" data-testid="assistant-choice-chat">
                {{ __('assistant.choice.continue') }}
            </x-button>
        @endif
        @if (filled($choice['url'] ?? null))
            <x-button variant="outline" size="sm" icon="arrow" :href="$choice['url']" data-testid="assistant-choice-page">
                {{ __('assistant.choice.open', ['what' => $choice['label'] ?? '']) }}
            </x-button>
        @endif
    </div>
@endif
