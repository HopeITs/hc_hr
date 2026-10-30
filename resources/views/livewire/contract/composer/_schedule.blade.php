@php
    use App\Enums\ScheduleMode;

    // work_day_from/to store Carbon's own day-of-week numbering (0 = Sunday
    // ... 6 = Saturday), so the picker options follow it; passed down from
    // _screen.blade.php so the JS-side schedule summary shares the same names.
    $dayNames ??= collect();

    $scheduleModeOptions = [
        ScheduleMode::Fixed => ScheduleMode::getLocalizedName(ScheduleMode::Fixed)['value'],
        ScheduleMode::Flexible => ScheduleMode::getLocalizedName(ScheduleMode::Flexible)['value'],
    ];
@endphp

{{-- Section 2 — the working week, in the contract's own words. --}}
<x-contract.section
    name="schedule"
    index="2"
    :title="__('general.composer.schedule')"
    summary="scheduleSummary"
>
    {{-- Fixed vs flexible — everything below changes shape with it. --}}
    <div class="mt-4">
        <x-forms.input-label required>@lang('general.composer.schedule-mode')</x-forms.input-label>
        <x-forms.segmented-control
            name="composer_schedule_mode"
            model="scheduleMode"
            :options="$scheduleModeOptions"
            :aria-label="__('general.composer.schedule-mode')"
        />
        <x-error-span>{{ $errors->first('form.schedule_mode') }}</x-error-span>
    </div>

    {{-- ───────────────────────── Fixed ───────────────────────── --}}
    <div x-cloak x-show="scheduleMode === '1'" class="mt-5 space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-forms.input-label for="composer_work_hours_from">@lang('general.work-hours-from')</x-forms.input-label>
                <x-forms.time-picker
                    id="composer_work_hours_from"
                    alpine-model="workHoursFrom"
                    format="HH:mm"
                />
                <x-error-span>{{ $errors->first('form.work_hours_from') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="composer_work_hours_to">@lang('general.work-hours-to')</x-forms.input-label>
                <x-forms.time-picker
                    id="composer_work_hours_to"
                    alpine-model="workHoursTo"
                    format="HH:mm"
                />
                <x-error-span>{{ $errors->first('form.work_hours_to') }}</x-error-span>
            </div>
        </div>

        {{-- A second shift in the same day, e.g. 9:00-13:00 and 17:00-21:00. --}}
        <div>
            <x-button variant="outline" size="sm" :loading="false" x-cloak x-show="!hasSecondShift()" x-on:click="toggleSecondShift(true)">
                <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M10 5v10M5 10h10" stroke-linecap="round"/>
                </svg>
                @lang('general.composer.add-second-shift')
            </x-button>

            <div x-cloak x-show="hasSecondShift()" x-collapse>
                <div class="flex items-center justify-between">
                    <x-forms.input-label>@lang('general.composer.second-shift')</x-forms.input-label>
                    <x-button variant="outline" size="sm" :loading="false" x-on:click="toggleSecondShift(false)">
                        @lang('general.composer.remove-second-shift')
                    </x-button>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-forms.input-label for="composer_work_hours_2_from">@lang('general.work-hours-from')</x-forms.input-label>
                        <x-forms.time-picker
                            id="composer_work_hours_2_from"
                            alpine-model="workHours2From"
                            format="HH:mm"
                        />
                        <x-error-span>{{ $errors->first('form.work_hours_2_from') }}</x-error-span>
                    </div>

                    <div>
                        <x-forms.input-label for="composer_work_hours_2_to">@lang('general.work-hours-to')</x-forms.input-label>
                        <x-forms.time-picker
                            id="composer_work_hours_2_to"
                            alpine-model="workHours2To"
                            format="HH:mm"
                        />
                        <x-error-span>{{ $errors->first('form.work_hours_2_to') }}</x-error-span>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-forms.input-label for="composer_work_day_from" required>@lang('general.composer.work-day-from')</x-forms.input-label>
                <x-forms.select id="composer_work_day_from" :options="$dayNames" x-model="workDayFrom"
                                 :placeholder="__('general.select')" />
                <x-error-span>{{ $errors->first('form.work_day_from') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label for="composer_work_day_to" required>@lang('general.composer.work-day-to')</x-forms.input-label>
                <x-forms.select id="composer_work_day_to" :options="$dayNames" x-model="workDayTo"
                                 :placeholder="__('general.select')" />
                <x-error-span>{{ $errors->first('form.work_day_to') }}</x-error-span>
            </div>
        </div>

        {{--
            Every figure here is computed from the ranges above, never typed
            in — each checkbox only controls whether its own piece is stated
            in the printed clause below, in the same order it reads there:
            hours/week, hours/day, days/week, then the day range itself.
        --}}
        <div class="grid gap-2 sm:grid-cols-2">
            <x-forms.checkbox-card id="composer_show_hours_per_week" model="showHoursPerWeek">
                <span x-text="fixedHoursPerWeekLabel"></span>
            </x-forms.checkbox-card>

            <x-forms.checkbox-card id="composer_show_hours_per_day" model="showHoursPerDay">
                <span x-text="fixedHoursPerDayLabel"></span>
            </x-forms.checkbox-card>

            <x-forms.checkbox-card id="composer_show_days_per_week" model="showDaysPerWeek">
                <span x-text="fixedDaysPerWeekLabel"></span>
            </x-forms.checkbox-card>

            <x-forms.checkbox-card id="composer_show_day_range" model="showDayRange">
                @lang('general.composer.show-day-range')
            </x-forms.checkbox-card>
        </div>
    </div>

    {{-- ───────────────────────── Flexible ───────────────────────── --}}
    <div x-cloak x-show="scheduleMode === '2'" class="mt-5 space-y-5">
        <p class="text-small text-ink-2">@lang('general.composer.schedule-flexible-hint')</p>

        {{--
            "Hours per week" is the only box here that feeds weekly_hours —
            LeaveBalanceService's entitlement figure — so it is grouped apart
            from the purely descriptive boxes below, not just another item in
            one long list.
        --}}
        <div>
            <p class="font-semibold tracking-wider uppercase text-caption text-ink-2">
                @lang('general.composer.flex-hours-group')
            </p>

            <div class="mt-2 grid items-start gap-3 sm:grid-cols-2">
                {{--
                    Hours/week — required unless the category is exempt
                    (service). Flagged the same way any other required field
                    is (an asterisk on its own label), not with a
                    conditionally-appearing card highlight: that reads as an
                    error the moment the box is unchecked, when it usually
                    just has not been gotten to yet.
                --}}
                <x-forms.checkbox-card id="composer_flex_hours_week_on" model="flexHoursPerWeekOn">
                    @lang('general.composer.flex-hours-per-week')
                    <span x-cloak x-show="selectedType?.category !== 'service'"
                          class="text-primary ltr:ml-1 rtl:mr-1" aria-hidden="true">*</span>
                    <span x-cloak x-show="selectedType?.category !== 'service'" class="sr-only">
                        (@lang('general.a11y.required-field'))
                    </span>

                    <x-slot:hint>
                        <p x-cloak x-show="selectedType?.category !== 'service'"
                           class="mt-1 text-caption text-ink-3">
                            @lang('general.composer.flex-hours-per-week-required')
                        </p>
                    </x-slot:hint>

                    <x-slot:fields>
                        <div x-cloak x-show="flexHoursPerWeekOn" x-collapse class="mt-2 max-w-40">
                            <x-forms.input id="composer_flex_hours_per_week" type="number" min="1" x-model="flexHoursPerWeek" />
                            <x-error-span>{{ $errors->first('form.flex_hours_per_week') }}</x-error-span>
                        </div>
                    </x-slot:fields>
                </x-forms.checkbox-card>

                {{-- Days/week — print-only, does not affect weekly_hours. --}}
                <x-forms.checkbox-card id="composer_flex_days_on" model="flexDaysPerWeekOn">
                    @lang('general.composer.flex-days-per-week')

                    <x-slot:fields>
                        <div x-cloak x-show="flexDaysPerWeekOn" x-collapse class="mt-2 max-w-40">
                            <x-forms.input id="composer_flex_days_per_week" type="number" min="1" max="7" x-model="flexDaysPerWeek" />
                            <x-error-span>{{ $errors->first('form.flex_days_per_week') }}</x-error-span>
                        </div>
                    </x-slot:fields>
                </x-forms.checkbox-card>

                {{-- Hours/month — teaching-style stipends tiered by a monthly hour count. --}}
                <x-forms.checkbox-card id="composer_flex_hours_month_on" model="flexHoursPerMonthOn">
                    @lang('general.composer.flex-hours-per-month')

                    <x-slot:fields>
                        <div x-cloak x-show="flexHoursPerMonthOn" x-collapse class="mt-2 max-w-40">
                            <x-forms.input id="composer_flex_hours_per_month" type="number" min="1" max="300" x-model="flexHoursPerMonth" />
                            <x-error-span>{{ $errors->first('form.flex_hours_per_month') }}</x-error-span>
                        </div>
                    </x-slot:fields>
                </x-forms.checkbox-card>
            </div>
        </div>

        <div>
            <p class="font-semibold tracking-wider uppercase text-caption text-ink-2">
                @lang('general.composer.flex-descriptive-group')
            </p>

            <div class="mt-2 grid items-start gap-3 sm:grid-cols-2">
                <x-forms.checkbox-card id="composer_flex_varies" model="flexVariesByRequirements">
                    @lang('general.composer.flex-varies-by-requirements')
                </x-forms.checkbox-card>

                <x-forms.checkbox-card id="composer_flex_coordinated" model="flexCoordinatedWithManager">
                    @lang('general.composer.flex-coordinated-with-manager')
                </x-forms.checkbox-card>

                {{-- Custom text, bilingual — spans both columns so its EN/AR pair keeps a usable width. --}}
                <x-forms.checkbox-card id="composer_flex_custom_on" model="flexCustomOn" class="sm:col-span-2">
                    @lang('general.composer.flex-custom')

                    <x-slot:fields>
                        <div x-cloak x-show="flexCustomOn" x-collapse class="grid gap-3 mt-2 sm:grid-cols-2">
                            <div>
                                <x-forms.input-label for="composer_flex_custom_en">@lang('general.composer.flex-custom') (EN)</x-forms.input-label>
                                <x-forms.input id="composer_flex_custom_en" type="text" dir="ltr" maxlength="255" x-model="flexCustomEn" />
                                <x-error-span>{{ $errors->first('form.flex_custom_en') }}</x-error-span>
                            </div>
                            <div>
                                <x-forms.input-label for="composer_flex_custom_ar">@lang('general.composer.flex-custom') (AR)</x-forms.input-label>
                                <x-forms.input id="composer_flex_custom_ar" type="text" dir="rtl" maxlength="255" x-model="flexCustomAr" />
                                <x-error-span>{{ $errors->first('form.flex_custom_ar') }}</x-error-span>
                            </div>
                        </div>
                    </x-slot:fields>
                </x-forms.checkbox-card>
            </div>
        </div>
    </div>

    {{--
        How the printed clause will actually read, in both languages at once
        — built live from whichever fields/checkboxes are set, in either
        mode, so "what will this print?" never requires creating the
        contract and opening the PDF. weekly_hours (LeaveBalanceService's
        real entitlement figure) is not repeated here — it already has its
        own line in the contract summary card alongside the composer.
    --}}
    <div class="p-3 mt-5 border rounded-control border-line-soft bg-soft">
        <p class="font-semibold tracking-wider uppercase text-caption text-ink-2">
            @lang('general.composer.schedule-preview-title')
        </p>

        <div class="grid gap-3 mt-2 sm:grid-cols-2 sm:divide-x sm:divide-line-soft">
            <p dir="ltr" class="text-start text-body text-ink sm:pe-3" x-text="schedulePreviewEn"></p>
            <p dir="rtl" class="text-start text-body text-ink sm:ps-3" x-text="schedulePreviewAr"></p>
        </div>
    </div>
    <x-error-span>{{ $errors->first('form.weekly_hours') }}</x-error-span>

    {{-- The Continue button below only appears once the section is valid —
         this is the only place that says why, when it is not. --}}
    <p x-cloak x-show="scheduleBlockedHint" class="mt-2 font-medium text-small text-primary" x-text="scheduleBlockedHint"></p>

    <x-contract.continue section="schedule" />
</x-contract.section>
