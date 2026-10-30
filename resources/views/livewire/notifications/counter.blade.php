<div
    @if($poll) wire:poll.10s="updateCount" @endif
    {{-- The scope lives on the stable wrapper: the badge below appears and
         disappears with the count, and a morph that re-created it without its
         own x-data left :class evaluating "pop" with no scope. --}}
    x-data="{ pop: false }"
    x-init="pop = true; setTimeout(() => pop = false, 500)"
    @notif-arrived.window="pop = false; $nextTick(() => { pop = true; setTimeout(() => pop = false, 500) })"
>
    @if($count > 0)
        <span
            :class="pop && 'animate-notif-pop'"
            class="inline-flex items-center justify-center h-[17px] min-w-[17px] px-[4px] rounded-full
                   bg-primary text-on-brand text-caption font-bold leading-none tabular-nums
                   ring-2 ring-base shadow-e1">
            {{ $count > 99 ? '99+' : $count }}
        </span>
    @endif
</div>
