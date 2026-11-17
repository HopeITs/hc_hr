{{--
    The request form itself, shared by the two ways a request is raised.

    A manager submits one for a post beneath them and HR opens one directly
    for a hire already agreed; what is being asked for is the same either
    way, and duplicating it would let the two drift until a field existed on
    one screen and not the other.

    Every list it renders is narrowed by whoever composed it. That is a
    convenience, never the guard: the service re-derives the same sets and
    refuses anything outside them.

    Sections appear only for the type chosen, in the order a requester thinks
    about it: what (overview, and the new post when one is proposed), why and
    when (requirements), how (work arrangement and contract), and for how much
    (compensation). Closing a post or a vacancy needs only a reason and a date.
--}}
@php
    $Type = \App\Enums\RecruitmentRequestType::class;
    $type = (int) ($data['request_type'] ?? 0);
    $newPosition = $type === $Type::CreatePosition;
    $hires = in_array($type, [$Type::FillVacancy, $Type::IncreaseHeadcount, $Type::CreatePosition], true);
    $closes = in_array($type, [$Type::DecreaseHeadcount, $Type::ClosePosition, $Type::CloseVacancy], true);
    $typeFixed = (bool) ($editing ?? false);

    // Decrease headcount (3) is not offered for new requests.
    $types = collect([$Type::FillVacancy, $Type::IncreaseHeadcount, $Type::CreatePosition, $Type::ClosePosition, $Type::CloseVacancy])
        ->mapWithKeys(fn ($value) => [$value => $Type::getLocalizedName($value)['value']])->all();

    $targetField = $newPosition ? 'direct_manager_id' : 'position_id';
    $positionOptions = $positions->mapWithKeys(fn ($position) => [$position->id => $position->title.' · '.$position->program?->name.' · '.$position->city?->name])->all();
    $selectedPosition = $positions->firstWhere('id', (int) ($data[$targetField] ?? 0));

    $availableSeats = null;
    if ($newPosition) {
        $availableSeats = max(0, (int) ($data['max_emp'] ?? 0));
    } elseif ($selectedPosition && $type === $Type::FillVacancy) {
        $availableSeats = $vacancies->isNotEmpty()
            ? $vacancies->sum(fn ($vacancy) => max(0, (int) $vacancy->needed_count - (int) $vacancy->filled_count))
            : max(0, (int) $selectedPosition->max_emp - (int) $selectedPosition->exist_emp);
    }

    $form = config('recruitment.request_form');
    $choices = fn (string $group, string $prefix) => collect($form[$group])
        ->mapWithKeys(fn ($value) => [$value => __('recruitment.'.$prefix.$value)])->all();

    // Earliest dates the service accepts: needed-by is strictly after today,
    // the period starts on or after needed-by and ends on or after its start.
    $tomorrow = now()->addDay()->toDateString();
    $validDate = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    $startMin = $validDate($data['needed_by_date'] ?? null) ?? $tomorrow;
    $endMin = $validDate($data['volunteer_period_start_date'] ?? null) ?? $startMin;

    $section = 0;
    $sectionClass = 'rounded-surface border border-line-soft bg-card p-5 shadow-e1 sm:p-6';
    $grid = 'grid gap-x-6 gap-y-5 md:grid-cols-2';
@endphp

<div class="space-y-6">
    {{-- A · Overview --}}
    <section aria-labelledby="request-overview" class="{{ $sectionClass }}">
        @include('livewire.recruitment.requests.partials.section-header', [
            'number' => ++$section, 'id' => 'request-overview',
            'title' => __('recruitment.form_ui.overview'),
            'lead' => __('recruitment.form_ui.overview_lead'),
        ])

        <div class="space-y-5">
            @if ($typeFixed)
                {{-- The type is fixed once the request exists (Edit puts it back
                     if anything tries to change it), so it reads as a fact. --}}
                <div>
                    <p class="mb-1.5 text-body font-bold text-ink">{{ __('recruitment.type') }}</p>
                    <div class="flex items-start gap-3 rounded-control border border-line-soft bg-soft p-3.5">
                        <x-ic name="file" class="mt-0.5 size-5 shrink-0 text-ink-2" />
                        <span class="min-w-0">
                            <span class="block font-bold text-ink">{{ $Type::getLocalizedName($type)['value'] }}</span>
                            @if (in_array($type, array_keys($types), true))
                                <span class="block text-small text-ink-2">{{ __('recruitment.form_ui.type_desc_'.$type) }}</span>
                            @endif
                        </span>
                    </div>
                    <x-forms.hint id="request_type-hint">{{ __('recruitment.form_ui.type_fixed') }}</x-forms.hint>
                </div>
            @else
                <fieldset class="min-w-0" @error('data.request_type') aria-describedby="request_type-error" @enderror>
                    <legend class="mb-1.5 text-body font-bold text-ink">
                        {{ __('recruitment.type') }}
                        <span class="text-primary ms-1" aria-hidden="true">*</span>
                        <span class="sr-only">({{ __('general.a11y.required-field') }})</span>
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($types as $value => $name)
                            <label wire:key="type-{{ $value }}" class="group min-w-0 cursor-pointer">
                                <input type="radio" class="peer sr-only" name="data_request_type" value="{{ $value }}"
                                    wire:model.live="data.request_type"
                                    aria-labelledby="request-type-{{ $value }}-name"
                                    aria-describedby="request-type-{{ $value }}-desc"
                                    @checked($type === $value)>
                                <span class="hc-choice flex h-full items-start gap-3 p-3.5">
                                    <span aria-hidden="true" class="mt-1 grid size-4 shrink-0 place-items-center rounded-full border-2 border-line group-has-[input:checked]:border-ink">
                                        <span class="size-2 rounded-full bg-ink opacity-0 group-has-[input:checked]:opacity-100"></span>
                                    </span>
                                    <span class="min-w-0">
                                        <span id="request-type-{{ $value }}-name" class="block font-bold text-ink">{{ $name }}</span>
                                        <span id="request-type-{{ $value }}-desc" class="mt-0.5 block text-small text-ink-2">{{ __('recruitment.form_ui.type_desc_'.$value) }}</span>
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-forms.input-error field="data.request_type" input-id="request_type" />
                </fieldset>
            @endif

            <div class="{{ $grid }}">
                <div class="min-w-0 md:col-span-2">
                    @include('livewire.recruitment.requests.partials.select', [
                        'field' => $targetField,
                        'label' => $newPosition ? 'parent_position' : 'position',
                        'options' => $positionOptions,
                        'live' => true,
                        'hint' => __($newPosition ? 'recruitment.parent_help' : 'recruitment.target_help'),
                    ])
                    @if ($positions->isEmpty())
                        <p role="status" class="mt-2 flex items-start gap-2 text-small text-ink-2">
                            <x-ic name="info" class="mt-0.5 size-4 shrink-0" />
                            <span>{{ __('recruitment.no_targets') }}</span>
                        </p>
                    @endif
                    @if ($selectedPosition)
                        <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-control bg-soft px-3 py-2 text-small text-ink-2" data-position-summary>
                            <x-ic name="building" class="size-4 shrink-0" />
                            <span class="sr-only">{{ __('recruitment.form_ui.selected_position') }}:</span>
                            <span class="font-semibold text-ink">{{ $selectedPosition->title }}</span>
                            @if ($selectedPosition->program?->name)<span aria-hidden="true">·</span><span>{{ $selectedPosition->program->name }}</span>@endif
                            @if ($selectedPosition->city?->name)<span aria-hidden="true">·</span><span>{{ $selectedPosition->city->name }}</span>@endif
                            @if ($type === $Type::FillVacancy && $availableSeats !== null)
                                <span aria-hidden="true">·</span>
                                <span class="num">{{ __('recruitment.form_ui.seats_open', ['count' => $availableSeats]) }}</span>
                            @endif
                        </p>
                    @endif
                </div>

                @if ($type === $Type::FillVacancy && $vacancies->count() > 1)
                    <div class="min-w-0 md:col-span-2">
                        @include('livewire.recruitment.requests.partials.select', [
                            'field' => 'vacancy_id', 'label' => 'vacancy',
                            'options' => $vacancies->mapWithKeys(fn ($vacancy) => [$vacancy->id => __('recruitment.vacancy').' #'.$vacancy->id.' · '.__('recruitment.remaining_slots', ['count' => $vacancy->needed_count - $vacancy->filled_count])])->all(),
                            'hint' => __('recruitment.form_ui.vacancy_help'),
                        ])
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- The proposed post, right after the overview: it is what the request is about. --}}
    @if ($newPosition)
        <section aria-labelledby="request-proposed-position" class="{{ $sectionClass }}">
            @include('livewire.recruitment.requests.partials.section-header', [
                'number' => ++$section, 'id' => 'request-proposed-position',
                'title' => __('recruitment.proposed_position'),
                'lead' => __('recruitment.form_ui.proposed_lead'),
            ])
            <div class="{{ $grid }}">
                @include('livewire.recruitment.requests.partials.input', ['field' => 'title_en', 'inputType' => 'text', 'dir' => 'ltr'])
                @include('livewire.recruitment.requests.partials.input', ['field' => 'title_ar', 'inputType' => 'text', 'dir' => 'rtl'])
                @include('livewire.recruitment.requests.partials.select', ['field' => 'city_id', 'label' => 'city', 'options' => $cities->pluck('name', 'id')->all(), 'live' => true])
                @include('livewire.recruitment.requests.partials.select', ['field' => 'program_id', 'label' => 'program', 'options' => $programs->pluck('name', 'id')->all(), 'live' => true])
                @include('livewire.recruitment.requests.partials.select', [
                    'field' => 'center_id', 'label' => 'center',
                    'options' => $centers->pluck('name', 'id')->all(),
                    'required' => $centerRequired,
                    'hint' => $centerRequired ? null : __('recruitment.center_optional_help'),
                ])
                @include('livewire.recruitment.requests.partials.select', ['field' => 'department_id', 'label' => 'department', 'options' => $departments->pluck('name', 'id')->all()])
                @include('livewire.recruitment.requests.partials.select', ['field' => 'job_level_id', 'label' => 'job_level', 'options' => $jobLevels->pluck('name', 'id')->all()])
                @include('livewire.recruitment.requests.partials.input', [
                    'field' => 'max_emp', 'inputType' => 'number', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric',
                    'hint' => __('recruitment.form_ui.max_emp_help'),
                ])
                <div class="min-w-0 md:col-span-2">
                    @include('livewire.recruitment.requests.partials.textarea', ['field' => 'qualifications', 'rows' => 4])
                </div>
                <div class="min-w-0 md:col-span-2">
                    @include('livewire.recruitment.requests.partials.textarea', ['field' => 'responsibilities', 'rows' => 4])
                </div>
            </div>
        </section>
    @endif

    @if ($hires)
        {{-- B · Recruitment requirements --}}
        <section aria-labelledby="request-requirements" class="{{ $sectionClass }}">
            @include('livewire.recruitment.requests.partials.section-header', [
                'number' => ++$section, 'id' => 'request-requirements',
                'title' => __('recruitment.form_ui.requirements'),
                'lead' => __('recruitment.form_ui.requirements_lead'),
            ])
            <div class="{{ $grid }}">
                @include('livewire.recruitment.requests.partials.select', [
                    'field' => 'recruitment_reason', 'label' => 'recruitment_reason',
                    'options' => $choices('recruitment_reasons', 'reason_'), 'live' => true,
                ])
                @include('livewire.recruitment.requests.partials.choice', [
                    'field' => 'priority', 'label' => 'priority', 'options' => $choices('priorities', 'priority_'),
                ])

                @if (($data['recruitment_reason'] ?? null) === 'other')
                    <div class="min-w-0 md:col-span-2">
                        @include('livewire.recruitment.requests.partials.textarea', ['field' => 'other_reason', 'rows' => 2])
                    </div>
                @endif

                @include('livewire.recruitment.requests.partials.input', [
                    'field' => 'needed_by_date', 'inputType' => 'date', 'update' => 'blur', 'min' => $tomorrow,
                    'hint' => __('recruitment.form_ui.needed_by_help'),
                ])

                @if ($type === $Type::IncreaseHeadcount)
                    @include('livewire.recruitment.requests.partials.input', [
                        'field' => 'additional_count', 'inputType' => 'number', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric',
                        'hint' => __('recruitment.form_ui.additional_count_help'),
                    ])
                @else
                    @include('livewire.recruitment.requests.partials.input', [
                        'field' => 'volunteers_required', 'inputType' => 'number', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric',
                        'max' => $availableSeats ?: null,
                        'hint' => $type === $Type::FillVacancy && $availableSeats !== null
                            ? __('recruitment.volunteers_required_help', ['count' => $availableSeats])
                            : __('recruitment.form_ui.volunteers_required_new_help'),
                    ])
                @endif

                <div class="min-w-0 md:col-span-2">
                    @include('livewire.recruitment.requests.partials.textarea', [
                        'field' => 'detailed_explanation', 'rows' => 5,
                        'placeholder' => __('recruitment.form_ui.justification_placeholder'),
                        'hint' => __('recruitment.detailed_explanation_help'),
                    ])
                </div>
            </div>
        </section>

        {{-- C · Work arrangement & contract --}}
        <section aria-labelledby="request-arrangement" class="{{ $sectionClass }}">
            @include('livewire.recruitment.requests.partials.section-header', [
                'number' => ++$section, 'id' => 'request-arrangement',
                'title' => __('recruitment.form_ui.arrangement'),
                'lead' => __('recruitment.form_ui.arrangement_lead'),
            ])
            <div class="{{ $grid }}">
                @include('livewire.recruitment.requests.partials.choice', [
                    'field' => 'volunteer_type', 'label' => 'volunteer_type',
                    'options' => $choices('volunteer_types', 'volunteer_type_'), 'live' => true,
                ])
                @include('livewire.recruitment.requests.partials.input', [
                    'field' => 'weekly_hours', 'inputType' => 'number', 'min' => 1, 'max' => 168, 'step' => 1, 'inputmode' => 'numeric',
                ])
                @include('livewire.recruitment.requests.partials.input', [
                    'field' => 'volunteer_period_start_date', 'inputType' => 'date', 'update' => 'blur', 'min' => $startMin,
                    'hint' => __('recruitment.form_ui.period_start_help'),
                ])
                @include('livewire.recruitment.requests.partials.input', [
                    'field' => 'volunteer_period_end_date', 'inputType' => 'date', 'min' => $endMin,
                    'hint' => __('recruitment.form_ui.period_end_help'),
                ])
                @include('livewire.recruitment.requests.partials.select', [
                    'field' => 'contract_type_id', 'label' => 'contract_type',
                    'options' => $contractTypes->pluck('name', 'id')->all(),
                ])
            </div>
        </section>

        {{-- D · Compensation: the amount, its currency and what it is paid per
             are one figure, so they are one group. --}}
        @php
            $payLocked = array_values(array_intersect(['salary_amount', 'currency'], $lockedFields ?? []));
            $payRequested = array_values(array_diff(array_intersect(['salary_amount', 'currency'], $requestedFields ?? []), $payLocked));
            $amountLocked = in_array('salary_amount', $payLocked, true);
            $currencyLocked = in_array('currency', $payLocked, true);
            $payErrors = array_filter([
                $errors->has('data.salary_amount') ? 'salary_amount-error' : null,
                $errors->has('data.currency') ? 'currency-error' : null,
            ]);
            $lockedClass = 'cursor-not-allowed border-secondary-light bg-white text-secondary-dark shadow-none';
        @endphp
        <section aria-labelledby="request-compensation" class="{{ $sectionClass }}">
            @include('livewire.recruitment.requests.partials.section-header', [
                'number' => ++$section, 'id' => 'request-compensation',
                'title' => __('recruitment.form_ui.compensation'),
                'lead' => __('recruitment.form_ui.compensation_lead'),
            ])
            <div class="{{ $grid }}">
                <div wire:key="field-salary-group" @class([
                    'min-w-0 rounded-control transition',
                    'border-s-2 border-primary ps-3' => $payRequested !== [],
                ])>
                    <div class="mb-1 flex flex-wrap items-center gap-2">
                        <x-forms.input-label for="salary_amount" required>{{ __('recruitment.salary_amount') }}</x-forms.input-label>
                        @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => $payRequested !== [], 'isLocked' => false])
                    </div>
                    <div class="flex min-w-0" dir="ltr">
                        <input id="salary_amount" type="number" min="0" step="1" inputmode="numeric"
                            wire:model="data.salary_amount"
                            @disabled($amountLocked)
                            @if ($errors->has('data.salary_amount')) aria-invalid="true" @endif
                            aria-describedby="{{ implode(' ', array_merge(['salary_amount-hint'], $payErrors)) }}"
                            @class(['hc-field num min-w-0 flex-1 rounded-e-none', $lockedClass => $amountLocked])>
                        <label for="currency" class="sr-only">{{ __('recruitment.currency') }}</label>
                        <select id="currency" wire:model="data.currency"
                            @disabled($currencyLocked)
                            @if ($errors->has('data.currency')) aria-invalid="true" @endif
                            @if ($errors->has('data.currency')) aria-describedby="currency-error" @endif
                            @class(['hc-field -ms-px w-auto max-w-[45%] shrink-0 rounded-s-none', $lockedClass => $currencyLocked])>
                            <option value="">{{ __('recruitment.currency') }}</option>
                            @foreach ([1, 2, 3] as $currency)
                                <option value="{{ $currency }}">{{ \App\Enums\Currency::getLocalizedName($currency)[app()->getLocale()] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-forms.hint id="salary_amount-hint">{{ __('recruitment.form_ui.salary_help') }}</x-forms.hint>
                    @include('livewire.recruitment.requests.partials.field-state', ['isChangeRequested' => false, 'isLocked' => $payLocked !== []])
                    <x-forms.input-error field="data.salary_amount" input-id="salary_amount" />
                    <x-forms.input-error field="data.currency" input-id="currency" />
                </div>
                @include('livewire.recruitment.requests.partials.choice', [
                    'field' => 'salary_per', 'label' => 'salary_per',
                    'options' => collect([1, 2, 3])->mapWithKeys(fn ($value) => [$value => \App\Enums\SalaryPer::getLocalizedName($value)['value']])->all(),
                ])
            </div>
        </section>
    @endif

    @if ($closes)
        {{-- Closing a post or a vacancy: why, and from when. --}}
        <section aria-labelledby="request-closure" class="{{ $sectionClass }}">
            @include('livewire.recruitment.requests.partials.section-header', [
                'number' => ++$section, 'id' => 'request-closure',
                'title' => __('recruitment.form_ui.closure'),
                'lead' => __('recruitment.form_ui.closure_lead'),
            ])
            <div class="{{ $grid }}">
                @if ($type === $Type::DecreaseHeadcount)
                    @include('livewire.recruitment.requests.partials.input', ['field' => 'reduce_by', 'inputType' => 'number', 'min' => 1, 'step' => 1, 'inputmode' => 'numeric'])
                @endif
                @include('livewire.recruitment.requests.partials.input', [
                    'field' => 'effective_date', 'inputType' => 'date',
                    'hint' => __('recruitment.form_ui.effective_date_help'),
                ])
                <div class="min-w-0 md:col-span-2">
                    @include('livewire.recruitment.requests.partials.textarea', [
                        'field' => 'reason', 'rows' => 4,
                        'placeholder' => __('recruitment.form_ui.closure_reason_placeholder'),
                    ])
                </div>
            </div>
        </section>
    @endif
</div>
