@props([
    'modelId' => null,
])
{{-- Icon-only unless the caller adds visible text, so it needs its own name then. --}}
@php($iconOnly = $slot->isEmpty())
<button {{
    $attributes->merge([
        'type' => 'button',
        'class' => 'cursor-pointer p-2'.($iconOnly ? ' inline-flex items-center justify-center max-sm:min-h-11 max-sm:min-w-11' : ''),
        'aria-label' => $iconOnly ? __('general.delete') : null,
])
}}  wire:click="confirmDelete({{$modelId}})">
    <svg class="size-4.5 text-primary " aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">
        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 7h14m-9 3v8m4-8v8M10 3h4a1 1 0 0 1 1 1v3H9V4a1 1 0 0 1 1-1ZM6 7h12v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7Z"/>
    </svg>

    <span>
    {{$slot}}
</span>
</button>
