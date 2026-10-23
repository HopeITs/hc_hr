@php
    use App\Enums\LeaveTypeCases;
use App\Enums\LeaveRequestStatus;
@endphp
<x-slot:title>
    {{ __('general.balance') }}
</x-slot:title>

<div>
    <div class="flex justify-between items-center mb-6">
        <livewire:layout.breadcrumbs
            :title="__('general.my-leaves')"
            :crumbs="[
                ['label' => __('general.holidays-and-leaves')],

            ]"
            :showButton="true"
        />
        @activerole('HR', 'super-admin')
            <div class="flex items-center gap-2">
                <x-excel-export-button wire:click="$dispatchTo('employee.leave.emp-leave-balance-table', 'exportExcel')" />
            </div>
        @endactiverole
    </div>


    {{-- Table --}}
    <div class="mt-4">
        <livewire:employee.leave.emp-leave-balance-table key="table-balance"/>
    </div>


    <x-modal.dialog max-width="6xl" id="showModal" :title="$modalTitle" wire:model.defer="showModal" close-action="requestClose" wire:ignore.self>

        <form wire:submit.prevent="save" class="space-y-4 sheet:flex-1 sheet:flex sheet:flex-col">
            <x-forms.errors-validation-list/>
            <div class="grid md:grid-cols-3 gap-4">
                <div class="md:col-span-1">
                    <x-forms.input-label >{{__('general.name')}}</x-forms.input-label>
                    <p><bdi>{{$form->balance?->contract->employee->name}}</bdi></p>
                </div>
                <div class="md:col-span-2">
                    <x-forms.input-label >{{__('general.years-of-service')}}</x-forms.input-label>
                    <p>{{$form->balance?->contract->employee->years_of_service}}</p>
                </div>
                <div class="md:col-span-1">
                    <x-forms.input-label >{{__('general.deserved')}}</x-forms.input-label>
                    <p>{!! $form->balance?->deserved_formatted!!}</p>
                </div>
                <div class="md:col-span-1">
                    <x-forms.input-label >{{__('general.used')}}</x-forms.input-label>
                    <p>{!! $form->balance?->used_formatted!!}</p>
                </div>
                <div class="md:col-span-1">
                    <x-forms.input-label >{{__('general.remaining')}}</x-forms.input-label>
                    <p>{!!  $form->balance?->remaining!!}</p>
                </div>

                <div>
                    <x-forms.radio-group :legend="__('general.operation')" error="form.operation" required>
                        <x-forms.radio-input value="sum"
                                             wire:model="form.operation">{{__('general.sum')}}</x-forms.radio-input>
                        <x-forms.radio-input value="sub"
                                             wire:model="form.operation">{{__('general.sub')}}</x-forms.radio-input>
                    </x-forms.radio-group>
                </div>
                <div>
                    <x-forms.radio-group :legend="__('general.unit')" error="form.unit" required>
                        <x-forms.radio-input value="hour"
                                             wire:model="form.unit">{{ __('leaves.unit.hour') }}</x-forms.radio-input>
                        <x-forms.radio-input value="day"
                                             wire:model="form.unit">{{ __('leaves.unit.day') }}</x-forms.radio-input>
                    </x-forms.radio-group>
                </div>
                <div>
                    <x-forms.input-label for="amount" required>@lang('general.amount')</x-forms.input-label>
                    <x-forms.input id="amount" wire:model="form.amount" type="numeric"/>
                    <x-error-span>{{ $errors->first('form.amount') }}</x-error-span>
                </div>
                <div>
                    @if($form->balance && \Illuminate\Support\Facades\Gate::allows('create', [\App\Models\Badge::class, $form->balance->contract->employee]))
                        <x-forms.checkbox-input type="checkbox"
                                                wire:model.live="form.add_badge">@lang('general.add-as-badge')</x-forms.checkbox-input>
                        <x-error-span>{{ $errors->first('form.add_badge') }}</x-error-span>
                    @endif
                </div>
                @if($form->add_badge && $form->balance && \Illuminate\Support\Facades\Gate::allows('create', [\App\Models\Badge::class, $form->balance->contract->employee]))
                    <div class="col-span-2">
                        <x-forms.input-label for="note">@lang('general.note')</x-forms.input-label>
                        <x-forms.input id="note" wire:model="form.notes" :required="$form->add_badge"/>
                        <x-error-span>{{ $errors->first('form.notes') }}</x-error-span>
                    </div>
                @endif
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
</div>
