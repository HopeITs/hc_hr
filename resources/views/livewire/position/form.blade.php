<x-slot:title>
    {{ $title }}
</x-slot:title>

<div>
    <div class="flex items-center justify-between mb-6">
        <livewire:layout.breadcrumbs
            :title="__('general.positions')"
            :crumbs="[
        ['label' => __('general.positions') ],
                ['label' => $title],
            ]"

        />

    </div>

    <x-forms.form-body wire:submit.prevent="save">
        <x-forms.errors-validation-list/>
        <div class="grid gap-4 md:grid-cols-2">


            <div wire:key="title">
                <x-forms.input-label for="title">@lang('general.title') (EN)
                </x-forms.input-label>
                <x-forms.input type="text" wire:model="form.title_en" id="title"/>
                <x-error-span>{{ $errors->first('form.title_en') }}</x-error-span>
            </div>

            <div wire:key="title-ar">
                <x-forms.input-label for="title_ar">@lang('general.title') (AR)
                </x-forms.input-label>
                <x-forms.input type="text" wire:model="form.title_ar" id="title_ar"/>
                <x-error-span>{{ $errors->first('form.title_ar') }}</x-error-span>
            </div>
            <div class="md:col-span-1">
                <x-forms.input-label for="city" required>{{trans_choice('general.city',1)}}</x-forms.input-label>
                <x-forms.select2-input wire:model.live="form.city_id" id="city">
                    <option value="" class="text-secondary-light" @disabled($form->position !== null) @selected($form->position === null)>Select Any..</option>
                    @foreach($cities as $city)
                        <option value="{{$city->id}}">{{$city->name}}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('form.city_id') }}</x-error-span>
            </div>
            <div class="md:col-span-1">
                <x-forms.input-label for="program" required>{{trans_choice('general.program',1)}}</x-forms.input-label>
                <x-forms.select2-input wire:model="form.program_id" id="program">
                    <option class="text-secondary-light" @disabled($form->position !== null) @selected($form->position === null)>Select Any..</option>
                    @foreach($programs as $program)
                        <option value="{{$program->id}}">{{$program->name}}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('form.program_id') }}</x-error-span>
            </div>
            <div class="md:col-span-1">
                <x-forms.input-label for="department" required>{{trans_choice('general.department',1)}}</x-forms.input-label>
                <x-forms.select2-input wire:model="form.department_id" id="department">
                    <option class="text-secondary-light" @disabled($form->position !== null) @selected($form->position === null)>Select Any..</option>
                    @foreach($departments as $department)
                        <option value="{{$department->id}}">{{$department->name}}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('form.department_id') }}</x-error-span>
            </div>
            <div class="md:col-span-1">
                <x-forms.input-label for="job-level" required>{{trans_choice('general.job-level',1)}}</x-forms.input-label>
                <x-forms.select2-input wire:model="form.job_level_id" id="job-level">
                    <option class="text-secondary-light" @disabled($form->position !== null) @selected($form->position === null)>Select Any..</option>
                    @foreach($jobLevels as $level)
                        <option value="{{$level->id}}">{{$level->name}}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('form.job_level_id') }}</x-error-span>
            </div>

            <div class="md:col-span-1">
                <x-forms.input-label for="direct-manager">{{__('general.direct-manager')}}</x-forms.input-label>
                <x-forms.select2-input wire:model="form.direct_manager_id" id="direct-manager">
                    <option value="">Select Any..</option>
                    @foreach($positions as $position)
                        @php
                        $centerName = isset($position->center) ? $position->center->name . ' - ' .$position->center->city->name : __('general.flexible');
                        $programName = isset($position->program) ? $position->program->name : '-'
                        @endphp
                        <option value="{{$position->id}}">{{$position->title . " ( ".$programName.' - '.$centerName ." ) "}}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('form.direct_manager_id') }}</x-error-span>
            </div>
            <div class="md:col-span-1" wire:key="center-field-{{ $form->city_id ?: 'all' }}">
                <x-forms.input-label for="center">{{trans_choice('general.center',1)}}</x-forms.input-label>
                <x-forms.select2-input wire:model="form.center_id" id="center">
                    <option value="">@lang('general.flexible')</option>
                    @foreach($centers as $center)
                        <option value="{{$center->id}}">
                            {{ $center->city ? $center->name .' - ' . $center->city->name : $center->name }}
                        </option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('form.center_id') }}</x-error-span>
            </div>
            <div>
                <x-forms.input-label for="max_emp" required>@lang('general.max')</x-forms.input-label>
                <x-forms.input id="max_emp" type="number" min="0" wire:model.defer="form.max_emp"/>
                <x-error-span>{{ $errors->first('form.max_emp') }}</x-error-span>
            </div>

            <div class="col-span-2">
                <x-forms.input-label for="responsibilities" required>@lang('general.responsibilities')</x-forms.input-label>
                <x-forms.textarea id="responsibilities" rows="3" wire:model.defer="form.responsibilities"/>
                <x-error-span>{{ $errors->first('form.responsibilities') }}</x-error-span>
            </div>
            <div class="col-span-2">
                <x-forms.input-label for="qualifications" required>@lang('general.qualifications')</x-forms.input-label>
                <x-forms.textarea id="qualifications" rows="3" wire:model.defer="form.qualifications"/>
                <x-error-span>{{ $errors->first('form.qualifications') }}</x-error-span>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <x-primary-button type="submit" class="!w-auto">@lang('general.save')</x-primary-button>
            <x-primary-button :href="route('positions.index')" class="!bg-secondary-light !w-auto">@lang('general.cancel')</x-primary-button>
        </div>
    </x-forms.form-body>

</div>
