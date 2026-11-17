@php
    use App\Enums\LeaveRequestStatus;
@endphp
<x-slot:title>
    {{ __('general.my-leaves') }}
</x-slot:title>

<div>
    {{-- Title and New request share a row while they fit; on a narrow phone the button wraps below the title. --}}
    <div class="mb-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <livewire:layout.breadcrumbs
            :title="__('general.my-leaves')"
            :crumbs="[]"
            :showButton="true"
        />

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{-- The page's live card on the dashboard (Dashboard\AddToDashboard). --}}
            <livewire:dashboard.add-to-dashboard tile="my-requests" />
            {{-- The page's own primary action (also the guided tour's anchor); the bottom nav's "+" reaches the same
                 dialog through ?new=1. Offered only to roles that may create; create() authorizes again on the server. --}}
            @if(auth()->user()->hasActivePermission('leaves.create'))
                {{-- An "add": a red "+" square that opens on hover to say "Request leave". --}}
                <x-button icon="plus" wire:click="create" data-tour="leaves.request">
                    @lang('leaves.modal.new-title')
                </x-button>
            @endif
        </div>
    </div>

    <livewire:employee.leave.birthday-holiday-card />

    @if($loadFailed)
        <x-ui.load-error class="mb-6" :message="__('states.balances-unavailable')" />
    @elseif($availableLeaves !== null && $availableLeaves->isNotEmpty())
        <div data-tour="leaves.balances" class="grid
            grid-cols-1
            sm:grid-cols-2
            lg:grid-cols-3
            xl:grid-cols-5
            gap-6
            mb-6">
            @foreach($availableLeaves as $availableLeave)
                <x-leave-card
                    class="{{ $availableLeave->isAnnual() ? 'col-span-1 sm:col-span-2' : 'col-span-1' }}"
                    :title="$availableLeave->leaveType->name"
                    :total="$availableLeave->deserved_formatted"
                    :remaining="$availableLeave->available_formatted"
                    :total-days="$availableLeave->intTotal"
                    :remaining-days="$availableLeave->int_available"
                    :is-annual="$availableLeave->isAnnual()"
                    :used="$availableLeave->used_formatted"
                    :pending="formatDuration($availableLeave->pending_days , $availableLeave->pending_hours)"
                />
            @endforeach
        </div>
    @else
        <div class="rounded-surface shadow-e2 mb-6 text-center font-semibold text-ink-2 p-4" role="status">
            {{-- Someone without a running contract has no balance yet: say why and who can help. --}}
            {{ auth()->user()->profile?->latestActiveContract() ? __('general.no-available-data') : __('general.no-active-contract') }}
        </div>

    @endif

    {{-- Result of the last send/update (A3): the real status and who acts next.
         Stays until hidden, unlike the toast, so it can be read at any pace. --}}
    @php
        $submittedLeave = $this->submittedLeave;
    @endphp
    @if($submittedLeave)
        <section wire:key="submitted-leave-{{ $submittedLeave->id }}-{{ $submittedKind }}"
                 x-data="{ open: true }" x-show="open"
                 aria-labelledby="submitted-leave-title"
                 data-testid="leave-submitted-result"
                 class="mb-6 rounded-surface border border-s-4 border-line-soft border-s-ink bg-card p-4 shadow-e2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1 basis-56 space-y-2">
                    <h2 id="submitted-leave-title" class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                        <span>{{ __('leaves.submit.'.($submittedKind === 'updated' ? 'updated' : 'sent').'-title') }}</span>
                        <span><span class="sr-only">@lang('general.status'): </span><x-status :status="LeaveRequestStatus::of($submittedLeave->status)" /></span>
                    </h2>
                    <p class="break-words text-body text-ink">
                        <span class="font-medium">@lang('leaves.next-step.title'):</span>
                        {{ app(\App\Services\LeaveRequestNextStep::class)->describe($submittedLeave) }}
                    </p>
                </div>
                <div class="ms-auto flex shrink-0 items-center gap-2">
                    <x-button variant="outline" size="sm"
                            wire:click="openDetails({{ $submittedLeave->id }})">
                        @lang('leaves.submit.view-details')
                    </x-button>
                    <button type="button"
                            x-on:click="open = false"
                            aria-label="{{ __('leaves.submit.dismiss') }}"
                            class="grid size-11 place-items-center rounded-control text-ink-3 hover:bg-hover hover:text-ink">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </section>
    @endif

    <x-tabs-list data-tour="leaves.filters">
        <x-tabs-list-item
            :active="$statusFilter === 'all'" active-tab="all">
            {{ __('general.all') }} ({{ $counts['all'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Pending"
            :active-tab="LeaveRequestStatus::Pending">
            {{ LeaveRequestStatus::getLocalizedName(0)['value'] }} ({{ $counts['pending'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Accepted"
            :active-tab="LeaveRequestStatus::Accepted">
            {{ LeaveRequestStatus::getLocalizedName(1)['value'] }} ({{ $counts['accepted'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Rejected"
            :active-tab="LeaveRequestStatus::Rejected">
            {{ LeaveRequestStatus::getLocalizedName(2)['value'] }} ({{ $counts['rejected'] ?? 0 }})
        </x-tabs-list-item>
    </x-tabs-list>

    {{-- Table --}}
    <div class="mt-4" data-tour="leaves.table">
        <livewire:employee.leave.my-leave-request-table :statusFilter="$statusFilter" key="table-{{ $statusFilter }}"/>
    </div>

    <x-modal.dialog
        max-width="4xl"
        id="leaveDetailsModal"
        :title="__('general.leave-details')"
        wire:model.live="detailsModal"
        wire:ignore.self>
        @php
            $selectedLeave = $this->selectedLeave;
        @endphp

        @if($selectedLeave)
            <div class="space-y-6">
                <x-leave.decision-summary :leave-request="$selectedLeave" />

                {{-- Who acts next, for every status (A3). --}}
                <div class="rounded-xl border border-line-soft bg-soft p-4" data-testid="leave-next-step">
                    <h3 class="text-small text-ink-2">@lang('leaves.next-step.title')</h3>
                    <p class="mt-1 break-words font-semibold text-ink">{{ app(\App\Services\LeaveRequestNextStep::class)->describe($selectedLeave) }}</p>
                </div>

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ trans_choice('general.leave-type', 1) }}</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->leaveBalance->leaveType->name }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">@lang('general.type')</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ __('leaves.unit.'.($selectedLeave->type === 'hour' ? 'hour' : 'day')) }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">@lang('general.during-period')</dt>
                        <dd class="mt-1 font-semibold text-ink">
                            @if($selectedLeave->type === 'hour')
                                <bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y, H:i') }}</bdi>
                                <span aria-hidden="true">{{ __('leaves.range-separator') }}</span>
                                <bdi dir="ltr">{{ $selectedLeave->end_at->format('H:i') }}</bdi>
                            @else
                                <bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y') }}</bdi>
                                <span aria-hidden="true">{{ __('leaves.range-separator') }}</span>
                                <bdi dir="ltr">{{ $selectedLeave->end_at->format('d-m-Y') }}</bdi>
                            @endif
                        </dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">@lang('general.duration')</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->duration }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                        <dt class="text-small text-ink-2">@lang('general.alternative-employee')</dt>
                        <dd class="mt-1 break-words font-semibold text-ink"><bdi>{{ $selectedLeave->alternativeEmployee?->name ?? '—' }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                        <dt class="text-small text-ink-2">@lang('general.reason')</dt>
                        <dd class="mt-1 whitespace-pre-line break-words text-ink">{{ $selectedLeave->reason }}</dd>
                    </div>
                </dl>

                <div class="grid">
                    @include('livewire.employee.leave.partials.hr-notes', ['leaveRequest' => $selectedLeave])
                </div>

                <div class="flex justify-end">
                    <x-button variant="quiet" wire:click="closeDetails">
                        @lang('general.cancel')
                    </x-button>
                </div>
            </div>
        @endif
    </x-modal.dialog>

    @include('livewire.employee.leave.partials.request-dialog')
</div>
