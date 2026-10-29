@php
    use App\Enums\MaritalStatus;
    use App\Enums\Nationality;
@endphp

@if ($isCreateMode && $showDraftPrompt)
    <div class="flex flex-col space-y-6" data-tour="employee.draft">
        <x-heading-label>@lang('general.assign-position-draft')</x-heading-label>
        <p class="text-sm text-neutral-500">@lang('general.assign-position-draft-description')</p>

        <div wire:key="draft-city-{{ $this->canPickAnyDraftCity() ? 'open' : 'locked' }}">
            <x-forms.input-label for="draft_city_id">{{ trans_choice('general.city', 1) }}</x-forms.input-label>
            @if ($this->canPickAnyDraftCity())
                {{-- A nationwide role (HR-SYR, super-admin, general-manager) is not
                     limited to the employee's own city elsewhere in the app either. --}}
                <x-forms.select2-input wire:model.live="draftCityId" id="draft_city_id">
                    <option value="">{{ __('general.select') }}</option>
                    @foreach ($this->citiesForDraft() as $cityId => $cityName)
                        <option value="{{ $cityId }}">{{ $cityName }}</option>
                    @endforeach
                </x-forms.select2-input>
            @else
                <x-forms.input type="text" id="draft_city_id" :value="$infoForm->employee?->city?->name" disabled />
            @endif
        </div>

        {{--
            select2-input wraps its real <select> in wire:ignore so Alpine's
            own entangle can own the selected VALUE without Livewire's morph
            fighting it — but that also means Livewire never touches its
            <option> list on a normal re-render. Program's options depend on
            draftCityId and Position's depend on draftProgramId, so each
            wrapper carries a wire:key tied to the value it depends on: when
            that value changes, Livewire treats the keyed element as a brand
            new node instead of patching it, which tears down and remounts
            the whole select2 instance with the fresh <option> list baked in.
        --}}
        <div wire:key="draft-program-wrap-{{ $draftCityId }}">
            <x-forms.input-label for="draft_program_id">{{ trans_choice('general.program', 1) }}</x-forms.input-label>
            <x-forms.select2-input wire:model.live="draftProgramId" id="draft_program_id">
                <option value="">{{ __('general.select') }}</option>
                @foreach ($this->programOptionsForDraft() as $programId => $programName)
                    <option value="{{ $programId }}">{{ $programName }}</option>
                @endforeach
            </x-forms.select2-input>
        </div>

        <div wire:key="draft-position-wrap-{{ $draftProgramId }}">
            <x-forms.input-label for="draft_position_id">@lang('general.position')</x-forms.input-label>
            <x-forms.select2-input wire:model="draftForm.position_id" id="draft_position_id">
                <option value="">{{ __('general.select') }}</option>
                @foreach ($this->positionOptionsForDraft() as $positionId => $positionTitle)
                    <option value="{{ $positionId }}">{{ $positionTitle }}</option>
                @endforeach
            </x-forms.select2-input>
            <x-error-span>{{ $errors->first('draftForm.position_id') }}</x-error-span>
        </div>

        <div class="flex justify-end space-x-2 m1-action-bar">
            <x-primary-button type="button" wire:click="createDraftAndContinue" class="!w-auto">
                @lang('general.create-draft-and-continue')
            </x-primary-button>
            <x-secondary-button type="button" wire:click="skipDraft" class="!w-auto">
                @lang('general.skip-for-now')
            </x-secondary-button>
        </div>
    </div>
@else
    <form wire:submit.prevent="saveInformation" class="flex flex-col space-y-6">
        <div class="" data-tour="employee.photo">
            <x-forms.input-label for="photo">@lang('general.photo')</x-forms.input-label>
            <x-forms.file-input wire:model.live="infoForm.photo" id="photo" name="infoForm.photo"
                preview-type="avatar" accept="image/*" :old-file="$infoForm->photo" />
        </div>

        <div class="grid pb-8 mb-8 border-b md:grid-cols-2 gap-x-6 gap-y-3">
            <x-heading-label class="col-span-full">@lang('general.general_info')</x-heading-label>
            <div class="grid md:grid-cols-4 col-span-full gap-x-6 gap-y-3">
                @foreach (config('app.supported_locales') as $locale)
                    <div wire:key="emp-first-name-{{ $locale }}">
                        <x-forms.input-label for="first_name_{{ $locale }}" required>@lang('general.first_name')
                            ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="infoForm.first_name_{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                            id="first_name_{{ $locale }}" :examples="__('examples.first_name', [], $locale)"
                            :hint="__('examples.hints.name')" :hint-always="false" />
                        <x-error-span>{{ $errors->first('infoForm.first_name_' . $locale) }}</x-error-span>
                    </div>

                    <div wire:key="emp-last-name-{{ $locale }}">
                        <x-forms.input-label for="last_name_{{ $locale }}" required>@lang('general.last_name')
                            ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="infoForm.last_name_{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                            id="last_name_{{ $locale }}" :examples="__('examples.last_name', [], $locale)"
                            :hint="__('examples.hints.name')" :hint-always="false" />
                        <x-error-span>{{ $errors->first('infoForm.last_name_' . $locale) }}</x-error-span>
                    </div>
                @endforeach
            </div>


            <div class="grid md:grid-cols-2 gap-x-6">
                <div wire:key="emp-actual-dob">
                    <x-forms.input-label for="actual_dob">@lang('general.actual-dob')</x-forms.input-label>
                    <x-forms.date-input wire:model="infoForm.actual_dob" id="actual_dob" />
                    <x-error-span>{{ $errors->first('infoForm.actual_dob') }}</x-error-span>
                </div>
                <div wire:key="emp-city">
                    <x-forms.input-label for="city_id" required>@lang('general.current-residence')</x-forms.input-label>
                    <x-forms.select2-input wire:model="infoForm.city_id" id="city_id">
                        <option value="">{{ __('general.unknown') }}</option>
                        @foreach ($cities as $cityId => $city)
                            <option value="{{ $cityId }}">{{ $city }}</option>
                        @endforeach
                    </x-forms.select2-input>
                    <x-error-span>{{ $errors->first('infoForm.city_id') }}</x-error-span>
                </div>
            </div>

            {{--
            A radio group has no single input to point a `for` at, so the group
            is named through aria-labelledby instead of a dangling label.
        --}}
            <div class="grid md:grid-cols-2 gap-x-6">
                <div wire:key="emp-gender" role="radiogroup" aria-labelledby="gender-label">
                    <x-forms.input-label id="gender-label" required>@lang('general.gender')</x-forms.input-label>
                    <div class="flex mt-2 space-x-4">
                        <x-forms.radio-input id="gender_male" name="gender" value="1"
                            wire:model="infoForm.gender">@lang('general.male')</x-forms.radio-input>
                        <x-forms.radio-input id="gender_female" name="gender" value="2"
                            wire:model="infoForm.gender">@lang('general.female')</x-forms.radio-input>
                    </div>
                    <x-error-span>{{ $errors->first('infoForm.gender') }}</x-error-span>
                </div>
                <div wire:key="emp-joined-at">
                    <x-forms.input-label for="joined_at">@lang('general.join-at')</x-forms.input-label>
                    <x-forms.date-input wire:model="infoForm.joined_at" id="joined_at" />
                    <x-error-span>{{ $errors->first('infoForm.joined_at') }}</x-error-span>
                </div>
            </div>

            <div wire:key="emp-marital_status">
                <x-forms.input-label for="marital_status" required>@lang('general.marital_status')</x-forms.input-label>
                <x-forms.select2-input wire:model="infoForm.marital_status" id="marital_status">
                    @foreach (MaritalStatus::getValues() as $statusValue)
                        @php $status = MaritalStatus::getLocalizedName($statusValue); @endphp
                        <option value="{{ $statusValue }}">{{ $status['value'] }}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('infoForm.marital_status') }}</x-error-span>
            </div>

            <div wire:key="emp-children">
                <x-forms.input-label for="children_count">@lang('general.children')</x-forms.input-label>
                <x-forms.input type="number" wire:model="infoForm.children" id="children_count" />
                <x-error-span>{{ $errors->first('infoForm.children') }}</x-error-span>
            </div>



            {{-- Own row so the EN/AR address pair stays side by side. --}}
            <div class="grid md:grid-cols-2 col-span-full gap-x-6 gap-y-3">
                @foreach (config('app.supported_locales') as $locale)
                    <div wire:key="emp-address-{{ $locale }}">
                        <x-forms.input-label for="address_{{ $locale }}">@lang('general.address')
                            ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="infoForm.address_{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                            id="address_{{ $locale }}" />
                        <x-error-span>{{ $errors->first('infoForm.address_' . $locale) }}</x-error-span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid pb-8 mb-8 border-b md:grid-cols-2 gap-x-6 gap-y-3">
            <x-heading-label class="col-span-full">@lang('general.address_info')</x-heading-label>
            <div class="md:grid md:grid-cols-2 gap-x-6 gap-y-3">
                @foreach (config('app.supported_locales') as $locale)
                    <div wire:key="emp-father-{{ $locale }}">
                        <x-forms.input-label for="father_name_{{ $locale }}" required>@lang('general.father_name')
                            ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="infoForm.father_name_{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                            id="father_name_{{ $locale }}" :examples="__('examples.father_name', [], $locale)"
                            :hint="__('examples.hints.name')" :hint-always="false" />
                        <x-error-span>{{ $errors->first('infoForm.father_name_' . $locale) }}</x-error-span>
                    </div>
                @endforeach
            </div>
            <div class="md:grid md:grid-cols-2 gap-x-6 gap-y-3">
                @foreach (config('app.supported_locales') as $locale)
                    <div wire:key="emp-mother-{{ $locale }}">
                        <x-forms.input-label for="mother_name_{{ $locale }}" required>@lang('general.mother_name')
                            ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="infoForm.mother_name_{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                            id="mother_name_{{ $locale }}" :examples="__('examples.mother_name', [], $locale)"
                            :hint="__('examples.hints.name')" :hint-always="false" />
                        <x-error-span>{{ $errors->first('infoForm.mother_name_' . $locale) }}</x-error-span>
                    </div>
                @endforeach
            </div>
            <div wire:key="emp-dob">
                <x-forms.input-label for="dob">@lang('general.dob')</x-forms.input-label>
                <x-forms.date-input wire:model="infoForm.dob" id="dob" />
                <x-error-span>{{ $errors->first('infoForm.dob') }}</x-error-span>
            </div>

            <div wire:key="emp-nationality">
                <x-forms.input-label for="nationality">@lang('general.nationality')</x-forms.input-label>
                <x-forms.select2-input wire:model.live="infoForm.nationality" id="nationality">
                    @foreach (Nationality::getValues() as $statusValue)
                        @php $status = Nationality::getLocalizedName($statusValue); @endphp
                        <option value="{{ $statusValue }}">{{ $status }}</option>
                    @endforeach
                </x-forms.select2-input>
                <x-error-span>{{ $errors->first('infoForm.nationality') }}</x-error-span>
            </div>

            <div wire:key="emp-national-number">
                <x-forms.input-label for="national_number" required>@lang('general.national_number')</x-forms.input-label>
                @if ((int) data_get($infoForm, 'nationality') === Nationality::Syrian)
                    <x-forms.input type="text" wire:model="infoForm.national_number" id="national_number"
                        inputmode="numeric" maxlength="11" dir="ltr"
                        placeholder="{{ __('general.national_number_placeholder') }}" />
                @else
                    <x-forms.input type="text" wire:model="infoForm.national_number" id="national_number"
                        inputmode="text" maxlength="255" dir="ltr"
                        placeholder="{{ __('general.national_number_placeholder') }}" />
                @endif
                <x-error-span>{{ $errors->first('infoForm.national_number') }}</x-error-span>
            </div>


            <div class="md:grid md:grid-cols-2 gap-x-6 gap-y-3">
                @foreach (config('app.supported_locales') as $locale)
                    <div wire:key="pob-{{ $locale }}">
                        <x-forms.input-label for="pob_{{ $locale }}">@lang('general.place_and_birth_date')
                            ({{ strtoupper($locale) }})
                        </x-forms.input-label>
                        <x-forms.input type="text" wire:model="infoForm.pob_{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                            id="pob_{{ $locale }}" />
                        <x-error-span>{{ $errors->first('infoForm.pob_' . $locale) }}</x-error-span>
                    </div>
                @endforeach
            </div>
            <div>
                <x-forms.input-label for="civil_registry_office">@lang('general.civil_registry_office')</x-forms.input-label>
                <x-forms.input type="text" wire:model="infoForm.civil_registry_office"
                    id="civil_registry_office" />
                <x-error-span>{{ $errors->first('infoForm.civil_registry_office') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="civil_registry_number">@lang('general.civil_registry_number')</x-forms.input-label>
                <x-forms.input type="text" wire:model="infoForm.civil_registry_number"
                    id="civil_registry_number" />
                <x-error-span>{{ $errors->first('infoForm.civil_registry_number') }}</x-error-span>
            </div>


        </div>
        <div class="grid pb-8 mb-8 border-b md:grid-cols-1 gap-x-6 gap-y-3">
            <x-heading-label class="col-span-1 ">@lang('general.membership')</x-heading-label>

            <div class="flex items-center col-span-1 space-x-2 ">
                <x-forms.input type="checkbox" wire:model.live="infoForm.is_board_member"
                    wire:change="toggleBoardMember($event.target.checked)" value="1" id="is_board_member" />
                <x-forms.input-label for="is_board_member">@lang('general.is_board_member')</x-forms.input-label>
                <x-error-span>{{ $errors->first('infoForm.is_board_member') }}</x-error-span>
            </div>

            <div class="grid col-span-1 md:grid-cols-2 gap-x-6 gap-y-3"
                wire:key="board-member-{{ (int) data_get($infoForm, 'is_board_member') }}">
                <div>
                    <x-forms.input-label for="board_member_started_at">@lang('general.board_member_started_at')</x-forms.input-label>
                    <x-forms.date-input wire:model.live="infoForm.board_member_started_at"
                        id="board_member_started_at" :required="data_get($infoForm, 'is_board_member')"
                        value="{{ data_get($infoForm, 'is_board_member') ?? null }}" />
                    <x-error-span>{{ $errors->first('infoForm.board_member_started_at') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="board_member_end_at">@lang('general.board_member_end_at')</x-forms.input-label>
                    <x-forms.date-input wire:model.live="infoForm.board_member_end_at" id="board_member_end_at"
                        value="{{ data_get($infoForm, 'is_board_member') ?? null }}" />
                    <x-error-span>{{ $errors->first('infoForm.board_member_end_at') }}</x-error-span>
                </div>
            </div>
        </div>
        <div class="pb-8 mb-8 border-b">
            <x-heading-label>@lang('general.skills_and_interests')</x-heading-label>

            <div class="md:col-span-2" wire:ignore>
                <x-forms.input-label for="skills">@lang('general.skills')</x-forms.input-label>
                <x-forms.tags-input inputId="skills" :placeholder="__('employees.form.add-skill')"
                    suggestions="Resilience,Initiative,MS Office,Teamwork,Time management,Critical thinking,Problem Solving,Adaptability,Willingness to learn,Active listening,Emotion Intelligence,Creativity,Data analysis"
                    :forceSuggestion=true wire:model="infoForm.skills" />
                <x-error-span>{{ $errors->first('infoForm.skills') }}</x-error-span>
            </div>

            <div class="md:col-span-2" wire:ignore>
                <x-forms.input-label for="interests">@lang('general.interests')</x-forms.input-label>
                <x-forms.tags-input inputId="interests" :placeholder="__('employees.form.add-interest')"
                    suggestions="Reading,Swimming,Cooking,Sports,Football,Basketball,Tennis,Hiking,Traveling"
                    :forceSuggestion=true wire:model="infoForm.interests" />
                <x-error-span>{{ $errors->first('infoForm.interests') }}</x-error-span>
            </div>
        </div>

        <x-forms.dirty-gate watch="infoForm" :active="!$isCreateMode" class="flex justify-end space-x-2 ">
            {{--
            The tab is deliberately NOT advanced here. In create mode
            saveInformation() redirects into the edit form on success, so
            switching tabs client-side only ever took effect when validation
            FAILED — which hid this tab, and with it every error message
            belonging to its fields. In edit mode the save simply stays put.
        --}}
            @include('livewire.employee.partials.record-save-buttons', ['tour' => true])
        </x-forms.dirty-gate>

    </form>
@endif
