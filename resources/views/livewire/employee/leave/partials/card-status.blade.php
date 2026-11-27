{{--
    A request's status for the table's Status column and the phone cards:
    icon + words + fill shape (LeaveRequestStatus::tone()), never colour.
    Receives $item (LeaveRequest).
--}}
@php($compactClass = ($compact ?? false) ? 'w-24 justify-center gap-1 px-1.5 py-0.5 text-caption leading-tight' : null)

@if ($item->isOverdue())
    <x-tag variant="late" :label="__('leaves.status.overdue')" :class="$compactClass" />
@else
    <x-status :status="\App\Enums\LeaveRequestStatus::of($item->status)" :class="$compactClass" />
@endif
