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

<article {{ $attributes->class('relative rounded-surface border border-line-soft p-4 ps-6') }}>
    <span
        class="absolute inset-y-4 start-0 w-1 rounded-e-full {{ $isActive ? 'bg-primary' : 'bg-line' }}"></span>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 id="contract-history-title-{{ $contract->id }}" class="font-bold text-ink">
                {{ $contract->position?->title ?? $notSpecified }}</h3>
            <p class="mt-1 text-body text-ink-2">
                {{ $contract->position?->department?->name ?? $notSpecified }}
                @if ($contract->contractType)
                    · {{ $contract->contractType->name }}
                @endif
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:flex-col sm:flex-nowrap sm:items-stretch">
            <x-status class="justify-center" :status="$isActive ? \App\Enums\ContractStatus::Active : \App\Enums\ContractStatus::Inactive" />
            {{-- Sized like the status badge above it; label and icon as the contracts table's View button. --}}
            @can('view', $contract)
                <a href="{{ route('contracts.show', $contract) }}" wire:navigate
                    aria-describedby="contract-history-title-{{ $contract->id }}"
                    class="hc-btn hc-btn--outline hc-btn--sm">
                    <x-icons.eye class="h-4 w-4" />
                    <span>{{ __('general.view') }}</span>
                </a>
            @endcan
        </div>
    </div>
    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-small text-ink-3">
        <span>{{ $formatDate($contract->start_date) }} -
            {{ $formatDate($contract->terminated_at ?? $contract->end_date) }}</span>
        @if ($contract->weekly_hours)
            <span>@lang('general.weekly-hours'): {{ $contract->weekly_hours }}</span>
        @endif
        @if ($canViewFinancial && $contract->salary)
            <span class="font-bold text-ink">@lang('general.salary'):
                {{ number_format($contract->salary) }}
                {{ $currency[$contract->currency] ?? '' }}</span>
        @endif
    </div>
</article>
