@php
    use App\Enums\EducationType;
    use App\Enums\EducationStatus;
    use Illuminate\Support\Facades\DB;

    $fields = DB::table('education_fields')->orderBy('name')->pluck('name')->toArray();
@endphp

<form wire:submit.prevent="saveEducation" class="space-y-6">
    @foreach ($educationForm->educations as $index => $education)
        <input type="hidden" wire:model="educationForm.educations.{{ $index }}.id">
        <div wire:key="education-{{ $index }}" class="relative border-b pb-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2 flex justify-end">
                    <x-primary-button type="button" :showSpinner="false"
                        x-on:click="if ({{ filled($education['id'] ?? null) ? 'await $confirm()' : 'true' }}) $wire.removeEducation({{ $index }}, {{ $education['id'] ?? 'null' }})"
                        :title="__('general.delete')" :aria-label="__('general.delete')">
                        <x-icons.trash />
                    </x-primary-button>
                </div>

                <div>
                    <x-forms.input-label
                        for="education_field_{{ $index }}" required>@lang('general.field-of-study')</x-forms.input-label>
                    <x-forms.select2-input id="education_field_{{ $index }}"
                        wire:model.defer="educationForm.educations.{{ $index }}.name">
                        <option value="">-- @lang('general.select') --</option>
                        @foreach ($fields as $field)
                            <option value="{{ $field }}">{{ $field }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("educationForm.educations.$index.name") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label
                        for="education_specialization_{{ $index }}">@lang('general.specialization')</x-forms.input-label>
                    <x-forms.input id="education_specialization_{{ $index }}" type="text"
                        wire:model.defer="educationForm.educations.{{ $index }}.specialization" />
                    <x-error-span>{{ $errors->first("educationForm.educations.$index.specialization") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label
                        for="education_degree_{{ $index }}" required>@lang('general.degree')</x-forms.input-label>
                    <x-forms.select2-input id="education_degree_{{ $index }}"
                        wire:model.defer="educationForm.educations.{{ $index }}.degree">
                        <option value="">-- @lang('general.select') --</option>
                        @foreach (EducationType::getValues() as $value)
                            <option value="{{ $value }}">{{ EducationType::getLocalizedName($value) }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("educationForm.educations.$index.degree") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label
                        for="education_status_{{ $index }}" required>@lang('general.status')</x-forms.input-label>
                    <x-forms.select2-input id="education_status_{{ $index }}"
                        wire:model.defer="educationForm.educations.{{ $index }}.status">
                        @foreach (EducationStatus::getValues() as $value)
                            <option value="{{ $value }}">{{ EducationStatus::getLocalizedName($value) }}
                            </option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("educationForm.educations.$index.status") }}</x-error-span>
                </div>
                <div class="md:col-span-2">
                    <x-forms.input-label
                        for="education_notes_{{ $index }}">@lang('general.notes')</x-forms.input-label>
                    <x-forms.textarea id="education_notes_{{ $index }}"
                        wire:model.defer="educationForm.educations.{{ $index }}.notes" rows="3" />
                    <x-error-span>{{ $errors->first("educationForm.educations.$index.notes") }}</x-error-span>
                </div>
                <div>
                    <x-forms.input-label
                        for="edu_attachment_{{ $index }}">@lang('general.attachment')</x-forms.input-label>
                    <x-forms.file-input wire:model="educationForm.educations.{{ $index }}.edu_attachment"
                        name="educationForm.educations.{{ $index }}.edu_attachment" preview-type="pdf"
                        :old-file="$education['edu_attachment']" :file-name="$education['file_name']" accept=".pdf" />
                </div>



            </div>
        </div>
    @endforeach

    <div class="flex justify-center">
        <x-primary-button type="button" :showSpinner="false" wire:click="addEducation">
            + @lang('general.add-new')
        </x-primary-button>
    </div>

    <x-forms.dirty-gate watch="educationForm" :active="! $isCreateMode"
        class="flex justify-end space-x-2 pt-4 mt-6 border-t">
        <x-primary-button type="submit" class="!w-auto" x-bind:disabled="! dirty">@lang('general.save-education')</x-primary-button>
    </x-forms.dirty-gate>
</form>
