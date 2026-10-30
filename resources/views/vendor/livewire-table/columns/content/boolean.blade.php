{{-- Yes / no as an icon and a word (sr-only), not a coloured dot. --}}
<div class="flex justify-center px-3 py-2 text-ink">
    @if($value === null)
        <span class="text-ink-3" aria-hidden="true">&mdash;</span>
    @elseif($value)
        <x-ic name="check" class="size-4" /><span class="sr-only">{{ __('general.yes') }}</span>
    @else
        <x-ic name="minus" class="size-4 text-ink-2" /><span class="sr-only">{{ __('general.no') }}</span>
    @endif
</div>
