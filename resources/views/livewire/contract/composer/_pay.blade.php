@php
    use App\Enums\PayMode;
    use App\Enums\SessionDuration;

    // Normally handed down by _screen.blade.php; defaulted here so this
    // partial cannot leak figures if it is ever included from somewhere else.
    $payHidden ??= auth()->check() && ! auth()->user()->canViewSalary();

    /*
     * Inlined rather than pulled from x-icons.*, because those components bake a
     * colour utility into their own class list (icons.calendar carries
     * text-ink-3). Merged classes cannot beat it, so the icon stayed
     * grey on the selected red card. These inherit currentColor and nothing else.
     */
    $perOptions = [
        1 => [
            'label' => __('general.month'),
            'path' => '<rect x="3" y="4.5" width="14" height="12" rx="2"/><path d="M3 8.5h14M7 2.5v3M13 2.5v3"/>',
        ],
        2 => [
            'label' => trans_choice('general.hour', 1),
            'path' => '<circle cx="10" cy="10" r="7.25"/><path d="M10 6v4.2l2.6 2.1"/>',
        ],
        3 => [
            'label' => __('general.session'),
            'path' => '<path d="M10 3.25L17.5 7 10 10.75 2.5 7 10 3.25z"/><path d="M5.5 8.75v3.9c0 1.4 2 2.6 4.5 2.6s4.5-1.2 4.5-2.6v-3.9"/>',
        ],
    ];

    $payModeOptions = [
        PayMode::Fixed => PayMode::getLocalizedName(PayMode::Fixed)['value'],
        PayMode::Flexible => PayMode::getLocalizedName(PayMode::Flexible)['value'],
    ];

    $sessionDurationOptions = collect([SessionDuration::Hour, SessionDuration::HourAndHalf, SessionDuration::TwoHours])
        ->mapWithKeys(fn (int $value) => [$value => SessionDuration::getLocalizedName($value)['value']]);

    $currencyOptions = [
        0 => __('general.unknown'),
        1 => 'SYP',
        2 => 'USD',
        3 => 'EUR',
    ];
@endphp

{{-- Section 4 — the one field where a typo costs the most. --}}
<x-contract.section
    name="pay"
    index="3"
    :title="__('general.composer.pay')"
    summary="paySummary"
>
@if($payHidden)
    {{-- A role without access to pay figures gets the section as a notice
         rather than as disabled inputs: there is nothing to disable, because
         the figures were never sent to this browser in the first place
         (ContractForm::fillFromModel). Saying so plainly is also the only way
         the screen can explain why the rest of the form saves fine while the
         pay clause stays exactly as it was — see preserveHiddenPay(). --}}
    <div class="mt-4 flex items-start gap-3 rounded-control border border-line-soft bg-soft p-4">
        <svg class="mt-0.5 size-5 shrink-0 text-ink-2" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path d="M10 2a3.5 3.5 0 00-3.5 3.5V8H6a1.5 1.5 0 00-1.5 1.5v6A1.5 1.5 0 006 17h8a1.5 1.5 0 001.5-1.5v-6A1.5 1.5 0 0014 8h-.5V5.5A3.5 3.5 0 0010 2zm2 6H8V5.5a2 2 0 114 0V8z"/>
        </svg>

        <div class="min-w-0">
            <p class="font-semibold text-body text-ink">@lang('general.salary-hidden-title')</p>
            <p class="mt-1 text-small text-ink-2">@lang('general.salary-hidden-body')</p>
            <x-error-span>{{ $errors->first('form.salary') }}</x-error-span>
        </div>
    </div>
@else
    {{-- Currency and pay mode are the two shared, top-level decisions —
         paired in one row so neither sits alone in an otherwise-empty strip. --}}
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="max-w-40">
            <x-forms.input-label for="composer_currency" required>@lang('general.currency')</x-forms.input-label>
            <x-forms.select id="composer_currency" :options="$currencyOptions" x-model="currency" />
            <x-error-span>{{ $errors->first('form.currency') }}</x-error-span>
        </div>

        <div>
            <x-forms.input-label required>@lang('general.composer.pay-mode')</x-forms.input-label>
            <x-forms.segmented-control
                name="composer_pay_mode"
                model="payMode"
                :options="$payModeOptions"
                :aria-label="__('general.composer.pay-mode')"
            />
            <x-error-span>{{ $errors->first('form.pay_mode') }}</x-error-span>
        </div>
    </div>

    {{-- ───────────────────────── Fixed ───────────────────────── --}}
    {{-- Boxed as one card rather than two loose columns: Session Duration
         used to nest under "Salary Per" alone, so it stretched that column
         taller than "Salary" and left a lopsided gap underneath it. Now it
         spans the full card below both, so the card's height always tracks
         its content instead of whichever column happens to be tallest. --}}
    <div x-cloak x-show="payMode === '1'" class="mt-5 rounded-control border border-line-soft bg-soft/60 p-4">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-forms.input-label for="composer_salary" required>@lang('general.salary')</x-forms.input-label>

                {{-- `min="0"` only governs the browser's own validation, which
                     nothing here triggers: Alpine reads the box directly and the
                     form is sent by script. The border colour therefore has no
                     default in the static class list — x-bind below owns it
                     outright, so the invalid state cannot lose to a stale one. --}}
                <input
                    id="composer_salary"
                    type="number"
                    step="0.01"
                    min="0"
                    inputmode="decimal"
                    placeholder="0.00"
                    x-model="salary"
                    x-bind:aria-invalid="salaryError ? 'true' : null"
                    x-bind:aria-describedby="salaryError ? 'composer_salary-error' : null"
                    x-bind:class="salaryError ? 'border-primary' : 'border-line'"
                    class="w-full min-w-0 rounded-control border
                           bg-card px-3 py-2 text-end font-mono text-body tabular-nums text-ink
                           shadow-e1 transition placeholder:text-ink-3
                              "
                />

                {{-- Echoed back formatted, and annualised when paid monthly. Goes
                     quiet on a figure the form will not take, so the slot below can
                     say why instead of this one confirming a number that is wrong. --}}
                <p x-cloak x-show="salaryEcho"
                   class="mt-2 rounded-control border border-line-soft bg-card px-3 py-1.5
                          text-small font-medium text-ink"
                   x-text="salaryEcho"></p>

                {{-- The client-side twin of <x-forms.input-error>: same id
                     convention, same role, same red, but driven by Alpine because
                     this value never reaches the server until submit — and submit
                     is the thing being blocked. --}}
                <p x-cloak x-show="salaryError"
                   id="composer_salary-error"
                   role="alert"
                   class="mt-1 text-body text-primary">
                    <span class="sr-only">@lang('general.a11y.error-prefix')</span>
                    <span x-text="salaryError"></span>
                </p>

                <x-error-span>{{ $errors->first('form.salary') }}</x-error-span>
            </div>

            <div>
                <x-forms.input-label required>@lang('general.salary-per')</x-forms.input-label>

                <div class="mt-1 grid grid-cols-3 gap-2" role="radiogroup" aria-label="@lang('general.salary-per')">
                    @foreach($perOptions as $value => $option)
                        <label class="cursor-pointer">
                            <input type="radio" class="peer sr-only" name="composer_salary_per"
                                   value="{{ $value }}" x-model="salaryPer"/>
                            <span class="flex min-h-10.5 items-center justify-center gap-1.5 rounded-control
                                         border-2 border-line-soft bg-card px-2 py-2 text-body text-ink
                                         shadow-e1 transition
                                         hover:border-ink-3
                                         peer-checked:border-ink peer-checked:bg-surface peer-checked:text-ink
                                         peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2
                                         peer-focus-visible:outline-focus">
                                <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                                     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    {!! $option['path'] !!}
                                </svg>
                                <span class="truncate font-semibold">{{ $option['label'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <x-error-span>{{ $errors->first('form.salary_per') }}</x-error-span>
            </div>
        </div>

        {{-- Only "Session" implies a length — the other two units are
             self-explanatory without one. Full-width so it never re-creates
             the column-height mismatch the card above was built to avoid. --}}
        <div x-cloak x-show="salaryPer === '3'" x-transition class="mt-4 max-w-64">
            <x-forms.input-label for="composer_session_duration" required>
                @lang('general.session-duration')
            </x-forms.input-label>
            <x-forms.select
                id="composer_session_duration"
                :options="$sessionDurationOptions"
                x-model="sessionDuration"
                :placeholder="__('general.select')"
            />
            <x-error-span>{{ $errors->first('form.session_duration') }}</x-error-span>
        </div>
    </div>

    {{-- ───────────────────────── Flexible ───────────────────────── --}}
    <div x-cloak x-show="payMode === '2'" class="mt-5 space-y-3">
        <p class="text-small text-ink-2">@lang('general.composer.pay-flexible-hint')</p>

        <template x-for="(tier, index) in payTiers" :key="index">
            <div class="space-y-3 rounded-control border border-line-soft p-3">
                <div class="flex items-center justify-between">
                    <span class="text-caption font-semibold text-ink-2" x-text="'#' + (index + 1)"></span>
                    <x-contract.mini-button x-on:click="removePayTier(index)">
                        @lang('general.composer.remove-condition')
                    </x-contract.mini-button>
                </div>

                {{--
                    Every row shares one template, so a plain Blade `id` would
                    repeat itself once per tier — invalid HTML, and each
                    <x-forms.input-label> below would silently point at
                    nothing without it. x-bind:id ties each field to its own
                    row's index instead.
                --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-forms.input-label x-bind:for="'composer_pay_tier_' + index + '_condition_en'">@lang('general.composer.condition') (EN)</x-forms.input-label>
                        <x-forms.input type="text" dir="ltr" maxlength="255" x-model="tier.conditionEn"
                                       x-bind:id="'composer_pay_tier_' + index + '_condition_en'" />
                    </div>
                    <div>
                        <x-forms.input-label x-bind:for="'composer_pay_tier_' + index + '_condition_ar'">@lang('general.composer.condition') (AR)</x-forms.input-label>
                        <x-forms.input type="text" dir="rtl" maxlength="255" x-model="tier.conditionAr"
                                       x-bind:id="'composer_pay_tier_' + index + '_condition_ar'" />
                    </div>
                </div>

                <div class="max-w-40">
                    <x-forms.input-label x-bind:for="'composer_pay_tier_' + index + '_amount'">@lang('general.amount')</x-forms.input-label>
                    {{-- No border swap here, unlike the fixed-mode salary box:
                         x-forms.input bakes border-line into its own
                         class list, and an Alpine binding adds to that list
                         rather than replacing it, so the two reds would race.
                         The message below carries the state instead. --}}
                    <x-forms.input type="number" step="0.01" min="0" x-model="tier.amount"
                                   x-bind:id="'composer_pay_tier_' + index + '_amount'"
                                   x-bind:aria-invalid="tierAmountError(tier) ? 'true' : null" />
                    <p x-cloak x-show="tierAmountError(tier)" role="alert" class="mt-1 text-body text-primary">
                        <span class="sr-only">@lang('general.a11y.error-prefix')</span>
                        <span x-text="tierAmountError(tier)"></span>
                    </p>
                </div>
            </div>
        </template>

        <x-contract.mini-button plus x-on:click="addPayTier()">
            @lang('general.composer.add-condition')
        </x-contract.mini-button>

        <x-error-span>{{ $errors->first('form.pay_tiers') }}</x-error-span>
    </div>

    {{-- Every other section withholds its Continue button until it is valid,
         which is itself the signal. Pay is last: there is no button here to
         withhold, only the Create button in the action bar, so this line is the
         only thing that can say why it is grey. --}}
    <p x-cloak x-show="payBlockedHint" class="mt-4 font-medium text-small text-primary" x-text="payBlockedHint"></p>
@endif
</x-contract.section>
