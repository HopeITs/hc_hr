{{--
    D1 (profile-contract.md §5.1): the employee's proposal for one section,
    sent to HR. The fields and rules are ProfileChangeRequests::rules(); the
    server re-checks everything. Closing with entries asks first (A5-M).
--}}
@php
    $proposalTitle = $proposing !== '' ? __('employees.profile.change.title', ['section' => __('employees.profile.change.sections.'.$proposing)]) : '';
    $select = 'w-full min-h-11 rounded-control border border-line bg-card px-3 text-body    ';
@endphp
<x-modal.dialog id="proposalModal" :title="$proposalTitle" wire:model.defer="proposalModal" close-action="requestClose" wire:ignore.self>
    @if ($proposalModal)
        <form wire:submit.prevent="submitProposal" class="space-y-4" data-testid="proposal-form">
            <p class="text-small text-ink-2">@lang('employees.profile.change.help')</p>

            <div class="grid gap-4 sm:grid-cols-2">
                @switch($proposing)
                    @case('education')
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_name" required>@lang('general.name')</x-forms.input-label>
                            <x-forms.input id="p_name" wire:model="proposal.name" />
                            <x-forms.input-error field="proposal.name" input-id="p_name" />
                        </div>
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_specialization">@lang('general.specialization')</x-forms.input-label>
                            <x-forms.input id="p_specialization" wire:model="proposal.specialization" />
                            <x-forms.input-error field="proposal.specialization" input-id="p_specialization" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_degree" required>@lang('general.degree')</x-forms.input-label>
                            <select id="p_degree" wire:model="proposal.degree" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\EducationType::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\EducationType::getLocalizedName($value) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.degree" input-id="p_degree" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_status" required>@lang('general.status')</x-forms.input-label>
                            <select id="p_status" wire:model="proposal.status" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\EducationStatus::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\EducationStatus::getLocalizedName($value) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.status" input-id="p_status" />
                        </div>
                        @break

                    @case('course')
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_title" required>@lang('general.title')</x-forms.input-label>
                            <x-forms.input id="p_title" wire:model="proposal.title" />
                            <x-forms.input-error field="proposal.title" input-id="p_title" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_category" required>@lang('general.category')</x-forms.input-label>
                            <select id="p_category" wire:model="proposal.category_id" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Models\CourseCategory::query()->pluck(transColumn('name'), 'id') as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.category_id" input-id="p_category" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_organization" required>@lang('general.organization')</x-forms.input-label>
                            <select id="p_organization" wire:model="proposal.organization" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (['Hope Center' => __('employees.courses.organization-hope-center'), 'Personal' => __('employees.courses.organization-personal'), 'Other' => __('employees.courses.organization-other')] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.organization" input-id="p_organization" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_end" required>@lang('general.end_date')</x-forms.input-label>
                            <x-forms.input id="p_end" type="date" wire:model="proposal.end_date" />
                            <x-forms.input-error field="proposal.end_date" input-id="p_end" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_cstatus" required>@lang('general.status')</x-forms.input-label>
                            <select id="p_cstatus" wire:model="proposal.status" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach ([1 => __('general.pending'), 2 => __('general.approved'), 3 => __('general.rejected'), 4 => __('general.in_progress'), 5 => __('general.finished')] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.status" input-id="p_cstatus" />
                        </div>
                        @break

                    @case('experience')
                        <div>
                            <x-forms.input-label for="p_job" required>@lang('general.job_title')</x-forms.input-label>
                            <x-forms.input id="p_job" wire:model="proposal.job_title" />
                            <x-forms.input-error field="proposal.job_title" input-id="p_job" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_company" required>@lang('general.company')</x-forms.input-label>
                            <x-forms.input id="p_company" wire:model="proposal.company" />
                            <x-forms.input-error field="proposal.company" input-id="p_company" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_start" required>@lang('general.start_date')</x-forms.input-label>
                            <x-forms.input id="p_start" type="date" wire:model="proposal.start_date" />
                            <x-forms.input-error field="proposal.start_date" input-id="p_start" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_xend">@lang('general.end_date')</x-forms.input-label>
                            <x-forms.input id="p_xend" type="date" wire:model="proposal.end_date" />
                            <x-forms.input-error field="proposal.end_date" input-id="p_xend" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_place" required>@lang('general.place')</x-forms.input-label>
                            <select id="p_place" wire:model="proposal.place" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\ContractPlace::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\ContractPlace::getLocalizedName($value)['value'] }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.place" input-id="p_place" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_type" required>@lang('general.type')</x-forms.input-label>
                            <select id="p_type" wire:model="proposal.type" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\ContractType::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\ContractType::getLocalizedName($value)['value'] }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.type" input-id="p_type" />
                        </div>
                        @break

                    @case('relative')
                        <div class="sm:col-span-2">
                            <x-forms.input-label for="p_rname" required>@lang('general.name')</x-forms.input-label>
                            <x-forms.input id="p_rname" wire:model="proposal.name" />
                            <x-forms.input-error field="proposal.name" input-id="p_rname" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_relation" required>@lang('general.relation')</x-forms.input-label>
                            <select id="p_relation" wire:model="proposal.relation" class="{{ $select }}">
                                <option value="">-- @lang('general.select') --</option>
                                @foreach (\App\Enums\KinshipType::getValues() as $value)
                                    <option value="{{ $value }}">{{ \App\Enums\KinshipType::getLocalizedName($value) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="proposal.relation" input-id="p_relation" />
                        </div>
                        <div>
                            <x-forms.input-label for="p_rphone">@lang('general.phone')</x-forms.input-label>
                            <x-forms.input id="p_rphone" type="tel" dir="ltr" wire:model="proposal.phone" />
                            <x-forms.input-error field="proposal.phone" input-id="p_rphone" />
                        </div>
                        @break
                @endswitch

                @if (in_array($proposing, ['education', 'course', 'experience', 'photo'], true))
                    <div class="sm:col-span-2">
                        <x-forms.input-label for="p_file" :required="$proposing === 'photo'">
                            {{ $proposing === 'photo' ? __('employees.profile.change.photo-file') : __('employees.profile.change.file') }}
                        </x-forms.input-label>
                        <input id="p_file" type="file" wire:model="proposalFile"
                               accept="{{ $proposing === 'photo' ? 'image/jpeg,image/png,image/webp' : 'image/jpeg,image/png,application/pdf' }}"
                               class="block w-full text-small file:me-3 file:min-h-11 file:rounded-control file:border-0 file:bg-surface file:px-4 file:font-semibold">
                        <x-forms.input-error field="proposalFile" input-id="p_file" />
                    </div>
                @endif
            </div>

            <x-modal.actions>
                <x-primary-button type="submit" wire:target="submitProposal,proposalFile" class="!w-auto">@lang('employees.profile.change.submit')</x-primary-button>
                <x-button variant="quiet" :loading="false" x-on:click="close()">@lang('general.cancel')</x-button>
            </x-modal.actions>
        </form>
    @endif
</x-modal.dialog>
