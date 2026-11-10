{{--
    One contract statistics card's figures: its name, employees, what it is
    (program or center) with its centers, and the annual / service / trial
    breakdown. Drawn by the contract statistics page and by its dashboard
    tile (Dashboard\ContractStatsCard), so both read the same.
    $card: ContractStatsCards::program()/center() plus 'name'; $cardType: program | center.
--}}
<div class="p-6 pb-1.5 flex justify-between items-start">
    <div class="flex flex-col">
        <span class="text-h4 font-black text-ink">
            {{ $card['name'] }}
        </span>

        <span class="text-body text-ink-2 mt-2">
            {{ trans_choice('general.employee', 2) }}:
            <span class="font-bold text-ink num">{{ $card['employees'] }}</span>
        </span>
    </div>

    <div class="text-end">
        <span class="inline-block px-2 py-1 text-caption font-semibold rounded-control
                     {{ $cardType === 'program' ? 'bg-ink text-on-ink' : 'bg-surface text-ink' }}">
            {{ $cardType === 'program'
                ? trans_choice('general.program', 2)
                : trans_choice('general.center', 1) }}
        </span>

        @if ($card['centers'] !== null)
            <p class="text-body text-center text-ink-2 mt-2.5">
                {{ trans_choice('general.center', 2) }}:
                <span class="font-semibold text-ink">{{ $card['centers'] }}</span>
            </p>
        @endif
    </div>
</div>


<div data-tour="contract-stats.breakdown" class="grid grid-cols-3 gap-3 p-4">

    @foreach ([
        'annual'  => __('general.annual'),
        'service' => __('general.service'),
        'trial'   => __('general.trial')
    ] as $key => $label)

        @php
            $data = $card[$key];
            $isService = $key === 'service';

            $total = $isService
                ? $data['online'] + $data['onsite']
                : $data['full'] + $data['part'];
        @endphp

        <div class="rounded-surface bg-soft border border-line-soft
    p-3 hover:bg-hover transition">

            <p class="text-small font-semibold text-ink
      border-b border-line-soft pb-1 mb-1">
                {{ $label }}
            </p>

            <div class="space-y-1 text-small">

                @if ($isService)
                    <div class="flex justify-between">
                        <span class="text-ink-2">@lang('general.online')</span>
                        <span class="font-semibold text-ink">{{ $data['online'] }}</span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-ink-2">@lang('general.onsite')</span>
                        <span class="font-semibold text-ink">{{ $data['onsite'] }}</span>
                    </div>
                @else
                    <div class="flex justify-between">
                        <span class="text-ink-2">@lang('general.full-time')</span>
                        <span class="font-semibold text-ink">{{ $data['full'] }}</span>
                    </div>

                    <div class="flex justify-between">
                        <span class="text-ink-2">@lang('general.part-time')</span>
                        <span class="font-semibold text-ink">{{ $data['part'] }}</span>
                    </div>
                @endif

                <div class="border-t border-line-soft pt-1 mt-1 flex justify-between">
                    <span class="text-ink-2">@lang('general.total')</span>
                    <span class="font-bold text-ink num">{{ $total }}</span>
                </div>

            </div>
        </div>
    @endforeach
</div>
