{{-- An employee's status: icon + words + fill shape (App\Enums\EmployeeStatus). A draft or an
     awaiting record past its B5-D age gets a "Needs review" tag: it waits on the viewer. --}}
@php
    $employeeStatus = \App\Enums\EmployeeStatus::for($employee);
    $reviewAfter = match ($employeeStatus) {
        \App\Enums\EmployeeStatus::Incomplete => \App\Models\Employee::DRAFT_REVIEW_DAYS,
        \App\Enums\EmployeeStatus::AwaitingContract => \App\Models\Employee::AWAITING_REVIEW_DAYS,
        default => null,
    };
@endphp
<x-status :status="$employeeStatus" />
@if($reviewAfter !== null && ($employee->updated_at?->lt(now()->subDays($reviewAfter)) ?? false))
    <x-tag variant="act" class="ms-1" data-testid="needs-review">{{ __('employees.onboarding.needs-review') }}</x-tag>
@endif
