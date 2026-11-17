@props([
    'number',
    'title',
    'summary' => null,
    'done' => false,
    'current' => false,
    'locked' => false,
])

{{--
    One row of the campaign checklist. State is carried by an icon and a text
    label, never by colour alone: brand red for "done" and "next", neutrals
    for everything waiting.
--}}
<li {{ $attributes->class([
    'scroll-mt-24 rounded-surface border bg-white p-5 shadow-e1 transition sm:p-6',
    'border-primary' => $current && ! $locked,
    'border-secondary-soft' => ! $current || $locked,
]) }}>
    <div class="flex items-start gap-4">
        <span @class([
            'flex size-9 shrink-0 items-center justify-center rounded-full text-body font-bold',
            'bg-primary text-white' => $done,
            'border-2 border-primary bg-white text-primary' => ! $done && $current && ! $locked,
            'border border-secondary-light bg-secondary-soft/30 text-secondary-light' => ! $done && (! $current || $locked),
        ]) aria-hidden="true">
            @if ($done)
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
            @elseif ($locked)
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V7a4 4 0 1 1 8 0v4"/></svg>
            @else
                {{ $number }}
            @endif
        </span>

        <div class="min-w-0 flex-1">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-subtitle font-bold text-secondary-dark">{{ $title }}</h3>
                    @if ($summary)
                        <p class="mt-1 text-body text-secondary-medium">{{ $summary }}</p>
                    @endif
                </div>
                <span @class([
                    'shrink-0 rounded-full px-2.5 py-0.5 text-small font-semibold',
                    'bg-primary text-white' => $done,
                    'border border-primary text-primary' => ! $done && $current && ! $locked,
                    'bg-secondary-soft/60 text-secondary-medium' => ! $done && (! $current || $locked),
                ])>
                    @if ($done)
                        {{ __('recruitment-campaign.page.done') }}
                    @elseif ($locked)
                        {{ __('recruitment-campaign.page.waiting') }}
                    @elseif ($current)
                        {{ __('recruitment-campaign.page.next') }}
                    @else
                        {{ __('recruitment-campaign.page.to_do') }}
                    @endif
                </span>
            </div>

            <div class="mt-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</li>
