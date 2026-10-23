{{-- Summary shown in the save-confirmation dialog of a leave request.
     duration / projected / warnings come from LeaveRequestPricing (A3); the
     balance line is passed only to the requester, never to a manager. --}}
@props([
    'type' => 'day',
    'leaveType' => '',
    'start' => '',
    'end' => '',
    'duration' => '',
    'projected' => null,
    'warnings' => [],
    'reason' => '',
    'date' => '',
])
<div class="flex flex-col items-start justify-start space-y-2 text-start">
    <span><b>{{ trans_choice('general.leave-type', 1) }}:</b> {{ $leaveType }}</span>
    <span><b>@lang('general.type'):</b> {{ __('leaves.unit.'.($type === 'hour' ? 'hour' : 'day')) }}</span>
    @if($type === 'hour')
        <span><b>@lang('general.leave-date'):</b> <bdi dir="ltr">{{ $date }}</bdi></span>
        <span><b>@lang('general.time'):</b> <bdi dir="ltr">{{ $start }}</bdi> {{ __('leaves.range-separator') }} <bdi dir="ltr">{{ $end }}</bdi></span>
    @else
        <span><b>@lang('general.during-period'):</b> <bdi dir="ltr">{{ $start }}</bdi> {{ __('leaves.range-separator') }} <bdi dir="ltr">{{ $end }}</bdi></span>
    @endif
    <span><b>@lang('general.duration'):</b> <bdi>{{ $duration }}</bdi></span>
    @if($projected !== null)
        <span><b>@lang('leave_quote.preview.projected'):</b> <bdi>{{ $projected }}</bdi></span>
    @endif
    @foreach($warnings as $warning)
        <span class="text-amber-800">{{ $warning }}</span>
    @endforeach
    <span><b>@lang('general.reason'):</b> {{ $reason }}</span>
    @if($projected !== null)
        <span class="text-sm text-neutral-600">@lang('leave_quote.preview.estimate')</span>
    @endif
</div>
