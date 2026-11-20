{{--
    Add employee, when accepted job offers are still waiting to be converted
    (EmployeeCreate::offersAwaitingConversion). Converting from the offer links
    the person to their recruitment file and writes the contract from the
    accepted terms; a record typed in here does neither. Red is right here: it
    waits on the viewer.
--}}
<section class="mb-4 rounded-control border border-s-4 border-line-soft border-s-primary bg-card p-4" role="status" data-testid="offers-awaiting-conversion">
    <h2 class="flex items-center gap-2 font-semibold text-ink">
        <x-ic name="inbox" aria-hidden="true" />
        {{ trans_choice('employees.offers-awaiting.title', $awaiting['count'], ['count' => $awaiting['count']]) }}
    </h2>
    <p class="mt-1 text-small text-ink-2">@lang('employees.offers-awaiting.hint')</p>

    <ul class="mt-3 divide-y divide-line-soft">
        @foreach ($awaiting['rows'] as $conversion)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="awaiting-offer-{{ $conversion->id }}">
                <span class="min-w-0">
                    <span class="font-medium text-ink" dir="auto">{{ $conversion->applicant?->full_name ?? '—' }}</span>
                    @if ($conversion->jobOffer?->position)
                        <span class="text-small text-ink-2"> · <span dir="auto">{{ $conversion->jobOffer->position->title }}</span></span>
                    @endif
                </span>
                <x-button variant="outline" size="sm" :href="route('recruitment.offers.convert', ['offerId' => $conversion->job_offer_id])">
                    @lang('employees.offers-awaiting.convert')
                </x-button>
            </li>
        @endforeach
    </ul>

    @if ($awaiting['count'] > $awaiting['rows']->count())
        <x-button variant="link" size="sm" class="mt-2" :href="route('recruitment.offers.index')">
            @lang('employees.offers-awaiting.all')
        </x-button>
    @endif
</section>
