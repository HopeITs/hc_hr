{{--
    A request's status for the table's Status column and the phone cards:
    icon + words + fill shape (LeaveRequestStatus::tone()), never colour.
    Receives $item (LeaveRequest).
--}}
@if ($item->isOverdue())
    <x-tag variant="late" :label="__('leaves.status.overdue')" />
@else
    <x-status :status="\App\Enums\LeaveRequestStatus::of($item->status)" />
@endif
