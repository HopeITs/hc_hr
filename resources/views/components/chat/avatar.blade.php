{{-- A chat's picture: the other person's photo, or the group mark. --}}
@props(['item', 'size' => 'size-9'])

@if (! empty($item['avatar']))
    <img src="{{ $item['avatar'] }}" alt="" {{ $attributes->class([$size, 'shrink-0 rounded-full object-cover']) }}>
@else
    <span {{ $attributes->class([$size, 'grid shrink-0 place-items-center rounded-full bg-primary text-white']) }} aria-hidden="true">
        <svg class="size-1/2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/></svg>
    </span>
@endif
