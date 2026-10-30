@props([
    'remainingDays' => 0 ,
    'totalDays' => 12 ,
    'used' => '12 days & 4 hours',
    'remaining' => '4 hours',
    'pending' => '',
    'total' => '',
    'title' => 'Leave',
    'isAnnual' => false

])
<div {{$attributes->merge([
    'class' => 'flex max-sm:flex-wrap items-end gap-4 sm:gap-8 bg-card rounded-surface py-4 px-6 w-full min-w-0'
]) }}>
    @if($isAnnual)
        {{-- Phones: the details drop below the figure instead of being pushed off-screen (M1). --}}
        <div class="flex items-end sm:-mb-3 shrink-0 max-sm:w-full">
            <span class="num text-[72px] sm:text-[125px] font-extrabold text-ink-2 leading-none">{{$remainingDays}}</span>
            <span class="text-h2 text-ink-2 font-semibold mb-4">/{{$totalDays}}</span>
        </div>

        <div class="hidden sm:block border-s border-ink-2 h-26 shrink-0"></div>
    @endif
    <div class="flex flex-col justify-between flex-1 min-w-0 max-sm:basis-full">
        <h4 class="text-ink-2 font-bold text-md">
            {{$title}}
        </h4>
        <div class="border-b border-3 border-ink w-6"></div>
        <ul class="mt-3 space-y-1 text-ink-3 text-small leading-relaxed">
            <li class="text-ink-3 text-start">
                <span>@lang('general.used-leave', ['attr' => $used ]) </span>

            </li>
            <li>
                <span>@lang('general.remaining-leave', ['attr' => $remaining ]) </span>
            </li>
            <li>
                <span>@lang('general.pending-leave', ['attr' => $pending ]) </span>

            </li>
            <li>
                <span>@lang('general.total-leave', ['attr' => $total ]) </span>
            </li>
        </ul>
    </div>
</div>
