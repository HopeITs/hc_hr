<form wire:submit.prevent="saveBadges" class="space-y-6">
    @foreach ($badgeForm->badges as $index => $badge)
        <input type="hidden" wire:model="badgeForm.badges.{{ $index }}.id">

        @php
            $badgeType = (int) ($badgeForm->badges[$index]['type'] ?? \App\Enums\BadgeType::Unknown);
            $badgeSubtype = $badgeForm->badges[$index]['subtype'] ?? null;
            $leaveUnit = $badgeForm->badges[$index]['leave_unit'] ?? null;
            $showsSubtype = in_array($badgeType, [\App\Enums\BadgeType::Warning, \App\Enums\BadgeType::Reward], true);
            $badgeDetailLabel = \App\Enums\BadgeSubtype::selectorLabelForType($badgeType);
            $leaveAmountLabel = match ($leaveUnit) {
                'hour' => __('general.number-of-hours'),
                'day' => __('general.number-of-days'),
                default => __('general.leave-quantity'),
            };
        @endphp

        <div wire:key="badge-{{ $index }}" class="relative border-b pb-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-3 flex justify-end">
                    <x-primary-button type="button"
                                      :showSpinner="false"
                                      x-on:click="if ({{ filled($badge['id'] ?? null) ? 'await $confirm()' : 'true' }}) $wire.removeBadge({{ $index }})"
                                      title="@lang('general.remove')">
                        <x-icons.trash/>
                    </x-primary-button>
                </div>
                <div>
                    <x-forms.input-label for="badge_type_{{ $index }}" required>@lang('general.type')</x-forms.input-label>
                    <x-forms.select2-input id="badge_type_{{ $index }}"
                                          wire:model.live="badgeForm.badges.{{ $index }}.type">
                        @foreach(\App\Enums\BadgeType::getValues() as $key => $value)
                            <option value="{{ $value }}">{{ \App\Enums\BadgeType::getLocalizedName($value) }}</option>
                        @endforeach
                    </x-forms.select2-input>

                    <x-error-span>{{ $errors->first("badgeForm.badges.$index.type") }}</x-error-span>
                </div>

                    <div>
                        <x-forms.input-label for="badge_date_{{ $index }}" required>@lang('general.date')</x-forms.input-label>
                        <x-forms.date-input id="badge_date_{{ $index }}"
                                            wire:model.defer="badgeForm.badges.{{ $index }}.date"/>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.date") }}</x-error-span>
                    </div>

                    <div>
                        <x-forms.input-label
                            for="badge_evaluation_{{ $index }}">@lang('general.related_evaluation')</x-forms.input-label>
                        <x-forms.select2-input id="badge_evaluation_{{ $index }}"
                                              wire:model.defer="badgeForm.badges.{{ $index }}.evaluation_id">
                            <option value="">-- @lang('general.select') --</option>
                        </x-forms.select2-input>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.evaluation_id") }}</x-error-span>
                    </div>

                @if($showsSubtype)
                    <div wire:key="badge-subtype-{{ $index }}-{{ $badgeType }}">
                        <x-forms.input-label
                            for="badge_subtype_{{ $index }}"
                            required>{{ $badgeDetailLabel }}</x-forms.input-label>
                        <x-forms.select2-input id="badge_subtype_{{ $index }}"
                                              wire:model.live="badgeForm.badges.{{ $index }}.subtype">
                            <option value="">-- @lang('general.select') --</option>
                            @foreach(\App\Enums\BadgeSubtype::optionsForType($badgeType) as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-forms.select2-input>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.subtype") }}</x-error-span>
                    </div>
                @endif

                @if($showsSubtype && \App\Enums\BadgeSubtype::isFinancial($badgeSubtype))
                    <div>
                        <x-forms.input-label
                            for="badge_amount_{{ $index }}"
                            required>@lang('general.financial-amount')</x-forms.input-label>
                        <x-forms.input id="badge_amount_{{ $index }}"
                                       type="number"
                                       step="0.01"
                                       min="0"
                                       wire:model.defer="badgeForm.badges.{{ $index }}.amount"/>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.amount") }}</x-error-span>
                    </div>

                    <div>
                        <x-forms.input-label
                            for="badge_financial_currency_{{ $index }}"
                            required>@lang('general.currency')</x-forms.input-label>
                        <x-forms.select2-input id="badge_financial_currency_{{ $index }}"
                                              wire:model.defer="badgeForm.badges.{{ $index }}.currency">
                            <option value="">-- @lang('general.select') --</option>
                            <option value="1">SYP</option>
                            <option value="2">USD</option>
                            <option value="3">EUR</option>
                        </x-forms.select2-input>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.currency") }}</x-error-span>
                    </div>
                @endif

                @if($showsSubtype && \App\Enums\BadgeSubtype::isPromotion($badgeSubtype))
                    <div>
                        <x-forms.input-label
                            for="badge_new_position_{{ $index }}"
                            required>@lang('general.new-position')</x-forms.input-label>
                        <x-forms.input id="badge_new_position_{{ $index }}"
                                       type="text"
                                       wire:model.defer="badgeForm.badges.{{ $index }}.new_position"/>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.new_position") }}</x-error-span>
                    </div>
                @endif

                @if($showsSubtype && \App\Enums\BadgeSubtype::isLeave($badgeSubtype))
                    <div wire:key="badge-leave-unit-{{ $index }}-{{ $badgeSubtype }}">
                        <x-forms.input-label required>@lang('general.leave-calculation')</x-forms.input-label>
                        <div class="mt-2 flex flex-wrap gap-4">
                            <x-forms.radio-input
                                id="badge_leave_unit_hour_{{ $index }}"
                                wire:model.live="badgeForm.badges.{{ $index }}.leave_unit"
                                value="hour">@lang('general.leave-hours')</x-forms.radio-input>
                            <x-forms.radio-input
                                id="badge_leave_unit_day_{{ $index }}"
                                wire:model.live="badgeForm.badges.{{ $index }}.leave_unit"
                                value="day">@lang('general.leave-days')</x-forms.radio-input>
                        </div>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.leave_unit") }}</x-error-span>
                    </div>

                    @if(in_array($leaveUnit, ['hour', 'day'], true))
                        <div wire:key="badge-leave-amount-{{ $index }}-{{ $leaveUnit }}">
                            <x-forms.input-label
                                for="badge_leave_amount_{{ $index }}"
                                required>{{ $leaveAmountLabel }}</x-forms.input-label>
                            <x-forms.input id="badge_leave_amount_{{ $index }}"
                                           type="number"
                                           step="1"
                                           min="1"
                                           wire:model.live="badgeForm.badges.{{ $index }}.amount"/>
                            <x-error-span>{{ $errors->first("badgeForm.badges.$index.amount") }}</x-error-span>
                        </div>
                    @endif
                @endif


                {{-- Show salary and currency inputs only if type == Salary_Increase --}}
                @if(($badgeForm->badges[$index]['type'] ?? null) == \App\Enums\BadgeType::Salary_Increase)
                    @if($badgeForm->salaryIsHidden())
                    {{-- The figures were never loaded into the form for this
                         role (BadgeForm::setEmployee), and save() leaves the
                         stored ones alone - so there is nothing to render here
                         but the reason why. --}}
                    <div class="md:col-span-3 rounded-control border border-neutral-200 bg-neutral-50 px-4 py-3">
                        <p class="font-semibold text-body text-neutral-900">@lang('general.salary-hidden-title')</p>
                        <p class="mt-1 text-small text-secondary-medium">@lang('general.salary-hidden-body')</p>
                    </div>
                    @else
                    <div>
                        <x-forms.input-label
                            for="current_salary_{{ $index }}">@lang('general.current_salary')</x-forms.input-label>
                        <x-forms.input id="current_salary_{{ $index }}" type="number" step="0.01"
                                       wire:model.defer="badgeForm.badges.{{ $index }}.current_salary"/>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.current_salary") }}</x-error-span>
                    </div>

                    <div>
                        <x-forms.input-label
                            for="new_salary_{{ $index }}">@lang('general.new_salary')</x-forms.input-label>
                        <x-forms.input id="new_salary_{{ $index }}" type="number" step="0.01"
                                       wire:model.defer="badgeForm.badges.{{ $index }}.new_salary"/>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.new_salary") }}</x-error-span>
                    </div>

                    <div>
                        <x-forms.input-label
                            for="badge_currency_{{ $index }}">@lang('general.currency')</x-forms.input-label>
                        <x-forms.select2-input id="badge_currency_{{ $index }}"
                                              wire:model.defer="badgeForm.badges.{{ $index }}.currency">
                            <option value="0">@lang('general.unknown')</option>
                            <option value="1">SYP</option>
                            <option value="2">USD</option>
                            <option value="3">EUR</option>
                        </x-forms.select2-input>
                        <x-error-span>{{ $errors->first("badgeForm.badges.$index.currency") }}</x-error-span>
                    </div>
                    @endif
                @endif


                <div class="md:col-span-3">
                    <x-forms.input-label
                        for="badge_reason_{{ $index }}"
                        :required="$badgeSubtype === \App\Enums\BadgeSubtype::WarningLeaveDeduction">@lang('general.reason')</x-forms.input-label>
                    <x-forms.textarea id="badge_reason_{{ $index }}"
                                      wire:model.defer="badgeForm.badges.{{ $index }}.reason"
                                      rows="1"></x-forms.textarea>
                    <x-error-span>{{ $errors->first("badgeForm.badges.$index.reason") }}</x-error-span>
                </div>

                <div class="md:col-span-3">
                    <x-forms.input-label for="badge_notes_{{ $index }}">@lang('general.notes')</x-forms.input-label>
                    <x-forms.textarea id="badge_notes_{{ $index }}"
                                      wire:model.defer="badgeForm.badges.{{ $index }}.notes"
                                      rows="3"></x-forms.textarea>
                    <x-error-span>{{ $errors->first("badgeForm.badges.$index.notes") }}</x-error-span>
                </div>
            </div>
        </div>
    @endforeach

    <div class="flex justify-center">
        <x-primary-button  :showSpinner="false" type="button" wire:click="addBadge">
            + @lang('general.add-new')
        </x-primary-button>
    </div>

    <x-forms.dirty-gate watch="badgeForm" :active="! $isCreateMode"
        class="flex justify-end space-x-2 pt-4 mt-6 border-t">
        <x-primary-button type="submit" class="!w-auto" x-bind:disabled="! dirty">@lang('general.save-badges')</x-primary-button>
    </x-forms.dirty-gate>
</form>
