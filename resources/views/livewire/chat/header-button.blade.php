{{-- The chat icon beside the bell (App\Livewire\Chat\HeaderButton). --}}
<button type="button" wire:click="$dispatch('chat-launcher-toggle')"
        aria-label="{{ $unread > 0 ? trans_choice('chat.ui.unread_chats', $unread, ['count' => $unread]) : __('chat.ui.chats') }}"
        title="{{ __('chat.ui.chats') }}"
        class="relative inline-flex items-center justify-center transition rounded-[14px] cursor-pointer size-11 sm:size-9 text-ink-2 hover:bg-card hover:text-ink hover:shadow-e1"
        data-testid="chat-header-button">
    <svg class="size-[17px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.5 7.2L4 21l1.8-5.2A8 8 0 1 1 21 12z"/></svg>
    @if ($unread > 0)
        <span class="pointer-events-none absolute -top-0.5 -end-0.5 min-w-[16px] rounded-full bg-primary px-1 text-center text-caption font-bold leading-4 text-white" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
    @endif
</button>
