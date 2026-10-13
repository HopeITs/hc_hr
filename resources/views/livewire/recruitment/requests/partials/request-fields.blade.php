{{--
    The request form itself, shared by the two ways a request is raised.

    A manager submits one for a post beneath them and HR opens one directly
    for a hire already agreed; what is being asked for is the same either
    way, and duplicating it would let the two drift until a field existed on
    one screen and not the other.

    Every list it renders is narrowed by whoever composed it. That is a
    convenience, never the guard: the service re-derives the same sets and
    refuses anything outside them.
--}}
        <section class="space-y-5 rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.request_scope') }}</h2>
            @php
                $type = (int) ($data['request_type'] ?? 0);
                $newPosition = $type === \App\Enums\RecruitmentRequestType::CreatePosition;
                $types = collect(\App\Enums\RecruitmentRequestType::getValues())
                    ->reject(fn ($value) => in_array($value, [
                        \App\Enums\RecruitmentRequestType::Unknown,
                        \App\Enums\RecruitmentRequestType::DecreaseHeadcount,
                    ], true))
                    ->mapWithKeys(fn ($value) => [$value => \App\Enums\RecruitmentRequestType::getLocalizedName($value)['value']])->all();
                $positionOptions = $positions->mapWithKeys(fn ($position) => [$position->id => $position->title.' · '.$position->program?->name.' · '.$position->city?->name])->all();
            @endphp
            @include('livewire.recruitment.requests.partials.select', ['field' => 'request_type', 'label' => 'type', 'options' => $types, 'required' => true])
            @include('livewire.recruitment.requests.partials.select', ['field' => $newPosition ? 'direct_manager_id' : 'position_id', 'label' => $newPosition ? 'parent_position' : 'position', 'options' => $positionOptions, 'required' => true])
            <p class="text-sm text-secondary-medium">{{ __($newPosition ? 'recruitment.parent_help' : 'recruitment.target_help') }}</p>
            @if ($positions->isEmpty())<p role="status" class="text-sm text-primary">{{ __('recruitment.no_targets') }}</p>@endif
            @if ($type === \App\Enums\RecruitmentRequestType::FillVacancy && $vacancies->count() > 1)
                @include('livewire.recruitment.requests.partials.select', ['field' => 'vacancy_id', 'label' => 'vacancy', 'options' => $vacancies->mapWithKeys(fn ($vacancy) => [$vacancy->id => __('recruitment.vacancy').' #'.$vacancy->id.' · '.__('recruitment.remaining_slots', ['count' => $vacancy->needed_count - $vacancy->filled_count])])->all(), 'required' => true])
            @endif
        </section>
        @if ($newPosition)
            <section class="space-y-5 rounded-surface border border-secondary-soft bg-white p-6">
                <h2 class="text-h4 font-semibold">{{ __('recruitment.proposed_position') }}</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'city_id', 'label' => 'city', 'options' => $cities->pluck('name', 'id')->all(), 'required' => true])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'program_id', 'label' => 'program', 'options' => $programs->pluck('name', 'id')->all(), 'required' => true])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'center_id', 'label' => 'center', 'options' => $centers->pluck('name', 'id')->all(), 'required' => $centerRequired])
                    @unless ($centerRequired)<p class="text-sm text-secondary-medium sm:col-span-2">{{ __('recruitment.center_optional_help') }}</p>@endunless
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'department_id', 'label' => 'department', 'options' => $departments->pluck('name', 'id')->all(), 'required' => true])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'job_level_id', 'label' => 'job_level', 'options' => $jobLevels->pluck('name', 'id')->all(), 'required' => true])
                    @foreach (['title_en' => 'text', 'title_ar' => 'text', 'max_emp' => 'number'] as $field => $inputType)
                        @include('livewire.recruitment.requests.partials.input')
                    @endforeach
                </div>
                @foreach (['qualifications', 'responsibilities'] as $field)
                    @include('livewire.recruitment.requests.partials.textarea')
                @endforeach
            </section>
        @endif
        <section class="space-y-5 rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.request_content') }}</h2>
            @if (in_array($type, [1, 2, 4], true))
                @php
                    $form = config('recruitment.request_form');
                    $options = fn (string $group, string $prefix) => collect($form[$group])
                        ->mapWithKeys(fn ($value) => [$value => __('recruitment.'.$prefix.$value)])->all();
                    $selectedPosition = $positions->firstWhere('id', (int) ($data['position_id'] ?? 0));
                    $availableSeats = null;
                    if ($type === \App\Enums\RecruitmentRequestType::CreatePosition) {
                        $availableSeats = max(0, (int) ($data['max_emp'] ?? 0));
                    } elseif ($selectedPosition) {
                        $availableSeats = $vacancies->isNotEmpty()
                            ? $vacancies->sum(fn ($vacancy) => max(0, (int) $vacancy->needed_count - (int) $vacancy->filled_count))
                            : max(0, (int) $selectedPosition->max_emp - (int) $selectedPosition->exist_emp);
                    }
                @endphp
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        @include('livewire.recruitment.requests.partials.select', ['field' => 'recruitment_reason', 'label' => 'recruitment_reason', 'options' => $options('recruitment_reasons', 'reason_'), 'required' => true])
                    </div>
                    @if (in_array($type, [\App\Enums\RecruitmentRequestType::FillVacancy, \App\Enums\RecruitmentRequestType::CreatePosition], true))
                        <div>
                            @include('livewire.recruitment.requests.partials.input', ['field' => 'volunteers_required', 'inputType' => 'number'])
                            @if ($type === \App\Enums\RecruitmentRequestType::FillVacancy && $availableSeats !== null)
                                <p class="mt-2 text-sm text-secondary-medium">{{ __('recruitment.volunteers_required_help', ['count' => $availableSeats]) }}</p>
                            @endif
                        </div>
                    @else
                        @include('livewire.recruitment.requests.partials.input', ['field' => 'needed_by_date', 'inputType' => 'date'])
                    @endif
                </div>
                @if (($data['recruitment_reason'] ?? null) === 'other')
                    @include('livewire.recruitment.requests.partials.textarea', ['field' => 'other_reason'])
                @endif
                <div class="grid gap-5 sm:grid-cols-2">
                    @if (in_array($type, [\App\Enums\RecruitmentRequestType::FillVacancy, \App\Enums\RecruitmentRequestType::CreatePosition], true))
                        @include('livewire.recruitment.requests.partials.input', ['field' => 'needed_by_date', 'inputType' => 'date'])
                    @endif
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'priority', 'label' => 'priority', 'options' => $options('priorities', 'priority_'), 'required' => true])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'volunteer_type', 'label' => 'volunteer_type', 'options' => $options('volunteer_types', 'volunteer_type_'), 'required' => true])
                    @include('livewire.recruitment.requests.partials.input', ['field' => 'weekly_hours', 'inputType' => 'number'])
                    @include('livewire.recruitment.requests.partials.input', ['field' => 'volunteer_period_start_date', 'inputType' => 'date'])
                    @include('livewire.recruitment.requests.partials.input', ['field' => 'volunteer_period_end_date', 'inputType' => 'date'])
                    @if ($type === 2)
                        @include('livewire.recruitment.requests.partials.input', ['field' => 'additional_count', 'inputType' => 'number'])
                    @endif
                </div>
            @endif
            @if ($type === 3)
                @include('livewire.recruitment.requests.partials.input', ['field' => 'reduce_by', 'inputType' => 'number'])
            @endif
            @if (in_array($type, [3, 5, 6], true))
                @include('livewire.recruitment.requests.partials.textarea', ['field' => 'reason'])
            @endif
            @if (in_array($type, [3, 5, 6], true))
                @include('livewire.recruitment.requests.partials.input', ['field' => 'effective_date', 'inputType' => 'date'])
            @endif
            @if (in_array($type, [1, 2, 4], true))
                <div class="grid gap-5 sm:grid-cols-2">
                    @include('livewire.recruitment.requests.partials.input', ['field' => 'salary_amount', 'inputType' => 'number'])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'currency', 'label' => 'currency', 'options' => collect([1, 2, 3])->mapWithKeys(fn ($value) => [$value => \App\Enums\Currency::getLocalizedName($value)[app()->getLocale()]])->all(), 'required' => true])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'salary_per', 'label' => 'salary_per', 'options' => collect([1, 2, 3])->mapWithKeys(fn ($value) => [$value => \App\Enums\SalaryPer::getLocalizedName($value)['value']])->all(), 'required' => true])
                    @include('livewire.recruitment.requests.partials.select', ['field' => 'contract_type_id', 'label' => 'contract_type', 'options' => $contractTypes->pluck('name', 'id')->all(), 'required' => true])
                </div>
                @include('livewire.recruitment.requests.partials.textarea', ['field' => 'detailed_explanation'])
                <p class="-mt-3 text-sm text-secondary-medium">{{ __('recruitment.detailed_explanation_help') }}</p>
            @endif
        </section>
