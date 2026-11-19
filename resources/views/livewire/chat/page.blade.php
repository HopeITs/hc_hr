{{--
    The full chat page (App\Livewire\Chat\Page): the chats list and one open
    conversation side by side. data-chat-page makes the dock step aside.
--}}
<div data-chat-page class="mx-auto w-full max-w-6xl" data-testid="chat-page">
    <div class="flex h-[calc(100dvh-11rem)] min-h-[28rem] overflow-hidden rounded-surface border border-line bg-card">

        {{-- The list (the start side). --}}
        <aside class="flex w-80 shrink-0 flex-col border-e border-line-soft">
            <header class="flex items-center gap-2 border-b border-line-soft px-3 py-2.5">
                <h1 class="flex-1 text-body font-semibold text-ink">{{ __('chat.ui.chats') }}</h1>
            </header>
            <div class="px-3 py-2">
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="{{ __('chat.ui.search_chats') }}" aria-label="{{ __('chat.ui.search_chats') }}"
                       class="hc-field h-9 w-full rounded-full text-small">
                @error('search')
                    <p class="mt-1 text-small text-primary" role="alert">{{ $message }}</p>
                @enderror
            </div>
            @include('livewire.chat.partials.list', [
                'openAction' => 'select',
                'startAction' => 'startWith',
                'activeId' => $conversationId,
            ])
        </aside>

        {{-- The open conversation. --}}
        <section class="flex min-w-0 flex-1 flex-col">
            @if ($conversationId)
                <livewire:chat.window :conversation-id="$conversationId" :open="true" mode="page"
                                      :wire:key="'chat-page-window-'.$conversationId" />
            @else
                <div class="flex flex-1 flex-col items-center justify-center gap-2 bg-soft p-8 text-center">
                    <svg class="size-10 text-ink-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.2A8 8 0 1 1 21 12z"/></svg>
                    <p class="text-small text-ink-2">{{ __('chat.ui.pick_chat') }}</p>
                </div>
            @endif
        </section>
    </div>
</div>
