{{--
    One contract in the employee form's Contracts History tab. Markup copied
    from the Career History card in livewire/employee/employee-profile.blade.php;
    keep the two in sync. Pay is shown only when `canViewFinancial` is true
    AND the salary column was loaded (see EmployeeEdit::contractHistory).
--}}
@props(['contract', 'canViewFinancial' => false])

@php
    $notSpecified = __('general.not-specified');
    $currency = [0 => $notSpecified, 1 => 'SYP', 2 => 'USD', 3 => 'EUR'];
    $formatDate = fn($date) => $date ? \Carbon\Carbon::parse($date)->translatedFormat('d M Y') : $notSpecified;
    $isActive = $contract->is_active && !$contract->terminated_at;
@endphp

<article {{ $attributes->class('relative rounded-surface border border-neutral-200 p-4 ps-6') }}>
    <span
        class="absolute inset-y-4 start-0 w-1 rounded-e-full {{ $isActive ? 'bg-primary' : 'bg-neutral-300' }}"></span>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="font-bold text-secondary-dark">
                {{ $contract->position?->title ?? $notSpecified }}</h3>
            <p class="mt-1 text-body text-secondary-medium">
                {{ $contract->position?->department?->name ?? $notSpecified }}
                @if ($contract->contractType)
                    · {{ $contract->contractType->name }}
                @endif
            </p>
        </div>
        <span
            class="shrink-0 rounded-full px-3 py-1 text-small font-bold {{ $isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-neutral-100 text-secondary-medium' }}">
            {{ $isActive ? __('general.active') : __('general.inactive') }}
        </span>
    </div>
    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-small text-secondary-light">
        <span>{{ $formatDate($contract->start_date) }} -
            {{ $formatDate($contract->terminated_at ?? $contract->end_date) }}</span>
        @if ($contract->weekly_hours)
            <span>@lang('general.weekly-hours'): {{ $contract->weekly_hours }}</span>
        @endif
        @if ($canViewFinancial && $contract->salary)
            <span class="font-bold text-primary">@lang('general.salary'):
                {{ number_format($contract->salary) }}
                {{ $currency[$contract->currency] ?? '' }}</span>
        @endif
    </div>
</article>
