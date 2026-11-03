{{--
    The second line of a leave card (B4-D §6): leave type · dates · duration.
    An hourly leave shows its day and hours; a one-day leave one date.
    Receives $item (LeaveRequest) and, optionally, $withType = false when the
    card already shows the leave type as its title (My leaves).
--}}
@php
    $withType ??= true;
    $hourly = $item->type === 'hour';
    $start = $item->start_at;
    $end = $item->end_at;
    $dates = match (true) {
        ! $start => '—',
        $hourly => $start->format('d-m-Y').' '.$start->format('H:i').'–'.$end?->format('H:i'),
        ! $end || $start->isSameDay($end) => $start->format('d-m-Y'),
        default => $start->format('d-m-Y').' – '.$end->format('d-m-Y'),
    };
@endphp
<span class="mt-0.5 block break-words text-small text-ink-2">
    @if($withType)
        <bdi>{{ $item->leaveBalance?->leaveType?->{transColumn('name')} ?? __('general.na') }}</bdi>
        &middot;
    @endif
    <bdi dir="ltr" class="num">{{ $dates }}</bdi>
    &middot; <bdi class="num">{{ $item->duration }}</bdi>
</span>
