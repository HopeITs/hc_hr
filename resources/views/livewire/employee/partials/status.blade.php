{{-- Status as text plus colour. B5 adds Incomplete (a draft) and Awaiting contract, with a review flag past the B5-D ages. --}}
@php
    $needsReview = fn (int $days) => $employee->updated_at?->lt(now()->subDays($days)) ?? false;
@endphp
@if($employee->isDraft())
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-amber-50 px-2 py-0.5 text-small font-semibold text-amber-800 ring-1 ring-amber-200"><span class="size-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>{{ __('employees.onboarding.status-incomplete') }}</span>
    @if($needsReview(\App\Models\Employee::DRAFT_REVIEW_DAYS))
        <span class="ms-1 whitespace-nowrap text-small font-semibold text-primary" data-testid="needs-review">{{ __('employees.onboarding.needs-review') }}</span>
    @endif
@elseif($employee->is_active)
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-emerald-50 px-2 py-0.5 text-small font-semibold text-emerald-700 ring-1 ring-emerald-200"><span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>{{ __('general.active') }}</span>
@elseif($employee->isAwaitingContract())
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-sky-50 px-2 py-0.5 text-small font-semibold text-sky-800 ring-1 ring-sky-200"><span class="size-1.5 rounded-full bg-sky-500" aria-hidden="true"></span>{{ __('employees.onboarding.status-awaiting') }}</span>
    @if($needsReview(\App\Models\Employee::AWAITING_REVIEW_DAYS))
        <span class="ms-1 whitespace-nowrap text-small font-semibold text-primary" data-testid="needs-review">{{ __('employees.onboarding.needs-review') }}</span>
    @endif
@else
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-neutral-100 px-2 py-0.5 text-small font-semibold text-secondary-medium ring-1 ring-neutral-200"><span class="size-1.5 rounded-full bg-neutral-400" aria-hidden="true"></span>{{ __('general.inactive') }}</span>
@endif
