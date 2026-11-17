<x-slot:title>{{ __('recruitment-workflow.pending_approvals') }}</x-slot:title>

@php
    $card = 'rounded-surface border border-neutral-200 bg-white shadow-e1';
    $eyebrow = 'text-caption font-semibold uppercase tracking-wider text-secondary-medium rtl:normal-case rtl:tracking-normal';
    $daysLabel = fn (?int $d) => $d === null ? '—' : ($d === 0 ? __('recruitment-workflow.waiting_today') : trans_choice('recruitment-workflow.waiting_days', $d, ['count' => $d]));
@endphp

<div class="space-y-6">
    <div>
        <livewire:layout.breadcrumbs :title="__('recruitment.navigation_pending_approvals')" :crumbs="[
            ['label' => __('recruitment.navigation_section')],
            ['label' => __('recruitment.navigation_pending_approvals'), 'route' => 'recruitment.approvals.pending'],
        ]" />
    </div>

    @if ($waiting === 0)
        <div class="{{ $card }} px-6 py-14 text-center" data-pending-empty>
            <span class="mx-auto flex size-12 items-center justify-center rounded-full border border-neutral-200 bg-neutral-50 text-secondary-dark" aria-hidden="true">
                <x-icons.check class="size-6" />
            </span>
            <h2 class="mt-4 text-h4 font-semibold text-neutral-900">{{ __('recruitment-workflow.empty_title') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-body text-secondary-medium text-pretty">{{ __('recruitment-workflow.empty_message') }}</p>
            <a href="{{ route('recruitment.requests.index') }}"
               class="mt-5 inline-flex min-h-11 items-center gap-2 rounded-control border border-neutral-200 bg-white px-4 text-small font-semibold text-secondary-dark shadow-e1 hover:bg-selection focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-h-10">
                {{ __('recruitment.requests') }}
                <flux:icon.arrow-right variant="micro" class="size-4 rtl:rotate-180" aria-hidden="true" />
            </a>
        </div>
    @else
        {{-- The inbox at a glance. Red only where something has waited too long: that is the one number asking to be acted on. --}}
        <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-pending-summary>
            <div class="{{ $card }} px-5 py-4">
                <dt class="{{ $eyebrow }}">{{ __('recruitment-workflow.summary_waiting') }}</dt>
                <dd class="mt-2 text-h2 font-semibold tabular-nums text-neutral-900">{{ $waiting }}</dd>
            </div>
            <div class="{{ $card }} px-5 py-4">
                <dt class="{{ $eyebrow }}">{{ __('recruitment-workflow.summary_overdue', ['count' => $longWaitDays]) }}</dt>
                <dd class="mt-2 flex items-center gap-2 text-h2 font-semibold tabular-nums {{ $overdue > 0 ? 'text-primary' : 'text-secondary-light' }}">
                    @if ($overdue > 0)<span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>@endif
                    {{ $overdue }}
                </dd>
            </div>
            <div class="{{ $card }} px-5 py-4">
                <dt class="{{ $eyebrow }}">{{ __('recruitment-workflow.summary_longest') }}</dt>
                <dd class="mt-2 text-h2 font-semibold text-neutral-900">{{ $daysLabel($longest) }}</dd>
            </div>
            <div class="{{ $card }} px-5 py-4">
                <dt class="{{ $eyebrow }}">{{ __('recruitment-workflow.summary_today') }}</dt>
                <dd class="mt-2 text-h2 font-semibold tabular-nums {{ $arrivedToday > 0 ? 'text-neutral-900' : 'text-secondary-light' }}">{{ $arrivedToday }}</dd>
            </div>
        </dl>

        <livewire:recruitment.approvals.pending-approval-table />
    @endif
</div>
