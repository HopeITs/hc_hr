{{--
    A chat's picture: the other person's photo, or the group mark. In a
    private chat a green dot shows while that person is online
    (data-chat-online-dot, resources/js/chat.js).
--}}
@props(['item', 'size' => 'size-9'])

@php
    $userId = ($item['type'] ?? null) === 'private' ? ($item['other_user_id'] ?? null) : null;
@endphp

<span {{ $attributes->class([$size, 'relative inline-flex shrink-0']) }}>
    @if (! empty($item['avatar']))
        <img src="{{ $item['avatar'] }}" alt="" class="size-full rounded-full object-cover">
    @else
        <span class="grid size-full place-items-center rounded-full bg-primary text-white" aria-hidden="true">
            <svg class="size-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>
        </span>
    @endif

    @if ($userId)
        {{-- Shown by chat.js while that person is online. --}}
        <span hidden data-chat-online-dot="{{ (int) $userId }}"
              class="absolute bottom-0 end-0 size-[28%] min-h-2 min-w-2 rounded-full border-2 border-card bg-ink"
              aria-hidden="true" data-testid="chat-online-dot"></span>
    @endif
</span>
