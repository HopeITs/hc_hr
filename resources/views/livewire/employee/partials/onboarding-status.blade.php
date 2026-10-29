{{--
    Where an employee record stands in onboarding (B5, DEC-04,
    docs/ux/employee-lifecycle.md): what is needed to save a draft and to
    complete it, why the other sections are not open yet, and, once saved,
    the record's state with its next action. Shown while creating, and for a
    draft or a volunteer awaiting their first contract. Nothing here is a
    permission: every action re-checks on the server.
--}}
@php
    /** @var \App\Models\Employee|null $record */
    $record = $isCreateMode ? null : $employee;
    $isDraft = $record?->isDraft() ?? false;
    $isAwaiting = $record && ! $isDraft && $record->isAwaitingContract();
    $locale = app()->getLocale();

    $names = [];
    foreach (['first_name', 'last_name', 'father_name', 'mother_name'] as $name) {
        foreach (config('app.supported_locales') as $nameLocale) {
            $names[] = "{$name}_{$nameLocale}";
        }
    }

    // [form field, label, tab]. Every key is an infoForm property; the tab is
    // where the field lives (employee-lifecycle.md §6).
    $draftNeeds = [
        ['names', __('employees.onboarding.need-names'), 'information', $names],
        ['city_id', trans_choice('general.city', 1), 'information', ['city_id']],
        ['national_number', __('general.national_number'), 'information', ['national_number']],
        ['username', __('general.username'), 'contact', ['username']],
    ];
    $completeNeeds = [
        ['gender', __('general.gender'), 'information', ['gender']],
        ['marital_status', __('general.marital_status'), 'information', ['marital_status']],
    ];
@endphp

@if ($isCreateMode || $isDraft || $isAwaiting)
    <section data-testid="onboarding-status" aria-labelledby="onboarding-status-title"
        class="mb-4 rounded-control border border-neutral-200 border-s-4 border-s-primary bg-white p-4 text-body"
        x-data="{
            fields: @js(collect($draftNeeds)->merge($completeNeeds)->map(fn ($need) => ['key' => $need[0], 'tab' => $need[2], 'props' => $need[3]])->values()),
            filled(key) {
                const need = this.fields.find((f) => f.key === key);
                return need.props.every((prop) => {
                    const value = $wire.$get('infoForm.' + prop, true);
                    return value !== null && value !== undefined && String(value).trim() !== '';
                });
            },
            go(key) {
                const need = this.fields.find((f) => f.key === key);
                const firstEmpty = need.props.find((prop) => {
                    const value = $wire.$get('infoForm.' + prop);
                    return value === null || value === undefined || String(value).trim() === '';
                }) ?? need.props[0];
                window.hcRevealField('infoForm.' + firstEmpty, { section: need.tab, reveal: 'setActiveTab', wire: $wire });
            },
        }">
        @if ($isAwaiting)
            <h2 id="onboarding-status-title" class="font-semibold text-secondary-dark">@lang('employees.onboarding.state-awaiting')</h2>
            <p class="mt-1 text-secondary-medium">@lang('employees.onboarding.awaiting-help')</p>
            @if (auth()->user()?->hasActivePermission('contracts.create'))
                <x-secondary-button :href="route('contracts.create')" class="mt-3 !w-auto">@lang('employees.onboarding.create-contract')</x-secondary-button>
            @endif
        @else
            <h2 id="onboarding-status-title" class="font-semibold text-secondary-dark">
                {{ $isDraft ? __('employees.onboarding.state-draft') : __('employees.onboarding.new-record') }}
            </h2>

            @if ($isDraft)
                <p class="mt-1 text-secondary-medium" data-testid="draft-saved-at">
                    {{ __('employees.onboarding.saved-at', [
                        'time' => $record->updated_at?->timezone(config('app.timezone'))->translatedFormat('d M Y, H:i'),
                        'name' => $record->lastSavedBy?->profile?->nameForLocale($locale) ?? $record->lastSavedBy?->email ?? __('general.unknown'),
                    ]) }}
                </p>
            @endif

            <div class="mt-3 grid gap-4 md:grid-cols-2">
                <div>
                    <p class="font-medium text-secondary-dark">
                        {{ $isDraft ? __('employees.onboarding.draft-has') : __('employees.onboarding.to-save-draft') }}
                    </p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($draftNeeds as [$key, $label])
                            <li>
                                <button type="button" x-on:click="go(@js($key))"
                                    class="inline-flex items-center gap-2 text-start hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                    <span x-show="filled(@js($key))" class="font-bold text-ink" aria-hidden="true">✓</span>
                                    <span x-show="! filled(@js($key))" class="text-secondary-light" aria-hidden="true">○</span>
                                    <span>{{ $label }}</span>
                                    <span class="sr-only" x-text="filled(@js($key)) ? @js(__('employees.onboarding.filled')) : @js(__('employees.onboarding.missing'))"></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <p class="font-medium text-secondary-dark">@lang('employees.onboarding.to-complete')</p>
                    <ul class="mt-1 space-y-1">
                        @foreach ($completeNeeds as [$key, $label])
                            <li>
                                <button type="button" x-on:click="go(@js($key))"
                                    class="inline-flex items-center gap-2 text-start hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                    <span x-show="filled(@js($key))" class="font-bold text-ink" aria-hidden="true">✓</span>
                                    <span x-show="! filled(@js($key))" class="text-secondary-light" aria-hidden="true">○</span>
                                    <span>{{ $label }}</span>
                                    <span class="sr-only" x-text="filled(@js($key)) ? @js(__('employees.onboarding.filled')) : @js(__('employees.onboarding.missing'))"></span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-small text-secondary-medium">{{ $isDraft ? __('employees.onboarding.complete-help-draft') : __('employees.onboarding.complete-help') }}</p>
                </div>
            </div>

            @if ($isCreateMode)
                <p class="mt-3 text-small text-secondary-medium" data-testid="sections-locked-reason">@lang('employees.onboarding.sections-after-draft')</p>
            @endif

            @if ($isDraft)
                <div class="mt-3">
                    <x-secondary-button type="button" wire:click="confirmDiscardDraftRecord" class="!w-auto" data-testid="discard-draft">
                        @lang('employees.onboarding.discard')
                    </x-secondary-button>
                </div>
            @endif
        @endif
    </section>
@endif
