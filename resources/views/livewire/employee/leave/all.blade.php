@php
    use App\Enums\LeaveTypeCases;
use App\Enums\LeaveRequestStatus;
@endphp
<x-slot:title>
    {{ __('general.all-leaves') }}
</x-slot:title>

<div>
    <div class="flex justify-between items-center">
        <livewire:layout.breadcrumbs
            :title="__('general.all-leaves')"
            :crumbs="[

            ]"
            :showButton="true"
        />
        @activerole('HR','super-admin')
        <div>
            <x-primary-button wire:click="create">
                @lang('general.add-new')
            </x-primary-button>
        </div>
        @endactiverole

    </div>

    <x-tabs-list>
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
    <div class="mt-4">
        <livewire:employee.leave.all-leave-request-table :statusFilter="$statusFilter" key="table-{{ $statusFilter }}"/>
    </div>


    <x-modal.dialog max-width="6xl" id="formModal" :title="$modalTitle" wire:model.defer="formModal" close-action="requestClose" wire:ignore.self>

        <form wire:submit.prevent="save" class="space-y-4 sheet:flex-1 sheet:flex sheet:flex-col">
            <x-forms.errors-validation-list/>
            <div class="grid md:grid-cols-2 gap-4">
                <div class="md:col-span-1">
                    {{-- Only HR has an employee list. A manager editing a team member's
                         request sees whose it is; an empty dropdown there showed no
                         one and could clear the leave type (G-B-6). --}}
                    @if ($employees->isEmpty() && $form->leaveRequest)
                        <x-forms.input-label>{{trans_choice('general.employee',1)}}</x-forms.input-label>
                        <p class="py-2 text-neutral-800" data-testid="leave-form-employee"><bdi>{{ $form->leaveRequest->leaveBalance?->contract?->employee?->name }}</bdi></p>
                    @else
                        <x-forms.input-label for="employee_id" required>{{trans_choice('general.employee',1)}}</x-forms.input-label>
                        <x-forms.select2-input wire:model.live="form.employee_id" id="employee_id">
                            <option value="">{{ __('leaves.select-employee') }}</option>
                            @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->name }}</option>
                            @endforeach
                        </x-forms.select2-input>
                    @endif
                    <x-error-span>{{ $errors->first('form.employee_id') }}</x-error-span>
                </div>
            
                <div class="md:col-span-1" wire:key="container-leave-type-{{ $form->employee_id ?? 'none' }}-{{ $form->leaveRequest ? 'edit' : 'create' }}" >
                    <x-forms.input-label for="leave_balance_id" required>{{ trans_choice('general.leave-type', 1) }}</x-forms.input-label>
                    <x-forms.select2-input wire:model.live="form.leave_balance_id" id="leave_balance_id">
                        <option
                            class="text-secondary-light" @disabled($form->leaveRequest !== null) @selected($form->leaveRequest === null)>
                            @lang('general.select')..
                        </option>
                        @foreach($allAvailableLeaves ?? [] as $availableLeave)
                            <option value="{{$availableLeave->id}}" @selected($form->leave_balance_id === $availableLeave->id)>{{$availableLeave->leaveType->name}}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('form.leave_balance_id') }}</x-error-span>
                </div>
                <div class="md:col-span-1" wire:key="container-alt-emp-{{ $form->employee_id }}-{{ $form->leaveRequest ? 'edit' : 'create' }}">
                    <x-forms.input-label for="alternative_employee">{{__('general.alternative-employee')}}</x-forms.input-label>
                    <x-forms.select2-input wire:model.live="form.alternative_employee" id="alternative_employee">
                        <option
                            class="text-secondary-light" @disabled($form->leaveRequest !== null) @selected($form->leaveRequest === null)>
                            @lang('general.select')..
                        </option>
                        @foreach($alternativeEmployees as $emp)
                            <option value="{{ $emp->id }}" @selected($form->alternative_employee === $emp->id)>{{ $emp->name }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('form.alternative_employee') }}</x-error-span>
                </div>

                @if($form->leave_balance_id !== null)
                    @if($allAvailableLeaves->find($form->leave_balance_id)?->leaveType?->id === LeaveTypeCases::Annual)
                        <x-forms.radio-group class="mt-4" :legend="__('leaves.unit.legend')" error="form.type">
                            <x-forms.radio-input wire:model.live="form.type" value="day">{{ __('leaves.unit.day') }}</x-forms.radio-input>
                            <x-forms.radio-input wire:model.live="form.type" value="hour">{{ __('leaves.unit.hour') }}</x-forms.radio-input>
                        </x-forms.radio-group>
                    @endif
                @endif
                @if($form->leave_balance_id === null || $allAvailableLeaves->find($form->leave_balance_id)?->leaveType?->id !== LeaveTypeCases::Annual)
                    <div class="col-span-full md:grid md:grid-cols-2 md:gap-4">
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
                @else

                    @if($form->type === 'day')
                        <div class="col-span-full grid md:grid-cols-2 gap-4">
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
                    @elseif($form->type === 'hour')

                        <div>
                            <x-forms.input-label for="leave_date" required>{{__('general.leave-date')}}</x-forms.input-label>
                            <x-forms.date-input id="leave_date" wire:model.live="form.leave_date" :label="__('general.leave-date')"/>
                            <x-error-span>{{ $errors->first('form.leave_date') }}</x-error-span>
                        </div>
                        <div class=" md:grid md:grid-cols-2 md:gap-4">
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

                    @endif

                @endif
                <div class="col-span-full">
                    <x-forms.input-label for="reason" required>{{__('general.reason')}}</x-forms.input-label>
                    <x-forms.input id="reason" wire:model="form.reason"/>
                    <x-error-span>{{ $errors->first('form.reason') }}</x-error-span>
                </div>
            </div>

            <x-modal.actions>
                <x-primary-button type="button" wire:click="confirmSave"
                                  class="!w-auto">@lang('general.save')</x-primary-button>
                {{-- Closes through the guard, like Escape and the header close: no link, no submit. --}}
                <x-primary-button type="button" :show-spinner="false" x-on:click="close()"
                                  class="!bg-secondary-light !w-auto">@lang('general.cancel')</x-primary-button>
            </x-modal.actions>
        </form>
    </x-modal.dialog>

    <x-modal.dialog id="showModal" :title="$modalTitle" wire:model.defer="showModal" wire:ignore.self>
        @if($selectedLeave)
            <div class="space-y-6">
                <x-leave.decision-summary :leave-request="$selectedLeave" />

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <x-forms.input-label>{{ __('general.name') }}</x-forms.input-label>
                        <p class="text-neutral-800"><bdi>{{ $selectedLeave->leaveBalance->contract->employee->name }}</bdi></p>
                    </div>
                    <div>
                        <x-forms.input-label>{{ __('general.request_date') }}</x-forms.input-label>
                        <p class="text-neutral-800"><bdi dir="ltr">{{ $selectedLeave->created_at->format('d-m-Y') }}</bdi></p>
                    </div>

                    <div>
                        <x-forms.input-label>{{ __('general.type') }}</x-forms.input-label>
                        <p class="text-neutral-800">{{ $selectedLeave->leaveType->name }}</p>
                    </div>

                    <div>
                        <x-forms.input-label>{{ __('general.alternative-employee') }}</x-forms.input-label>
                        <p class="text-neutral-800">
                            <bdi>{{ optional($selectedLeave->alternativeEmployee)->name ?? '—' }}</bdi>
                        </p>
                    </div>


                    @if($selectedLeave->type === 'day')
                        <div>
                            <x-forms.input-label>{{ __('general.start-date') }}</x-forms.input-label>
                            <p class="text-neutral-800"><bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y') }}</bdi></p>
                        </div>

                        <div>
                            <x-forms.input-label>{{ __('general.end-date') }}</x-forms.input-label>
                            <p class="text-neutral-800"><bdi dir="ltr">{{ $selectedLeave->end_at->format('d-m-Y') }}</bdi></p>
                        </div>
                        <div>
                            <x-forms.input-label>{{ __('general.duration') }}</x-forms.input-label>
                            <p class="text-neutral-800">{{ $selectedLeave->duration }}</p>
                        </div>
                    @else

                        <div>
                            <x-forms.input-label>{{ __('general.leave-date') }}</x-forms.input-label>
                            <p class="text-neutral-800"><bdi dir="ltr">{{ $selectedLeave->start_at->format('d-m-Y') }}</bdi></p>
                        </div>
                        <div>
                            <x-forms.input-label>{{ __('general.duration') }}</x-forms.input-label>
                            <p class="text-neutral-800">{{ $selectedLeave->duration }}</p>
                        </div>

                        <div>
                            <x-forms.input-label>{{ __('general.start-at') }}</x-forms.input-label>
                            <p class="text-neutral-800"><bdi dir="ltr">{{ $selectedLeave->start_at->format('H:i') }}</bdi></p>
                        </div>
                        <div>
                            <x-forms.input-label>{{ __('general.end-at') }}</x-forms.input-label>
                            <p class="text-neutral-800"><bdi dir="ltr">{{ $selectedLeave->end_at->format('H:i') }}</bdi></p>
                        </div>

                    @endif

                    <div class="col-span-full">
                        <x-forms.input-label>{{ __('general.reason') }}</x-forms.input-label>
                        <p class="text-neutral-800">{{ $selectedLeave->reason }}</p>
                    </div>

                    @include('livewire.employee.leave.partials.hr-notes', ['leaveRequest' => $selectedLeave])

                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <x-primary-button wire:click="confirmDelete({{$selectedLeave->id}})"
                                      class=" !w-auto">
                        {{ __('general.delete') }}
                    </x-primary-button>
                    <x-primary-button wire:click="closeDetails"
                                      class="!bg-secondary-light !w-auto">
                        {{ __('general.cancel') }}
                    </x-primary-button>
                </div>

            </div>
        @endif
    </x-modal.dialog>

    <x-modal.dialog id="decisionModal" :title="$modalTitle" wire:model.defer="decisionModal" close-action="requestClose" wire:ignore.self>
        @if($decisionLeave)
            {{-- A real form, so Enter in the reason field submits (G-B-7). --}}
            <form wire:submit.prevent="submitDecision" class="space-y-6 sheet:flex-1 sheet:flex sheet:flex-col">

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <x-forms.input-label>{{ __('general.name') }}</x-forms.input-label>
                        <p class="text-neutral-800"><bdi>{{ $decisionLeave->leaveBalance->contract->employee->name }}</bdi></p>
                    </div>

                    <div>
                        <x-forms.input-label>{{ __('general.request_date') }}</x-forms.input-label>
                        <p class="text-neutral-800"><bdi dir="ltr">{{ $decisionLeave->created_at->format('d-m-Y') }}</bdi></p>
                    </div>

                    <div>
                        <x-forms.input-label>{{ __('general.type') }}</x-forms.input-label>
                        <p class="text-neutral-800">{{ $decisionLeave->leaveType->name }}</p>
                    </div>

                    <div>
                        <x-forms.input-label>{{ __('general.alternative-employee') }}</x-forms.input-label>
                        <p class="text-neutral-800">
                            <bdi>{{ optional($decisionLeave->alternativeEmployee)->name ?? '—' }}</bdi>
                        </p>
                    </div>

                    @if($decisionLeave->type === 'day')
                        <div>
                            <x-forms.input-label>{{ __('general.during-period') }}</x-forms.input-label>
                            <p class="text-neutral-800"><bdi dir="ltr">{{ $decisionLeave->start_at->format('d-m-Y') }}</bdi> {{ __('leaves.range-separator') }} <bdi dir="ltr">{{ $decisionLeave->end_at->format('d-m-Y') }}</bdi></p>
                        </div>

                        <div>
                            <x-forms.input-label>{{ __('general.duration') }}</x-forms.input-label>
                            <p class="text-neutral-800">{{ $decisionLeave->duration }}</p>
                        </div>
                    @else

                        <div>
                            <x-forms.input-label>{{ __('general.during-period') }}</x-forms.input-label>
                            <div class="text-neutral-800 flex items-center gap-2">
                                <x-icons.calendar class="!size-4"/>
                                <bdi dir="ltr">{{ $decisionLeave->start_at->format('d-m-Y') }}</bdi>
                            </div>
                            <div class="text-neutral-800 flex items-center gap-2">
                                <x-icons.clock class="!size-4"/>
                                <span><bdi dir="ltr">{{ $decisionLeave->start_at->format('H:i') }}</bdi> {{ __('leaves.range-separator') }} <bdi dir="ltr">{{ $decisionLeave->end_at->format('H:i') }}</bdi></span>
                            </div>
                        </div>
                        <div>
                            <x-forms.input-label>{{ __('general.duration') }}</x-forms.input-label>
                            <p class="text-neutral-800">{{ $decisionLeave->duration }}</p>
                        </div>

                    @endif

                    <div class="col-span-full">
                        <x-forms.input-label>{{ __('general.reason') }}</x-forms.input-label>
                        <p class="text-neutral-800">{{ $decisionLeave->reason }}</p>
                    </div>
                </div>

                {{-- Decision Section --}}
                @if($decisionLeave->status === LeaveRequestStatus::Pending)
                    <div class="border-t pt-4 space-y-4">

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

                <x-modal.actions class="mt-4">

                    <x-primary-button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="submitDecision"
                        class="!w-auto">
                        {{ __('general.save') }}
                    </x-primary-button>

                    <x-primary-button type="button" :show-spinner="false" x-on:click="close()"
                                      class="!bg-secondary-light !w-auto">
                        {{ __('general.cancel') }}
                    </x-primary-button>
                </x-modal.actions>

            </form>
        @endif
    </x-modal.dialog>


</div>
