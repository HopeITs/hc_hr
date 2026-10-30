@php
    use App\Enums\LeaveTypeCases;
use App\Enums\LeaveRequestStatus;
@endphp
<x-slot:title>
    {{ __('general.my-leaves') }}
</x-slot:title>

<div>
    <div class="flex justify-between items-center mb-6">
        <livewire:layout.breadcrumbs
            :title="__('general.my-leaves')"
            :crumbs="[

            ]"
            :showButton="true"
        />

        {{-- Offered only to roles that may create; create() authorizes again on the server. --}}
        @if(auth()->user()->hasActivePermission('leaves.create'))
            <div>
                <x-primary-button wire:click="create" data-tour="leaves.request">
                    @lang('leaves.modal.new-title')
                </x-primary-button>
            </div>
        @endif

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
                    :remaining="$availableLeave->remaining"
                    :total-days="$availableLeave->intTotal"
                    :remaining-days="$availableLeave->intRemaining"
                    :is-annual="$availableLeave->isAnnual()"
                    :used="$availableLeave->used_formatted"
                    :pending="formatDuration($availableLeave->pending_days , $availableLeave->pending_hours)"
                />
            @endforeach
        </div>
    @else
        <div class="rounded-surface shadow-e2 mb-6 text-center font-semibold text-ink-2 p-4" role="status">
            @lang('general.no-available-data')
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
                <div class="min-w-0 flex-1 space-y-2">
                    <h2 id="submitted-leave-title" class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                        <span>{{ __('leaves.submit.'.($submittedKind === 'updated' ? 'updated' : 'sent').'-title') }}</span>
                        <span><span class="sr-only">@lang('general.status'): </span><x-status :status="LeaveRequestStatus::of($submittedLeave->status)" /></span>
                    </h2>
                    <p class="text-body text-ink">
                        <span class="font-medium">@lang('leaves.next-step.title'):</span>
                        {{ app(\App\Services\LeaveRequestNextStep::class)->describe($submittedLeave) }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
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
            {{ __('general.all') . ' ('. $counts['all'] ?? 0 }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Pending"
            :active-tab="LeaveRequestStatus::Pending">
            {{ LeaveRequestStatus::getLocalizedName(0)['value'] . ' ('.$counts['pending'] ?? 0  }})
        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Accepted"
            :active-tab="LeaveRequestStatus::Accepted">
            {{ LeaveRequestStatus::getLocalizedName(1)['value'] . ' ('.$counts['accepted'] ?? 0  }})

        </x-tabs-list-item>

        <x-tabs-list-item
            :active="$statusFilter === (string) LeaveRequestStatus::Rejected"
            :active-tab="LeaveRequestStatus::Rejected">
            {{ LeaveRequestStatus::getLocalizedName(2)['value'] . ' ('.$counts['rejected'] ?? 0  }})
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
        wire:model.defer="detailsModal"
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
                    <p class="mt-1 font-semibold text-ink">{{ app(\App\Services\LeaveRequestNextStep::class)->describe($selectedLeave) }}</p>
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
                        <dd class="mt-1 font-semibold text-ink"><bdi>{{ $selectedLeave->alternativeEmployee?->name ?? '—' }}</bdi></dd>
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


    <x-modal.dialog
        max-width="6xl"
        id="showModal"
        :title="$modalTitle"
        wire:model.defer="showModal"
        close-action="requestClose"
        wire:ignore.self
        class="flex items-center justify-center px-2">
        {{-- Phones (M1): the sheet itself scrolls, so there is no nested scroll area; sm and up keeps the capped panel. --}}
        <div class="w-full sheet:flex-1 sheet:flex sheet:flex-col bg-card rounded-surface px-0 py-0 dialog:max-h-[60dvh] dialog:overflow-y-auto dialog:px-6 dialog:py-4 dialog:md:px-12 dialog:md:py-6">
            {{-- Enter in a field takes the same path as Save (there is no save()). --}}
            <form wire:submit.prevent="confirmSave" class="space-y-4 sheet:flex-1 sheet:flex sheet:flex-col">
                <x-forms.errors-validation-list/>
                <div class="grid md:grid-cols-2 gap-4">

                    <div class="md:col-span-1" wire:key="container-leave-type-{{ $form->leaveRequest ? 'edit' : 'new' }}">
                        {{-- D3: the "?" sits beside the label, not inside it. --}}
                        <div class="flex items-start gap-1">
                            <x-forms.input-label for="leave_balance_id" required>{{ trans_choice('general.leave-type', 1) }}</x-forms.input-label>
                            <x-help.trigger topic="leave-units" :label="trans_choice('general.leave-type', 1)"/>
                        </div>
                        <x-forms.select2-input wire:model.live="form.leave_balance_id" required id="leave_balance_id">
                            @if($allAvailableLeaves?->count() > 0)
                                <option
                                    class="text-ink-3" @disabled($form->leaveRequest !== null) @selected($form->leaveRequest === null)>
                                    @lang('general.select')
                                </option>
                                @foreach($allAvailableLeaves  as $availableLeave)
                                    <option
                                        value="{{$availableLeave->id}}">{{$availableLeave->leaveType->name}}</option>
                                @endforeach
                            @else
                                <option
                                    class="text-ink-3" disabled>
                                    @lang('general.no-available-data')
                                </option>
                            @endif
                        </x-forms.select2-input>
                        <x-error-span>{{ $errors->first('form.leave_balance_id') }}</x-error-span>
                    </div>
                    <div class="md:col-span-1" wire:key="container-alt-employee-{{ $form->leaveRequest ? 'edit' : 'new' }}">
                        <x-forms.input-label for="alternative_employee">{{__('general.alternative-employee')}}</x-forms.input-label>
                        <x-forms.select2-input wire:model="form.alternative_employee" id="alternative_employee">
                            <option
                                class="text-ink-3" @disabled($form->leaveRequest !== null) @selected($form->leaveRequest === null)>

                                @lang('general.select')
                            </option>
                            @foreach($alternativeEmployees as $alternativeEmployee)
                                <option value="{{$alternativeEmployee->id}}">{{$alternativeEmployee->name}}</option>
                            @endforeach
                        </x-forms.select2-input>
                        <x-error-span>{{ $errors->first('form.alternative_employee') }}</x-error-span>
                    </div>

                    @if($form->leave_balance_id !== null)
                        @if($availableLeaves->find($form->leave_balance_id)?->leaveType?->id === LeaveTypeCases::Annual)
                            <x-forms.radio-group class="mt-4" :legend="__('leaves.unit.legend')" error="form.type" help="leave-units">
                                <x-forms.radio-input wire:model.live="form.type"
                                                     value="day">{{ __('leaves.unit.day') }}</x-forms.radio-input>
                                <x-forms.radio-input wire:model.live="form.type"
                                                     value="hour">{{ __('leaves.unit.hour') }}</x-forms.radio-input>
                            </x-forms.radio-group>
                        @endif
                    @endif
                    @if($form->leave_balance_id === null || $availableLeaves->find($form->leave_balance_id)?->leaveType?->id !== LeaveTypeCases::Annual)

                        <div>
                            <x-forms.input-label for="start_at" required>{{__('general.start-date')}}</x-forms.input-label>
                            <x-forms.date-input id="start_at" wire:model.live="form.start_at" :label="__('general.start-date')"/>
                            <x-error-span>{{ $errors->first('form.start_at') }}</x-error-span>
                        </div>
                        <div>
                            <x-forms.input-label for="end_at" required>{{__('general.end-date')}}</x-forms.input-label>
                            <x-forms.date-input id="end_at" wire:model.live="form.end_at" :label="__('general.end-date')"/>
                            <x-error-span>{{ $errors->first('form.end_at') }}</x-error-span>
                        </div>

                    @else

                        @if($form->type === 'day')
                            <div class="col-span-full grid md:grid-cols-2 gap-4">
                                <div>
                                    <x-forms.input-label
                                        for="start_at" required>{{__('general.start-date')}}</x-forms.input-label>
                                    <x-forms.date-input id="start_at" wire:model.live="form.start_at" :label="__('general.start-date')"/>
                                    <x-error-span>{{ $errors->first('form.start_at') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="end_at" required>{{__('general.end-date')}}</x-forms.input-label>
                                    <x-forms.date-input id="end_at" wire:model.live="form.end_at" :label="__('general.end-date')"/>
                                    <x-error-span>{{ $errors->first('form.end_at') }}</x-error-span>
                                </div>
                            </div>
                        @elseif($form->type === 'hour')

                            <div>
                                <x-forms.input-label for="leave_date" required>{{__('general.leave-date')}}</x-forms.input-label>
                                <x-forms.date-input id="leave_date" wire:model.live="form.leave_date" :label="__('general.leave-date')"/>
                                <x-error-span>{{ $errors->first('form.leave_date') }}</x-error-span>
                            </div>

                            <div>
                                <x-forms.input-label for="start_time" required>{{__('general.start-time')}}(24)
                                </x-forms.input-label>
                                <x-forms.time-picker id="start_time" wire-model="form.start_hour" format="HH:mm" min="09:00" max="21:00" :live="true" />
                                <x-error-span>{{ $errors->first('form.start_hour') }}</x-error-span>
                            </div>
                            <div>
                                <x-forms.input-label for="end_time" required>{{__('general.end-time')}}(24)</x-forms.input-label>
                                <x-forms.time-picker id="end_time" wire-model="form.end_hour" format="HH:mm" min="09:00" max="21:00" :live="true" />
                                <x-error-span>{{ $errors->first('form.end_hour') }}</x-error-span>
                            </div>

                        @endif

                    @endif

                    {{-- D-7: December leave only as an exception. Offered only
                         when the typed period touches December. --}}
                    @if($form->periodTouchesDecember())
                        <div class="col-span-full rounded-control border-[1.5px] border-ink bg-card px-3 py-2" wire:key="leave-exceptional">
                            <label for="is_exceptional" class="flex min-h-11 cursor-pointer items-start gap-3">
                                <input type="checkbox" id="is_exceptional" wire:model.live="form.is_exceptional"
                                       aria-describedby="is_exceptional-hint"
                                       class="hc-check mt-1 rounded-inset">
                                <span>
                                    <span class="block font-medium text-ink">@lang('leave_quote.exceptional.label')</span>
                                    <span id="is_exceptional-hint" class="block text-small text-ink">@lang('leave_quote.exceptional.hint')</span>
                                </span>
                            </label>
                            <x-error-span>{{ $errors->first('form.is_exceptional') }}</x-error-span>
                        </div>
                    @endif

                    {{-- Live impact summary (A3): priced on the server on each change. --}}
                    @php($impact = $this->leaveImpact)
                    @if($impact !== null)
                        <div class="col-span-full" wire:key="leave-impact">
                            @include('livewire.employee.leave.partials.impact-summary', ['impact' => $impact])
                        </div>
                    @endif

                    <div class="col-span-full">
                        <x-forms.input-label for="reason" required>{{__('general.reason')}}</x-forms.input-label>
                        <x-forms.input id="reason" wire:model="form.reason" required examples="examples.leave_reason"
                            :hint="__('examples.hints.leave_reason')" :hint-always="false"/>
                        <x-error-span>{{ $errors->first('form.reason') }}</x-error-span>
                    </div>
                </div>

                {{-- Phones (M1): Submit/Cancel stay pinned to the bottom of the sheet, above the safe area, while
                     the form scrolls; the modal root's scroll padding keeps a focused field clear of this bar. --}}
                <x-modal.actions data-testid="leave-form-actions">
                    {{-- Disabled while saving is feedback only; duplicates are
                         stopped on the server (LeaveRequestForm::store). --}}
                    <x-primary-button
                        type="button"
                        wire:click="confirmSave"
                        wire:target="confirmSave,processSave"
                        class="!w-auto">
                        {{ $form->id ? __('leaves.modal.save-changes') : __('leaves.modal.submit') }}
                    </x-primary-button>

                    {{-- Closes locally, like Escape and the header close: no
                         navigation, no reload, nothing submitted. --}}
                    <x-button variant="quiet" :loading="false" x-on:click="close()">
                        @lang('general.cancel')
                    </x-button>
                </x-modal.actions>
            </form>
        </div>
    </x-modal.dialog>
</div>
