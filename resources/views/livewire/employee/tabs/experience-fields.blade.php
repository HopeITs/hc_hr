<form wire:submit.prevent="saveExperience" class="space-y-6">
    @foreach ($experienceForm->experiences as $index => $experience)
        <input type="hidden" wire:model="experienceForm.experiences.{{ $index }}.id">

        <div wire:key="experience-{{ $index }}" class="relative border-b pb-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2 flex justify-end">
                    <x-primary-button type="button"
                        x-on:click="if ({{ filled($experience['id'] ?? null) ? 'await $confirm()' : 'true' }}) $wire.removeExperience({{ $index }}, {{ $experience['id'] ?? 'null' }})"
                        title="@lang('general.remove')">
                        <x-icons.trash />
                    </x-primary-button>
                </div>

                <div>
                    <x-forms.input-label for="job_title_{{ $index }}" required>@lang('general.job_title')</x-forms.input-label>
                    <x-forms.input id="job_title_{{ $index }}" type="text"
                        wire:model.defer="experienceForm.experiences.{{ $index }}.job_title" />
                    <x-error-span>{{ $errors->first("experienceForm.experiences.$index.job_title") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="company_{{ $index }}" required>@lang('general.company')</x-forms.input-label>
                    <x-forms.input id="company_{{ $index }}" type="text"
                        wire:model.defer="experienceForm.experiences.{{ $index }}.company" />
                    <x-error-span>{{ $errors->first("experienceForm.experiences.$index.company") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="start_date_{{ $index }}" required>@lang('general.start_date')</x-forms.input-label>
                    <x-forms.date-input id="start_date_{{ $index }}"
                        wire:model.defer="experienceForm.experiences.{{ $index }}.start_date" />
                    <x-error-span>{{ $errors->first("experienceForm.experiences.$index.start_date") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="end_date_{{ $index }}">@lang('general.end_date')</x-forms.input-label>
                    <x-forms.date-input id="end_date_{{ $index }}"
                        wire:model.defer="experienceForm.experiences.{{ $index }}.end_date" />
                    <x-error-span>{{ $errors->first("experienceForm.experiences.$index.end_date") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="place_{{ $index }}">@lang('general.place')</x-forms.input-label>
                    <x-forms.select2-input id="place_{{ $index }}"
                                          wire:model.defer="experienceForm.experiences.{{ $index }}.place" >
                        @foreach(\App\Enums\ContractPlace::getValues() as $key => $value)
                            <option value="{{$value}}">{{\App\Enums\ContractPlace::getLocalizedName($value)['value']}}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("experienceForm.experiences.$index.place") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="type_{{ $index }}">@lang('general.type')</x-forms.input-label>
                    <x-forms.select2-input id="type_{{ $index }}"
                                          wire:model.defer="experienceForm.experiences.{{ $index }}.type" >
                        @foreach(\App\Enums\ContractType::getValues() as $key => $value)
                            <option value="{{$value}}">{{\App\Enums\ContractType::getLocalizedName($value)['value']}}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("experienceForm.experiences.$index.type") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="exp_attachment_{{ $index }}">@lang('general.attachment')</x-forms.input-label>
                    <x-forms.file-input wire:model="experienceForm.experiences.{{ $index }}.exp_attachment"
                                        name="experienceForm.experiences.{{ $index }}.exp_attachment"
                                        preview-type="pdf"
                                        :old-file="$experience['exp_attachment']"
                                        :file-name="$experience['file_name']"
                                        accept=".pdf"/>
                </div>
            </div>
        </div>
    @endforeach

    <div class="flex justify-center">
        <x-primary-button type="button" wire:click="addExperience">
            + @lang('general.add-new')
        </x-primary-button>
    </div>

    <x-forms.dirty-gate watch="experienceForm" :active="! $isCreateMode"
        class="flex justify-end space-x-2 pt-4 mt-6 border-t">
        <x-primary-button type="submit" class="!w-auto" x-bind:disabled="! dirty">@lang('general.save-experience')</x-primary-button>
    </x-forms.dirty-gate>
</form>
