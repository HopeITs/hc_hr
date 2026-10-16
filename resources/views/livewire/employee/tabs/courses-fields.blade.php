<form wire:submit.prevent="saveCourses" class="space-y-6">
    @php
        $courseCategories = \App\Models\CourseCategory::pluck('name_en', 'id')->toArray();
        $organizationOptions = [
            'Hope Center' => 'Hope Center',
            'Personal' => 'Personal',
            'Other' => 'Other',
        ];
        $placeOptions = [
            1 => __('general.online'),
            2 => __('general.onsite'),
        ];
        $statusOptions = [
            1 => __('general.pending'),
            2 => __('general.approved'),
            3 => __('general.rejected'),
            4 => __('general.in_progress'),
            5 => __('general.finished'),
        ];
    @endphp

    @foreach ($courseForm->courses as $index => $course)
        <input type="hidden" wire:model="courseForm.courses.{{ $index }}.id">

        <div wire:key="course-{{ $index }}" class="relative border-b pb-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="md:col-span-2 lg:col-span-3 flex justify-end">
                    <x-primary-button
                        type="button"
                        :showSpinner="false"
                        x-on:click="if ({{ filled($course['id'] ?? null) ? 'await $confirm()' : 'true' }}) $wire.removeCourse({{ $index }}, {{ $course['id'] ?? 'null' }})"
                        title="@lang('general.remove')">
                        <x-icons.trash />
                    </x-primary-button>
                </div>

                <div>
                    <x-forms.input-label for="course_category_{{ $index }}" required>@lang('general.category')</x-forms.input-label>
                    <x-forms.select2-input id="course_category_{{ $index }}" wire:model.defer="courseForm.courses.{{ $index }}.category_id">
                        <option value="">-- @lang('general.select') --</option>
                        @foreach ($courseCategories as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.category_id") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_title_{{ $index }}" required>@lang('general.title')</x-forms.input-label>
                    <x-forms.input id="course_title_{{ $index }}" type="text" wire:model.defer="courseForm.courses.{{ $index }}.title" />
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.title") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_org_{{ $index }}" required>@lang('general.organization')</x-forms.input-label>
                    <x-forms.select2-input id="course_org_{{ $index }}" wire:model.defer="courseForm.courses.{{ $index }}.organization">
                        <option value="">-- @lang('general.select') --</option>
                        @foreach ($organizationOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.organization") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_place_{{ $index }}">@lang('general.place')</x-forms.input-label>
                    <x-forms.select2-input id="course_place_{{ $index }}" wire:model.defer="courseForm.courses.{{ $index }}.place">
                        <option value="">-- @lang('general.select') --</option>
                        @foreach ($placeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.place") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_granted_by_{{ $index }}">@lang('general.granted_by')</x-forms.input-label>
                    <x-forms.input id="course_granted_by_{{ $index }}" type="text" wire:model.defer="courseForm.courses.{{ $index }}.granted_by" />
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.granted_by") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_end_date_{{ $index }}" required>@lang('general.end_date')</x-forms.input-label>
                    <x-forms.input id="course_end_date_{{ $index }}" type="date" wire:model.defer="courseForm.courses.{{ $index }}.end_date" />
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.end_date") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_duration_{{ $index }}">
                        @lang('general.duration') (@lang('general.e.g_20_hours_2_weeks'))
                    </x-forms.input-label>
                    <x-forms.input id="course_duration_{{ $index }}" type="text" wire:model.defer="courseForm.courses.{{ $index }}.duration" />
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.duration") }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="course_status_{{ $index }}" required>@lang('general.status')</x-forms.input-label>
                    <x-forms.select2-input id="course_status_{{ $index }}" wire:model.defer="courseForm.courses.{{ $index }}.status">
                        <option value="">-- @lang('general.select') --</option>
                        @foreach ($statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.status") }}</x-error-span>
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <x-forms.input-label for="course_certificate_yes_{{ $index }}">@lang('general.has_certificate')</x-forms.input-label>
                    <div class="flex space-x-6 mt-2">
                        <x-forms.radio-input id="course_certificate_yes_{{ $index }}"
                            name="course_certificate_{{ $index }}"
                            wire:model.defer="courseForm.courses.{{ $index }}.has_certificate" value="1">
                            @lang('general.yes')
                        </x-forms.radio-input>

                        <x-forms.radio-input id="course_certificate_no_{{ $index }}"
                            name="course_certificate_{{ $index }}"
                            wire:model.defer="courseForm.courses.{{ $index }}.has_certificate" value="0">
                            @lang('general.no')
                        </x-forms.radio-input>
                    </div>
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.has_certificate") }}</x-error-span>
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <x-forms.input-label for="course_notes_{{ $index }}">@lang('general.notes')</x-forms.input-label>
                    <x-forms.textarea id="course_notes_{{ $index }}" wire:model.defer="courseForm.courses.{{ $index }}.notes" rows="3" />
                    <x-error-span>{{ $errors->first("courseForm.courses.$index.notes") }}</x-error-span>
                </div>

                <div class="md:col-span-2 lg:col-span-3">
                    <x-forms.input-label for="course_attachment_{{ $index }}">@lang('general.certificate_attachment')</x-forms.input-label>
                    <x-forms.file-input
                        wire:model="courseForm.courses.{{ $index }}.course_attachment"
                        name="courseForm.courses.{{ $index }}.course_attachment"
                        previewType="pdf"
                        :oldFile="$course['course_attachment'] ?? null"
                        :fileName="$course['file_name'] ?? null"
                        accept=".pdf, .jpg, .jpeg, .png" />
                </div>
            </div>
        </div>
    @endforeach

    <div class="flex justify-center">
        <x-primary-button type="button" :showSpinner="false" wire:click="addCourse">
            + @lang('general.add-new')
        </x-primary-button>
    </div>

    <x-forms.dirty-gate watch="courseForm" :active="! $isCreateMode"
        class="flex justify-end space-x-2 pt-4 mt-6 border-t">
        <x-primary-button type="submit" class="!w-auto" wire:target="saveCourses" x-bind:disabled="! dirty">
            @lang('general.save-courses')
        </x-primary-button>
    </x-forms.dirty-gate>
</form>
