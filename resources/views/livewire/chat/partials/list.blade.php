{{--
    The chats list, shared by the dock's launcher and the full chat page.
    Expects: $chats, $colleagues, $search, $openAction (method taking a
    conversation id), $startAction (method taking a user id), and optionally
    $activeId (the chat open beside the list) and $emptyAction (new chat).
--}}
@php($activeId = $activeId ?? null)

<div class="min-h-0 flex-1 overflow-y-auto px-1 pb-2" data-csb-exclude>
    @if ($search !== '' && ($chats->isNotEmpty() || $colleagues->isNotEmpty()))
        <p class="px-2 pb-1 pt-2 text-caption font-semibold uppercase tracking-wide text-ink-3">{{ __('chat.ui.chats') }}</p>
    @endif

    @foreach ($chats as $item)
        <button type="button" wire:click="{{ $openAction }}({{ $item['id'] }})"
                wire:key="chat-row-{{ $item['id'] }}"
                @class([
                    'flex w-full items-center gap-2.5 rounded-control px-2 py-2 text-start hover:bg-hover',
                    'bg-hover' => $activeId === $item['id'],
                ])
                data-testid="chat-row">
            <x-chat.avatar :item="$item" size="size-10" />
            <span class="min-w-0 flex-1">
                <span class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate text-small {{ $item['unread'] > 0 ? 'font-bold text-ink' : 'font-semibold text-ink' }}">{{ $item['title'] }}</span>
                    @if ($item['last_at'])
                        <span class="shrink-0 text-caption text-ink-3">{{ \App\Support\ChatTime::listLabel($item['last_at']) }}</span>
                    @endif
                </span>
                <span class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate text-[12px] text-ink-2" dir="auto">
                        @if ($item['last_mine'])<span class="text-ink-3">{{ __('chat.ui.you') }}:</span>@endif
                        {{ $item['last_preview'] }}
                    </span>
                    @if ($item['unread'] > 0)
                        <span class="min-w-[18px] shrink-0 rounded-full px-1.5 text-center text-caption font-semibold text-white {{ $item['muted'] ? 'bg-ink-3' : 'bg-primary' }}">{{ $item['unread'] }}</span>
                    @endif
                </span>
            </span>
        </button>
    @endforeach

    {{-- While searching: colleagues there is no chat with yet. One tap starts it. --}}
    @if ($colleagues->isNotEmpty())
        <p class="px-2 pb-1 pt-3 text-caption font-semibold uppercase tracking-wide text-ink-3">{{ __('chat.ui.colleagues') }}</p>
        @foreach ($colleagues as $person)
            <button type="button" wire:click="{{ $startAction }}({{ $person['id'] }})" wire:key="chat-colleague-{{ $person['id'] }}"
                    class="flex w-full items-center gap-2.5 rounded-control px-2 py-2 text-start hover:bg-hover"
                    data-testid="chat-colleague">
                <img src="{{ $person['avatar'] }}" alt="" class="size-10 shrink-0 rounded-full object-cover">
                <span class="min-w-0 flex-1 truncate text-small font-semibold text-ink">{{ $person['name'] }}</span>
            </button>
        @endforeach
    @endif

    @if ($chats->isEmpty() && $colleagues->isEmpty())
        <div class="flex flex-col items-center gap-2 px-6 py-10 text-center">
            <p class="text-body font-semibold text-ink">{{ $search === '' ? __('chat.ui.empty_title') : __('chat.ui.no_matches') }}</p>
            @if ($search === '')
                <p class="text-small text-ink-2">{{ __('chat.ui.empty_body') }}</p>
                @isset($emptyAction)
                    <button type="button" wire:click="{{ $emptyAction }}"
                            class="mt-2 rounded-control bg-primary px-4 py-2 text-small font-semibold text-white">{{ __('chat.ui.new_chat') }}</button>
                @endisset
            @endif
        </div>
    @endif
</div>
