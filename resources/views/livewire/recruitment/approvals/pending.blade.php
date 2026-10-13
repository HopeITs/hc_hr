<x-slot:title>{{ __('recruitment-workflow.pending_approvals') }}</x-slot:title>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <livewire:layout.breadcrumbs :title="__('recruitment.navigation_pending_approvals')" :crumbs="[
            ['label' => __('recruitment.navigation_section')],
            ['label' => __('recruitment.navigation_pending_approvals'), 'route' => 'recruitment.approvals.pending'],
        ]" />

        <div class="flex flex-wrap gap-3" data-pending-summary>
            <div class="min-w-36 rounded-surface border border-secondary-soft bg-white px-4 py-3">
                <p class="text-xs font-semibold uppercase tracking-wide text-secondary-medium">{{ __('recruitment-workflow.summary_waiting') }}</p>
                <p class="mt-1 text-h3 font-bold {{ $waiting > 0 ? 'text-primary' : 'text-secondary-dark' }}">{{ $waiting }}</p>
            </div>
            @if ($waiting > 0 && $longest !== null)
                <div class="min-w-36 rounded-surface border border-secondary-soft bg-white px-4 py-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-secondary-medium">{{ __('recruitment-workflow.summary_longest') }}</p>
                    <p class="mt-1 text-h3 font-bold text-secondary-dark">
                        {{ $longest === 0 ? __('recruitment-workflow.waiting_today') : trans_choice('recruitment-workflow.waiting_days', $longest, ['count' => $longest]) }}
                    </p>
                </div>
            @endif
        </div>
    </div>

    @if ($waiting === 0)
        <div class="rounded-surface border border-secondary-soft bg-white px-6 py-12 text-center" data-pending-empty>
            <span class="mx-auto flex size-12 items-center justify-center rounded-full border border-primary text-primary" aria-hidden="true">
                <x-icons.check class="size-6" />
            </span>
            <h2 class="mt-4 text-subtitle font-bold text-secondary-dark">{{ __('recruitment-workflow.empty_title') }}</h2>
            <p class="mx-auto mt-2 max-w-md text-body text-secondary-medium">{{ __('recruitment-workflow.empty_message') }}</p>
        </div>
    @else
        <livewire:recruitment.approvals.pending-approval-table />
    @endif
</div>
