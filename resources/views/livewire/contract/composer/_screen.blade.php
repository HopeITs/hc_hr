@php
    /*
     * The composer itself — identical for creating a contract and for editing
     * one. Everything that differs between the two screens arrives as a
     * variable, so neither page owns a private copy of this layout:
     *
     *   $mode               'create' | 'edit'
     *   $seed               the contract's current values, or null for a blank create
     *   $cities / $lockedCity / $types   option data
     *   $terminationReasons only meaningful in edit mode
     *   $cancelUrl          where the Cancel link goes back to
     *   $currentPeriod      the contract's stored term, shown in the card header
     */
    $mode ??= 'create';
    $seed ??= null;
    $terminationReasons ??= collect();
    $canManageAutoRenew ??= false;
    $cancelUrl ??= route('contracts.index');
    $currentPeriod ??= null;

    $isEdit = $mode === 'edit';

    // Mirrors ContractForm::payIsHidden(). The pay figures are already absent
    // from $seed for such a role (ContractForm::fillFromModel withholds them);
    // this flag is what turns the section itself into a read-only notice
    // instead of an empty box the Save button waits forever on.
    $payHidden = auth()->check() && ! auth()->user()->canViewSalary();

    // Editing adds the one section a contract that does not exist cannot have.
    $sections = ['assignment', 'schedule', 'pay'];

    if ($isEdit) {
        $sections[] = 'termination';
    }

    // work_day_from/to store Carbon's own day-of-week numbering (0 = Sunday
    // ... 6 = Saturday); shared by the day-range picker's options and the
    // JS-side "Sunday to Thursday" summary so both agree on day names.
    $dayNames = collect(range(0, 6))->mapWithKeys(
        fn (int $day) => [$day => \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::SUNDAY)->addDays($day)->translatedFormat('l')],
    );

    $composerConfig = [
        'locale' => app()->getLocale(),
        // Draft-upgrade detection (see checkForDraftContract() in
        // contract-composer.js) calls a method that exists only on the
        // create screen's component — an edit is always about a contract
        // that already exists, so there is no "latest contract" to check.
        'allowDraftUpgrade' => ! $isEdit,
        'cityId' => $lockedCity?->id ?? ($seed['cityId'] ?? null),
        'cities' => $cities->map(fn ($city) => ['value' => $city->id, 'label' => $city->name])->values(),
        'sections' => $sections,
        'dayNames' => $dayNames,
        // An edit opens with everything collapsed and summarised: there is
        // nothing to fill in, only something to change.
        'initialSection' => $isEdit ? '' : 'assignment',
        'typeDefaults' => $types->mapWithKeys(fn ($type) => [
            (string) $type->id => [
                'name' => $type->name,
                'start' => $type->startDate,
                'end' => $type->endDate,
                'category' => $type->category,
            ],
        ]),
        'terminationReasons' => $terminationReasons
            ->map(fn ($reason) => ['value' => $reason->id, 'label' => $reason->title])
            ->values(),
        'canManageAutoRenew' => $canManageAutoRenew,
        'payHidden' => $payHidden,
        // Present whenever this page opened on an existing contract — a renewal
        // or an edit — so Alpine can fill every field from it instead of blank.
        'seed' => $seed,
        'labels' => [
            'cityLevel' => __('general.composer.city-level'),
            'lockedBecause' => __('general.composer.locked-because'),
            'notTerminated' => __('general.composer.not-terminated'),
            'sections' => [
                'assignment' => __('general.composer.assignment'),
                'schedule' => __('general.composer.schedule'),
                'pay' => __('general.composer.pay'),
                'termination' => __('general.composer.termination'),
            ],
            // Passed with their :placeholders intact; the composer substitutes
            // them client-side so no formatting logic is duplicated in PHP.
            'progress' => __('general.composer.progress'),
            'seatsLeft' => __('general.composer.seats-left'),
            'noSeatsLeft' => __('general.composer.no-seats-left'),
            'duplicate' => __('general.composer.duplicate-contract'),
            'draftFound' => __('general.composer.draft-found'),
            // :count substituted client-side, same as the duration labels below.
            // Laravel pipe-plural strings, resolved by choice() in the composer.
            'daysPerWeek' => __('contracts.include-days-per-week'),
            'hoursPerWeek' => __('contracts.include-hours-per-week'),
            'hoursPerDay' => __('contracts.include-hours-per-day'),
            'showDayRange' => __('general.composer.show-day-range'),
            'weeklyHoursValue' => __('general.composer.weekly-hours-value'),
            'dayRange' => __('general.composer.day-range'),
            'flexible' => __('general.composer.schedule-flexible'),
            'scheduleHintDayRange' => __('general.composer.schedule-hint-day-range'),
            'scheduleHintSecondShift' => __('general.composer.schedule-hint-second-shift'),
            'scheduleHintWeeklyHours' => __('general.composer.schedule-hint-weekly-hours'),
            'payHintSalaryRequired' => __('general.composer.pay-hint-salary-required'),
            'payHintSalaryNegative' => __('general.composer.pay-hint-salary-negative'),
            'payHintSalaryNumber' => __('general.composer.pay-hint-salary-number'),
            'payHintSessionDuration' => __('general.composer.pay-hint-session-duration'),
            'payHintNoTiers' => __('general.composer.pay-hint-no-tiers'),
            'payHintTiersIncomplete' => __('general.composer.pay-hint-tiers-incomplete'),
            'payHintAmountNegative' => __('general.composer.pay-hint-amount-negative'),
            // Whole counts are pipe-plural strings (Arabic has six forms), picked
            // client-side by choice(); a fractional figure uses the *Decimal one.
            'monthsCount' => __('contracts.months-count'),
            'monthsDecimal' => __('contracts.months-decimal'),
            'daysCount' => __('contracts.days-count'),
            'daysDecimal' => __('contracts.days-decimal'),
            'hoursShort' => __('contracts.hours-short'),
            'unknownCurrency' => __('general.unknown'),
            'perMonth' => __('general.composer.per-month'),
            'perHour' => __('general.composer.per-hour'),
            'perSession' => __('general.composer.per-session'),
            'perYear' => __('general.composer.per-year'),
            'tiersCount' => __('contracts.tiers-count'),
            'salaryHidden' => __('general.salary-hidden'),
            'empty' => '—',
            // Points from start to end in the reading direction.
            'periodArrow' => app()->getLocale() === 'ar' ? '←' : '→',
        ],
    ];
@endphp

<div
    x-data="contractComposer(@js($composerConfig))"
    x-init="initialize()"
    class="pb-2 max-lg:pb-40"
>
    {{-- Errors in a collapsed section; each entry opens it (reveal-field). --}}
    <x-forms.errors-validation-list :sections="$errorSections ?? []" />

    {{--
        items-stretch is what actually guarantees the two columns share a
        height: a grid row is as tall as its tallest cell, and both cells
        fill it. Neither side is tuned to the other.
    --}}
    <div class="grid items-stretch gap-4 lg:grid-cols-[minmax(0,1fr)_18rem] xl:gap-5">
        <div class="flex min-w-0 flex-col gap-2.5">
            @include('livewire.contract.composer._assignment')
            @include('livewire.contract.composer._schedule', ['dayNames' => $dayNames])

            @include('livewire.contract.composer._pay', ['payHidden' => $payHidden])

            @if($isEdit)
                @include('livewire.contract.composer._termination')
            @endif

            @include('livewire.contract.composer._action-bar', [
                'cancelUrl' => $cancelUrl,
                'submitLabel' => $isEdit ? __('general.save') : __('general.composer.create'),
            ])
        </div>

        <aside class="hidden lg:block" data-tour="contract.card">
            @include('livewire.contract.composer._contract-card')
        </aside>
    </div>
</div>
