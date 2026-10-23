@props([
    'modelId' => null,
])
{{-- `.stop` matters: the surrounding table row is itself a link to the edit
     page, and without it renewing would navigate away instead. --}}
<button {{
    $attributes->merge([
        'type' => 'button',
        'class' => 'cursor-pointer p-2 inline-flex items-center max-sm:min-h-11 max-sm:min-w-11 justify-center',
        'title' => __('general.renew'),
])
}} x-on:click.stop="Livewire.dispatch('open-renew-modal', { contractId: {{ (int) $modelId }} })">
    <svg class="size-4.5 text-primary" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24"
         fill="none" viewBox="0 0 24 24">
        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M17.651 7.65a7.131 7.131 0 0 0-12.68 3.15M18.001 4v4h-4m-7.652 8.35a7.13 7.13 0 0 0 12.68-3.15M6 20v-4h4"/>
    </svg>
    <span class="sr-only">@lang('general.renew')</span>
    {{ $slot }}
</button>
