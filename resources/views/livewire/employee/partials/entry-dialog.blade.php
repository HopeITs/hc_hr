{{--
    The employee adds one entry to their own profile (meeting 19/11): saved at
    once, and HR of their city is told. The fields and rules are
    ProfileChangeRequests::rules(); the server re-checks everything. Closing
    with entries asks first (A5-M).
--}}
@php
    $entryTitle = $adding !== '' ? __('employees.profile.change.title', ['section' => __('employees.profile.change.sections.'.$adding)]) : '';
    $select = 'w-full min-h-11 rounded-control border border-line bg-card px-3 text-body    ';
@endphp
<x-modal.dialog id="entryModal" :title="$entryTitle" wire:model.defer="entryModal" close-action="requestClose" wire:ignore.self>
    @if ($entryModal)
        <form wire:submit.prevent="saveEntry" class="space-y-4" data-testid="entry-form">
            <p class="text-small text-ink-2">@lang('employees.profile.change.help')</p>

            <div class="grid gap-4 sm:grid-cols-2">
                @switch($adding)
                    @case('education')
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_name" required>@lang('general.name')</x-forms.input-label>
                            <x-forms.input id="p_name" wire:model="entry.name" />
                            <x-forms.input-error field="entry.name" input-id="p_name" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_specialization">@lang('general.specialization')</x-forms.input-label>
                            <x-forms.input id="p_specialization" wire:model="entry.specialization" />
                            <x-forms.input-error field="entry.specialization" input-id="p_specialization" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_degree" required>@lang('general.degree')</x-forms.input-label>
                            <select id="p_degree" wire:model="entry.degree" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\EducationType::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\EducationType::getLocalizedName($value) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.degree" input-id="p_degree" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_status" required>@lang('general.status')</x-forms.input-label>
                            <select id="p_status" wire:model="entry.status" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\EducationStatus::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\EducationStatus::getLocalizedName($value) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.status" input-id="p_status" />
                        </div>
                        @break

                    @case('course')
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_title" required>@lang('general.title')</x-forms.input-label>
                            <x-forms.input id="p_title" wire:model="entry.title" />
                            <x-forms.input-error field="entry.title" input-id="p_title" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_category" required>@lang('general.category')</x-forms.input-label>
                            <select id="p_category" wire:model="entry.category_id" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Models\CourseCategory::query()->pluck(transColumn('name'), 'id') as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.category_id" input-id="p_category" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_organization" required>@lang('general.organization')</x-forms.input-label>
                            <select id="p_organization" wire:model="entry.organization" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (['Hope Center' => __('employees.courses.organization-hope-center'), 'Personal' => __('employees.courses.organization-personal'), 'Other' => __('employees.courses.organization-other')] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.organization" input-id="p_organization" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_end" required>@lang('general.end_date')</x-forms.input-label>
                            <x-forms.input id="p_end" type="date" wire:model="entry.end_date" />
                            <x-forms.input-error field="entry.end_date" input-id="p_end" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_cstatus" required>@lang('general.status')</x-forms.input-label>
                            <select id="p_cstatus" wire:model="entry.status" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach ([1 => __('general.pending'), 2 => __('general.approved'), 3 => __('general.rejected'), 4 => __('general.in_progress'), 5 => __('general.finished')] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.status" input-id="p_cstatus" />
                        </div>
                        @break

                    @case('experience')
                        <div>
                            <x-forms.input-label for="p_job" required>@lang('general.job_title')</x-forms.input-label>
                            <x-forms.input id="p_job" wire:model="entry.job_title" />
                            <x-forms.input-error field="entry.job_title" input-id="p_job" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_company" required>@lang('general.company')</x-forms.input-label>
                            <x-forms.input id="p_company" wire:model="entry.company" />
                            <x-forms.input-error field="entry.company" input-id="p_company" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_start" required>@lang('general.start_date')</x-forms.input-label>
                            <x-forms.input id="p_start" type="date" wire:model="entry.start_date" />
                            <x-forms.input-error field="entry.start_date" input-id="p_start" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_xend">@lang('general.end_date')</x-forms.input-label>
                            <x-forms.input id="p_xend" type="date" wire:model="entry.end_date" />
                            <x-forms.input-error field="entry.end_date" input-id="p_xend" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_place" required>@lang('general.place')</x-forms.input-label>
                            <select id="p_place" wire:model="entry.place" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\ContractPlace::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\ContractPlace::getLocalizedName($value)['value'] }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.place" input-id="p_place" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_type" required>@lang('general.type')</x-forms.input-label>
                            <select id="p_type" wire:model="entry.type" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\ContractType::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\ContractType::getLocalizedName($value)['value'] }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.type" input-id="p_type" />
                        </div>
                        @break

                    @case('relative')
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_rname" required>@lang('general.name')</x-forms.input-label>
                            <x-forms.input id="p_rname" wire:model="entry.name" />
                            <x-forms.input-error field="entry.name" input-id="p_rname" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_relation" required>@lang('general.relation')</x-forms.input-label>
                            <select id="p_relation" wire:model="entry.relation" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\KinshipType::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\KinshipType::getLocalizedName($value) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="entry.relation" input-id="p_relation" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_rphone">@lang('general.phone')</x-forms.input-label>
                            <x-forms.input id="p_rphone" type="tel" dir="ltr" wire:model="entry.phone" />
                            <x-forms.input-error field="entry.phone" input-id="p_rphone" />
                        </div>
                        @break
                @endswitch

                @if (in_array($adding, ['education', 'course', 'experience', 'photo'], true))
                    <div class="sm:col-span-2">
                        <x-forms.input-label for="p_file" :required="$adding === 'photo'">
                            {{ $adding === 'photo' ? __('employees.profile.change.photo-file') : __('employees.profile.change.file') }}
                        </x-forms.input-label>
                        <input id="p_file" type="file" wire:model="entryFile"
                               accept="{{ $adding === 'photo' ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,application/pdf' }}"
                               class="block w-full text-small file:me-3 file:min-h-11 file:rounded-control file:border-0 file:bg-surface file:px-4 file:font-semibold">
                        <x-forms.input-error field="entryFile" input-id="p_file" />
                    </div>
                @endif
            </div>

            <x-modal.actions>
                <x-primary-button type="submit" wire:target="saveEntry,entryFile" class="!w-auto">@lang('general.save')</x-primary-button>
                <x-button variant="quiet" :loading="false" x-on:click="close()">@lang('general.cancel')</x-button>
            </x-modal.actions>
        </form>
    @endif
</x-modal.dialog>
