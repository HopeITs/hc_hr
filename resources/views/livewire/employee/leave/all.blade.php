@php
    use App\Enums\LeaveTypeCases;
use App\Enums\LeaveRequestStatus;
@endphp
<x-slot:title>
    {{ __('general.all-leaves') }}
</x-slot:title>

<div>
    {{-- Title and Add share a row while they fit; on a narrow phone the button wraps below the title. --}}
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <livewire:layout.breadcrumbs
            :title="__('general.all-leaves')"
            :crumbs="[]"
            :showButton="true"
        />
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{-- The page's live card on the dashboard (Dashboard\AddToDashboard). --}}
            <livewire:dashboard.add-to-dashboard tile="upcoming-leaves" />
            {{-- HR's "request on someone's behalf": not the bottom nav's own-request "+", so it stays on phones too. --}}
            @activerole('HR','super-admin')
            <x-primary-button wire:click="create">
                @lang('general.add-new')
            </x-primary-button>
            @endactiverole
        </div>
    </div>

    <x-tabs-list>
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
    <div class="mt-4">
        <livewire:employee.leave.all-leave-request-table :statusFilter="$statusFilter" key="table-{{ $statusFilter }}"/>
    </div>


    <x-modal.dialog
        max-width="4xl"
        id="formModal"
        :title="$modalTitle"
        wire:model.defer="formModal"
        close-action="requestClose"
        wire:ignore.self
        class="flex items-center justify-center px-2">
        @php
            // The same numbered steps as the employee's own request form (index.blade.php), over HR's
            // fields: employee → leave type → unit (annual only) → dates → reason and cover.
            $employeeReadOnly = $employees->isEmpty() && $form->leaveRequest;
            $chosen = $form->leave_balance_id !== null;
            $isAnnual = $chosen && $allAvailableLeaves->find($form->leave_balance_id)?->leaveType?->id === LeaveTypeCases::Annual;
            $hasUnit = in_array($form->type, ['day', 'hour'], true);
            $isHourly = $isAnnual && $form->type === 'hour';

            $done = [
                'employee' => $employeeReadOnly || filled($form->employee_id),
                'type' => $chosen,
                'unit' => $hasUnit,
                'dates' => $isHourly
                    ? filled($form->leave_date) && filled($form->start_hour) && filled($form->end_hour)
                    : filled($form->start_at) && filled($form->end_at),
                'details' => mb_strlen(trim((string) $form->reason)) >= 3,
            ];
            // An annual leave shows its dates once the unit is picked, as before.
            $visibleSteps = array_values(array_filter([
                'employee',
                'type',
                $isAnnual ? 'unit' : null,
                ! $isAnnual || $hasUnit ? 'dates' : null,
                'details',
            ]));
            $current = collect($visibleSteps)->first(fn ($step) => ! $done[$step]);
            $stateOf = fn (string $step) => $done[$step] ? 'done' : ($current === $step ? 'current' : 'todo');
            // Numbered here, not with ++ in the tag: Blade evaluates a component's
            // attribute expressions more than once, which skipped numbers.
            $numberOf = fn (string $step) => array_search($step, $visibleSteps, true) + 1;
        @endphp
        {{-- Phones (M1): the sheet itself scrolls, so there is no nested scroll area; sm and up keeps the capped panel. --}}
        <div data-csb-exclude class="thin-scroll w-full sheet:flex-1 sheet:flex sheet:flex-col bg-card rounded-surface px-0 py-0 dialog:max-h-[60dvh] dialog:overflow-y-auto dialog:px-6 dialog:py-4 dialog:md:px-10 dialog:md:py-6">
            {{-- Enter and Save take the same path: validate, then confirm. --}}
            <form wire:submit.prevent="confirmSave" class="space-y-8 sheet:flex-1 sheet:flex sheet:flex-col">
                <x-forms.errors-validation-list/>

                {{-- 1. Employee. Only HR has an employee list. A manager editing a team member's
                     request sees whose it is; an empty dropdown there showed no
                     one and could clear the leave type (G-B-6). --}}
                <x-leave.request-step :number="$numberOf('employee')" id="leave-step-employee" :title="trans_choice('general.employee', 1)"
                                      :state="$stateOf('employee')" wire:key="leave-step-employee">
                    @if ($employeeReadOnly)
                        <p class="rounded-control border border-line-soft bg-soft px-3 py-3 font-semibold text-ink" data-testid="leave-form-employee"><bdi>{{ $form->leaveRequest->leaveBalance?->contract?->employee?->name }}</bdi></p>
                    @else
                        <x-forms.input-label for="employee_id" required class="sr-only">{{trans_choice('general.employee',1)}}</x-forms.input-label>
                        <x-forms.select2-input wire:model.live="form.employee_id" id="employee_id">
                            <option value="">{{ __('leaves.select-employee') }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                            @endforeach
                        </x-forms.select2-input>
                    @endif
                    <x-error-span>{{ $errors->first('form.employee_id') }}</x-error-span>
                </x-leave.request-step>

                {{-- 2. Leave type: the chosen employee's balances. --}}
                <x-leave.request-step :number="$numberOf('type')" id="leave-step-type" :title="__('leaves.request.steps.category')"
                                      help="leave-units" :state="$stateOf('type')"
                                      wire:key="container-leave-type-{{ $form->employee_id ?? 'none' }}-{{ $form->leaveRequest ? 'edit' : 'create' }}">
                    {{-- No cards until the employee is known: they are that employee's balances. --}}
                    @php $typeCards = $formModal && $done['employee'] ? $this->leaveTypeCards : []; @endphp
                    @if($typeCards === [])
                        <p class="rounded-control border border-dashed border-line bg-soft px-3 py-3 text-small text-ink-2" role="status">
                            {{ filled($form->employee_id) ? __('leaves.request.none-for-employee') : __('leaves.request.pick-employee-first') }}
                        </p>
                    @else
                        <div id="leave_balance_id" class="grid grid-cols-2 gap-2 sm:gap-3 md:grid-cols-3 lg:grid-cols-4" role="radiogroup"
                             aria-labelledby="leave-step-type-title" data-testid="leave-type-cards">
                            @foreach($typeCards as $card)
                                @php $selected = (string) $form->leave_balance_id === (string) $card['balance_id']; @endphp
                                <label class="relative block cursor-pointer" wire:key="leave-type-card-{{ $card['balance_id'] }}">
                                    <input type="radio" class="peer sr-only" name="form.leave_balance_id" id="leave-type-card-{{ $card['balance_id'] }}"
                                           value="{{ $card['balance_id'] }}" wire:model.live="form.leave_balance_id" @checked($selected)>
                                    <span class="hc-choice flex h-full min-h-24 flex-col gap-1 p-3">
                                        <span class="flex items-start justify-between gap-2">
                                            <span class="grid size-9 place-items-center rounded-round bg-soft text-ink">
                                                <x-ic :name="$card['icon']" class="size-5" />
                                            </span>
                                            @if($selected)
                                                <x-ic name="check-circle" class="size-5 text-ink" />
                                            @endif
                                        </span>
                                        <span class="font-bold leading-snug text-ink">{{ $card['label'] }}</span>
                                        <span class="text-caption text-ink-2 num">{{ $card['left'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                    <x-error-span>{{ $errors->first('form.leave_balance_id') }}</x-error-span>
                </x-leave.request-step>

                {{-- 3. Unit: annual leave is taken by the day or by the hour (form.type). --}}
                @if($isAnnual)
                    <x-leave.request-step :number="$numberOf('unit')" id="leave-step-unit" :title="__('leaves.request.steps.unit')"
                                          help="leave-units" :state="$stateOf('unit')" wire:key="leave-step-unit">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3" role="radiogroup" aria-labelledby="leave-step-unit-title">
                            @foreach(['day' => ['daily', 'calendar'], 'hour' => ['hourly', 'clock']] as $unit => [$labelKey, $icon])
                                <label class="relative block cursor-pointer" wire:key="leave-unit-{{ $unit }}">
                                    <input type="radio" class="peer sr-only" name="form.type" id="radio-formtype-{{ $unit }}"
                                           value="{{ $unit }}" wire:model.live="form.type" @checked($form->type === $unit)>
                                    <span class="hc-choice flex h-full min-h-16 items-center gap-3 p-3">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-round bg-soft text-ink">
                                            <x-ic :name="$icon" class="size-5" />
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-bold text-ink">@lang('leaves.request.subtype.'.$labelKey)</span>
                                            <span class="block text-caption text-ink-2">@lang('leaves.request.subtype.'.$labelKey.'-hint')</span>
                                        </span>
                                        @if($form->type === $unit)
                                            <x-ic name="check-circle" class="size-5 text-ink" />
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        <x-error-span>{{ $errors->first('form.type') }}</x-error-span>
                    </x-leave.request-step>
                @endif

                {{-- 4. Dates: whole days, or one date and its hours (annual by the hour). --}}
                @if(in_array('dates', $visibleSteps, true))
                    <x-leave.request-step :number="$numberOf('dates')" id="leave-step-dates" :title="__('leaves.request.steps.dates')"
                                          :state="$stateOf('dates')" wire:key="leave-step-dates-{{ $isHourly ? 'hour' : 'day' }}">
                        @if($isHourly)
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4">
                                <div class="col-span-2 sm:col-span-1">
                                    <x-forms.input-label for="leave_date" required>{{__('general.leave-date')}}</x-forms.input-label>
                                    <x-forms.date-input id="leave_date" wire:model.live="form.leave_date" :label="__('general.leave-date')"/>
                                    <x-error-span>{{ $errors->first('form.leave_date') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="start_time" required>{{__('general.start-time')}}</x-forms.input-label>
                                    <x-forms.time-picker id="start_time" wire-model="form.start_hour" format="HH:mm" />
                                    <x-error-span>{{ $errors->first('form.start_hour') }}</x-error-span>
                                </div>
                                <div>
                                    <x-forms.input-label for="end_time" required>{{__('general.end-time')}}</x-forms.input-label>
                                    <x-forms.time-picker id="end_time" wire-model="form.end_hour" format="HH:mm" />
                                    <x-error-span>{{ $errors->first('form.end_hour') }}</x-error-span>
                                </div>
                            </div>
                        @else
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
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
                            </div>
                        @endif
                    </x-leave.request-step>
                @endif

                {{-- 5. Reason and cover. --}}
                <x-leave.request-step :number="$numberOf('details')" id="leave-step-details" :title="__('leaves.request.steps.details')"
                                      :state="$stateOf('details')" wire:key="leave-step-details">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="md:col-span-2">
                            <x-forms.input-label for="reason" required>{{__('general.reason')}}</x-forms.input-label>
                            <x-forms.input id="reason" wire:model="form.reason" examples="examples.leave_reason"
                                :hint="__('examples.hints.leave_reason')" :hint-always="false"/>
                            <x-error-span>{{ $errors->first('form.reason') }}</x-error-span>
                        </div>
                        <div class="md:col-span-2" wire:key="container-alt-emp-{{ $form->employee_id }}-{{ $form->leaveRequest ? 'edit' : 'create' }}">
                            <x-forms.input-label for="alternative_employee">{{__('general.alternative-employee')}}</x-forms.input-label>
                            <x-forms.select2-input wire:model.live="form.alternative_employee" id="alternative_employee">
                                <option
                                    class="text-ink-3" @disabled($form->leaveRequest !== null) @selected($form->leaveRequest === null)>
                                    @lang('general.select')..
                                </option>
                                @foreach($alternativeEmployees as $emp)
                                    <option value="{{ $emp->id }}" @selected($form->alternative_employee === $emp->id)>{{ $emp->name }}</option>
                                @endforeach
                            </x-forms.select2-input>
                            <x-error-span>{{ $errors->first('form.alternative_employee') }}</x-error-span>
                        </div>
                    </div>
                </x-leave.request-step>

                {{-- Phones (M1): Save/Cancel stay pinned to the bottom of the sheet while the form scrolls. --}}
                <x-modal.actions>
                    <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
                    {{-- Closes through the guard, like Escape and the header close: no link, no submit. --}}
                    <x-button variant="quiet" :loading="false" x-on:click="close()">@lang('general.cancel')</x-button>
                </x-modal.actions>
            </form>
        </div>
    </x-modal.dialog>

    <x-modal.dialog
        max-width="4xl"
        id="showModal"
        :title="$modalTitle"
        wire:model.live="showModal"
        wire:ignore.self>
        @if($selectedLeave)
            <div class="space-y-6">
                <x-leave.decision-summary :leave-request="$selectedLeave" />

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.name') }}</dt>
                        <dd class="mt-1 break-words font-semibold text-ink"><bdi>{{ $selectedLeave->leaveBalance->contract->employee->name }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.request_date') }}</dt>
                        <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $selectedLeave->created_at->format('d-m-Y') }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.type') }}</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->leaveType->name }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.alternative-employee') }}</dt>
                        <dd class="mt-1 break-words font-semibold text-ink"><bdi>{{ optional($selectedLeave->alternativeEmployee)->name ?? '—' }}</bdi></dd>
                    </div>

                    @if($selectedLeave->type === 'day')
                        <div class="rounded-xl border border-line-soft p-4">
                            <dt class="text-small text-ink-2">{{ __('general.start-date') }}</dt>
                            <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y') }}</bdi></dd>
                        </div>

                        <div class="rounded-xl border border-line-soft p-4">
                            <dt class="text-small text-ink-2">{{ __('general.end-date') }}</dt>
                            <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $selectedLeave->end_at->format('d-m-Y') }}</bdi></dd>
                        </div>

                        <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                            <dt class="text-small text-ink-2">{{ __('general.duration') }}</dt>
                            <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->duration }}</dd>
                        </div>
                    @else
                        <div class="rounded-xl border border-line-soft p-4">
                            <dt class="text-small text-ink-2">{{ __('general.leave-date') }}</dt>
                            <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y') }}</bdi></dd>
                        </div>

                        <div class="rounded-xl border border-line-soft p-4">
                            <dt class="text-small text-ink-2">{{ __('general.duration') }}</dt>
                            <dd class="mt-1 font-semibold text-ink">{{ $selectedLeave->duration }}</dd>
                        </div>

                        <div class="rounded-xl border border-line-soft p-4">
                            <dt class="text-small text-ink-2">{{ __('general.start-at') }}</dt>
                            <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $selectedLeave->start_at->format('H:i') }}</bdi></dd>
                        </div>

                        <div class="rounded-xl border border-line-soft p-4">
                            <dt class="text-small text-ink-2">{{ __('general.end-at') }}</dt>
                            <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $selectedLeave->end_at->format('H:i') }}</bdi></dd>
                        </div>
                    @endif

                    <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                        <dt class="text-small text-ink-2">{{ __('general.reason') }}</dt>
                        <dd class="mt-1 whitespace-pre-line break-words text-ink">{{ $selectedLeave->reason }}</dd>
                    </div>
                </dl>

                <div class="grid">
                    @include('livewire.employee.leave.partials.hr-notes', ['leaveRequest' => $selectedLeave])
                </div>

                <div class="flex justify-end gap-2">
                    {{-- Only for whoever delete() would allow; the others got a 403. --}}
                    @if($canDeleteSelected)
                        <x-primary-button wire:click="confirmDelete({{$selectedLeave->id}})"
                                          class="!w-auto">
                            {{ __('general.delete') }}
                        </x-primary-button>
                    @endif
                    <x-button variant="quiet" wire:click="closeDetails">
                        {{ __('general.cancel') }}
                    </x-button>
                </div>
            </div>
        @endif
    </x-modal.dialog>

    <x-modal.dialog
        max-width="4xl"
        id="decisionModal"
        :title="$modalTitle"
        wire:model.defer="decisionModal"
        close-action="requestClose"
        wire:ignore.self>
        @if($decisionLeave)
            {{-- A real form, so Enter in the reason field submits (G-B-7). --}}
            <form wire:submit.prevent="submitDecision" class="space-y-6 sheet:flex-1 sheet:flex sheet:flex-col">

                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.name') }}</dt>
                        <dd class="mt-1 break-words font-semibold text-ink"><bdi>{{ $decisionLeave->leaveBalance->contract->employee->name }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.request_date') }}</dt>
                        <dd class="mt-1 font-semibold text-ink"><bdi dir="ltr">{{ $decisionLeave->created_at->format('d-m-Y') }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.type') }}</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $decisionLeave->leaveType->name }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.alternative-employee') }}</dt>
                        <dd class="mt-1 break-words font-semibold text-ink"><bdi>{{ optional($decisionLeave->alternativeEmployee)->name ?? '—' }}</bdi></dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.during-period') }}</dt>
                        <dd class="mt-1 font-semibold text-ink">
                            @if($decisionLeave->type === 'day')
                                <bdi dir="ltr">{{ $decisionLeave->start_at->format('d-m-Y') }}</bdi>
                                <span aria-hidden="true">{{ __('leaves.range-separator') }}</span>
                                <bdi dir="ltr">{{ $decisionLeave->end_at->format('d-m-Y') }}</bdi>
                            @else
                                <span class="flex items-center gap-2">
                                    <x-ic name="calendar" class="size-4 shrink-0 text-ink-2" />
                                    <bdi dir="ltr">{{ $decisionLeave->start_at->format('d-m-Y') }}</bdi>
                                </span>
                                <span class="flex items-center gap-2">
                                    <x-ic name="clock" class="size-4 shrink-0 text-ink-2" />
                                    <span><bdi dir="ltr">{{ $decisionLeave->start_at->format('H:i') }}</bdi> {{ __('leaves.range-separator') }} <bdi dir="ltr">{{ $decisionLeave->end_at->format('H:i') }}</bdi></span>
                                </span>
                            @endif
                        </dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4">
                        <dt class="text-small text-ink-2">{{ __('general.duration') }}</dt>
                        <dd class="mt-1 font-semibold text-ink">{{ $decisionLeave->duration }}</dd>
                    </div>

                    <div class="rounded-xl border border-line-soft p-4 sm:col-span-2">
                        <dt class="text-small text-ink-2">{{ __('general.reason') }}</dt>
                        <dd class="mt-1 whitespace-pre-line break-words text-ink">{{ $decisionLeave->reason }}</dd>
                    </div>
                </dl>

                @if($decisionOverlap)
                    @include('livewire.team.partials.overlap-warning', ['overlap' => $decisionOverlap])
                @endif

                {{-- Decision Section --}}
                @if($decisionLeave->status === LeaveRequestStatus::Pending)
                    <div class="space-y-4 rounded-xl border border-line-soft bg-soft p-4">

                        {{-- One <label> per radio (inside radio-input); wrapping it in a second one was invalid nesting. --}}
                        <x-forms.radio-group :legend="__('general.action')" error="form.status" required>
                            <x-forms.radio-input :value="LeaveRequestStatus::Accepted"
                                                 wire:model.live="form.status">
                                {{ __('general.accept') }}
                            </x-forms.radio-input>
                            <x-forms.radio-input :value="LeaveRequestStatus::Rejected"
                                                 wire:model.live="form.status">
                                {{ __('general.reject') }}
                            </x-forms.radio-input>
                        </x-forms.radio-group>

                        @if((int)$form->status === LeaveRequestStatus::Rejected)
                            <div>
                                <x-forms.input-label for="reject_reason" required>{{ __('general.reject-reason') }}</x-forms.input-label>
                                <x-forms.input id="reject_reason" wire:model="form.reject_reason"/>
                                <x-forms.input-error field="form.reject_reason" input-id="reject_reason"/>
                            </div>
                        @endif
                    </div>
                @endif

                <x-modal.actions>
                    <x-primary-button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submitDecision"
                        class="!w-auto">
                        {{ __('general.save') }}
                    </x-primary-button>

                    <x-button variant="quiet" :loading="false" x-on:click="close()">
                        {{ __('general.cancel') }}
                    </x-button>
                </x-modal.actions>

            </form>
        @endif
    </x-modal.dialog>


</div>
