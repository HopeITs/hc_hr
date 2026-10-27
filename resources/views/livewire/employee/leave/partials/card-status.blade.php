{{--
    A request's status as text plus colour, for the table's Status column and
    the phone cards. Literal class names on purpose: the old `bg-{color}-100`
    was built at runtime, so Tailwind never generated it and the pill had no
    colour at all. Same look as the employee status (employee/partials/status).
    Receives $item (LeaveRequest).
--}}
@php
    $status = \App\Enums\LeaveRequestStatus::getLocalizedName($item->status);
    $tone = match ((int) $item->status) {
        \App\Enums\LeaveRequestStatus::Accepted => ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'bg-emerald-500'],
        \App\Enums\LeaveRequestStatus::Rejected => ['bg-neutral-100 text-primary ring-neutral-200', 'bg-primary'],
        default => ['bg-amber-50 text-amber-800 ring-amber-200', 'bg-amber-500'],
    };
@endphp
<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-small font-semibold ring-1 {{ $tone[0] }}"><span class="size-1.5 rounded-full {{ $tone[1] }}" aria-hidden="true"></span>{{ $status['value'] }}</span>
