{{--
    The body of the working days & hours card (contract-work-days): the week as
    tiles, the same in words, where the days come from, the changes so far.
    Shared by the contract page's card and the employee profile's section.
--}}
    @if($now['count'] === 0)
        {{-- No days stated in the contract (most older contracts): say so, and
             what leave goes by meanwhile, instead of a week of days off. --}}
        <p class="rounded-control bg-soft px-3 py-2 text-small text-ink">
            <x-ic name="info" class="inline size-4" />
            @lang('contracts.work-days.none-stated')
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            @if($hours)
                <x-tag icon="clock">{{ $hours }}</x-tag>
            @endif
            @if($now['center'] !== '')
                <x-tag icon="building">@lang('contracts.work-days.center-off', ['days' => $now['center']])</x-tag>
            @endif
        </div>
    @else
        {{-- The week at a glance: seven tiles, Saturday first, each with an
             icon and a word (_work-day-tile). Short names up to laptop
             width, full names from xl up; the badges below say it in words. --}}
        <ol class="grid max-w-3xl grid-cols-7 gap-1 sm:gap-2" aria-label="@lang('contracts.work-days.title')">
            @foreach($week as $d)
                <li class="flex">@include('livewire.contract._work-day-tile', ['d' => $d, 'full' => true])</li>
            @endforeach
        </ol>

        {{-- The same week in words. --}}
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <x-tag icon="check">{{ trans_choice('contracts.work-days.days-a-week', $now['count'], ['count' => $now['count']]) }}</x-tag>
            @if($hours)
                {{-- The hours of a working day, and of the week. --}}
                <x-tag icon="clock">{{ $hours }}</x-tag>
            @endif
            @if($isRange && $rangeLabel)
                {{-- A day range is the contract's own week, not a day off chosen. --}}
                <x-tag icon="calendar">@lang('contracts.work-days.range-badge', ['range' => $rangeLabel])</x-tag>
            @elseif($now['own'] !== '')
                <x-tag icon="home">@lang('contracts.work-days.own-off', ['days' => $now['own']])</x-tag>
            @endif
            @if($now['center'] !== '')
                <x-tag icon="building">@lang('contracts.work-days.center-off', ['days' => $now['center']])</x-tag>
            @endif
        </div>
    @endif

    @if($since || $next)
        <p class="mt-3 text-small text-ink-2">
            @if($since)
                <span class="num">@lang('contracts.work-days.since', ['date' => $since['from']->format('d/m/Y')])</span>
                @if($since['label'])
                    <span class="text-ink-3">· {{ $since['label'] }}</span>
                @endif
            @endif
            @if($next)
                <span @class(['block' => $since, 'font-semibold text-ink'])>
                    <x-ic name="clock" class="inline size-3.5" />
                    <span class="num">@lang('contracts.work-days.next', ['date' => $next['from']->format('d/m/Y'), 'days' => $next['days'] ?? __('contracts.work-days.back-to-range')])</span>
                </span>
            @endif
        </p>
    @endif

    @if($history->isNotEmpty())
        <details wire:ignore.self class="group/history mt-3 border-t border-line-soft pt-2">
            <summary class="flex cursor-pointer list-none items-center gap-2 text-small font-semibold text-ink-2 hover:text-ink
                            [&::-webkit-details-marker]:hidden">
                <x-ic name="chevron" class="size-3.5 transition-transform group-open/history:rotate-90 rtl:-scale-x-100" />
                @lang('contracts.work-days.history') <span class="num font-normal text-ink-3">({{ $history->count() }})</span>
            </summary>
            <ol class="mt-1 divide-y divide-line-soft ps-5">
                @foreach($history as $row)
                    <li class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-1.5 text-small">
                        <span class="num font-semibold text-ink">
                            @lang('contracts.work-days.from', ['date' => $row['from']->format('d/m/Y')])
                        </span>
                        <span class="text-ink">{{ $row['days'] ?? __('contracts.work-days.back-to-range') }}</span>
                        @if($row['note'] || $row['by'])
                            <span class="text-caption text-ink-3">
                                {{ collect([$row['note'], $row['by']])->filter()->implode(' · ') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </details>
    @endif
