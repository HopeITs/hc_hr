@props(['status', 'labelKey'])

@php
    use App\Enums\HiringCampaignStatus as Status;

    // Brand red means "live"; everything else is neutral. The label and the
    // icon carry the meaning, so nothing depends on telling colours apart.
    $live = in_array((int) $status, [Status::Open, Status::Published], true);
    $open = (int) $status === Status::Open;
@endphp

<span {{ $attributes->class([
    'inline-flex items-center gap-2 rounded-full px-3 py-1 text-small font-bold',
    'bg-primary text-white' => $open,
    'border border-primary bg-white text-primary' => $live && ! $open,
    'border border-secondary-light bg-secondary-soft/30 text-secondary-dark' => ! $live,
]) }} data-campaign-status="{{ $labelKey }}">
    @if ($open)
        <span class="relative flex size-2" aria-hidden="true">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-white opacity-60 motion-reduce:animate-none"></span>
            <span class="relative inline-flex size-2 rounded-full bg-white"></span>
        </span>
    @elseif ($live)
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
    @else
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><circle cx="12" cy="12" r="8"/></svg>
    @endif
    {{ __('recruitment-campaign.page.badge_'.$labelKey) }}
</span>
