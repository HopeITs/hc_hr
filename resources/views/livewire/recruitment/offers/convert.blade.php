@php
    use App\Enums\Gender;
    use App\Enums\MaritalStatus;
    use App\Enums\Nationality;
    use App\Enums\SessionDuration;
    use App\Services\RecruitmentHiringConversionService as Conversions;

    $applicant = $offer->application?->applicant;
    $vacancy = $offer->vacancy;
    $position = $offer->position;
    $field = fn (string $key) => 'employee.'.$key;

    // The identity rule (Conversions::matches()): only the same person can be linked.
    $linkable = $matches->where('kind', Conversions::MATCH_LINKABLE);
    $warnings = $matches->where('kind', Conversions::MATCH_WARNING);
    $conflicts = $matches->where('kind', Conversions::MATCH_ID_CONFLICT);
@endphp

<x-slot:title>{{ __('recruitment-applicant.conversions.title') }}</x-slot:title>

<div class="space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.conversions.title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.offers.title'), 'route' => 'recruitment.offers.index'], ['label' => __('recruitment-applicant.conversions.title')]]" />
    <div>
        <a href="{{ route('recruitment.offers.show', ['offerId' => $offer->id]) }}" wire:navigate class="text-sm text-primary hover:underline">&larr; {{ __('recruitment-applicant.interviews.back') }}</a>
        <p class="mt-1 text-sm text-secondary-medium">
            {{ $applicant?->full_name }} &middot; {{ $position?->title }} &middot; {{ __('recruitment-applicant.offers.document_title') }} v{{ $version?->version_no }}
        </p>
    </div>

    @error('conversion')
        <p role="alert" class="rounded-control border border-primary bg-primary/5 p-3 text-sm text-primary">{{ $message }}</p>
    @enderror

    @if ($converted)
        {{-- Done: what was created, and by whom --}}
        <section class="space-y-3 rounded-surface border border-primary/30 bg-primary/5 p-6 text-sm text-primary">
            <p class="font-semibold">{{ __('recruitment-applicant.conversions.statuses.converted') }}</p>
            <p>{{ __('recruitment-applicant.conversions.converted_by', ['name' => $conversion->convertedBy?->profile?->name ?? $conversion->convertedBy?->email ?? '—', 'date' => $conversion->converted_at?->format('Y-m-d H:i')]) }}</p>
            <div class="flex flex-wrap gap-3">
                @if ($conversion->employee?->username)
                    <a href="{{ route('employees.show', $conversion->employee->username) }}" class="font-medium text-primary hover:underline">{{ __('recruitment-applicant.conversions.open_employee') }}: {{ $conversion->employee->name }}</a>
                @endif
                @if ($conversion->contract)
                    <a href="{{ route('contracts.show', $conversion->contract->id) }}" class="font-medium text-primary hover:underline">{{ __('recruitment-applicant.conversions.open_contract') }} #{{ $conversion->contract->id }}</a>
                @endif
                @if ($conversion->employee)
                    <a href="{{ route('employees.edit', $conversion->employee->id) }}" wire:navigate class="font-medium text-primary hover:underline">{{ __('recruitment-applicant.conversions.open_employee_edit') }}</a>
                @endif
            </div>
            @if ($conversion->employee && ! $conversion->employee->account)
                <p>{{ __('recruitment-applicant.conversions.no_account', ['label' => __('general.create_login_account')]) }}</p>
            @endif
        </section>
    @else
        <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.conversions.hint') }}</p>

        {{-- What stops the hire right now; checked again on the locked rows when HR confirms --}}
        @if ($blockers !== [])
            <section role="alert" class="space-y-2 rounded-surface border border-primary bg-primary/5 p-4 text-sm">
                <p class="font-semibold text-primary">{{ __('recruitment-applicant.conversions.blockers_title') }}</p>
                <ul class="list-disc space-y-1 ps-5">
                    @foreach ($blockers as $code)
                        <li>{{ __('recruitment-applicant.conversions.errors.'.$code) }}</li>
                    @endforeach
                </ul>
                <p class="text-secondary-medium">{{ __('recruitment-applicant.conversions.blockers_hint') }}</p>
            </section>
        @endif

        @if ($conversion->status === \App\Models\RecruitmentHiringConversion::STATUS_FAILED && $conversion->failure_reason)
            <p class="rounded-control bg-secondary-soft/30 p-3 text-sm">
                {{ __('recruitment-applicant.conversions.last_failure', ['count' => $conversion->attempt_count, 'reason' => __('recruitment-applicant.conversions.errors.'.$conversion->failure_reason)]) }}
            </p>
        @endif

        {{-- The accepted snapshot: the contract takes these, unchanged --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.conversions.accepted_terms') }}</h2>
            @if ($version)
                @include('livewire.recruitment.offers.partials.terms', ['version' => $version])
            @endif
            <p class="mt-4 text-xs text-secondary-medium">
                {{ __('recruitment-applicant.conversions.seat', [
                    'filled' => (int) $vacancy?->filled_count, 'needed' => (int) $vacancy?->needed_count,
                    'exist' => (int) $position?->exist_emp, 'max' => (int) $position?->max_emp,
                ]) }}
                &middot; {{ __('recruitment-applicant.conversions.schedule_hint') }}
            </p>
        </section>

        <form wire:submit="confirmHire" class="space-y-6">
            {{-- Who the employee is --}}
            <section class="space-y-4 rounded-surface border border-secondary-soft bg-white p-6">
                <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.conversions.employee_section') }}</h2>

                {{-- The national number is on file under another name: the hire stops. --}}
                @foreach ($conflicts as $match)
                    <div role="alert" class="rounded-control border border-primary bg-primary/5 p-3 text-sm text-primary" wire:key="conflict-{{ $match['employee']->id }}">
                        <p class="font-semibold">{{ __('recruitment-applicant.conversions.id_conflict_title') }}</p>
                        <p>{{ __('recruitment-applicant.conversions.id_conflict', ['number' => $employee['nationalNumber'] ?? '', 'name' => $match['employee']->name]) }}</p>
                    </div>
                @endforeach

                <div class="space-y-2">
                    <h3 class="text-sm font-semibold">{{ __('recruitment-applicant.conversions.matches_title') }}</h3>
                    <p class="text-xs text-secondary-medium">{{ __('recruitment-applicant.conversions.matches_hint') }}</p>
                    @forelse ($linkable as $match)
                        <label class="flex items-start gap-3 rounded-control border border-secondary-soft p-3 text-sm" wire:key="match-{{ $match['employee']->id }}">
                            <input type="radio" wire:model.live="employeeId" value="{{ $match['employee']->id }}" @disabled($mode !== Conversions::MODE_LINK) class="mt-1 text-primary">
                            <span>
                                <span class="font-medium">{{ $match['employee']->name }}</span>
                                <span class="text-secondary-medium">&middot; {{ $match['employee']->city?->name }} &middot; {{ $match['employee']->username }}
                                    @unless ($match['employee']->is_active) &middot; {{ __('recruitment-applicant.conversions.inactive_employee') }} @endunless
                                </span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($match['reasons'] as $reason)
                                        <span class="rounded-control bg-secondary-soft/60 px-2 py-0.5 text-xs">{{ __('recruitment-applicant.conversions.match_reasons.'.$reason) }}</span>
                                    @endforeach
                                </span>
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-secondary-medium">{{ $matches->isEmpty() ? __('recruitment-applicant.conversions.no_matches') : __('recruitment-applicant.conversions.no_linkable') }}</p>
                    @endforelse
                    <x-forms.input-error field="employeeId" input-id="employeeId" />
                </div>

                {{-- An email or phone on someone else's record: said plainly, never linked. --}}
                @if ($warnings->isNotEmpty())
                    <div role="status" class="space-y-1 rounded-control border border-secondary-light bg-secondary-soft/30 p-3 text-sm">
                        @foreach ($warnings as $match)
                            @foreach (array_intersect([Conversions::MATCH_PHONE, Conversions::MATCH_EMAIL], $match['reasons']) as $reason)
                                <p class="font-medium" wire:key="warning-{{ $match['employee']->id }}-{{ $reason }}">{{ __('recruitment-applicant.conversions.warnings.'.$reason, ['name' => $match['employee']->name]) }}</p>
                            @endforeach
                        @endforeach
                        <p class="text-secondary-medium">{{ __('recruitment-applicant.conversions.warning_hint') }}</p>
                    </div>
                @endif

                <fieldset class="flex flex-wrap gap-4 text-sm">
                    <legend class="sr-only">{{ __('recruitment-applicant.conversions.employee_section') }}</legend>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="mode" value="{{ Conversions::MODE_CREATE }}" class="text-primary"> {{ __('recruitment-applicant.conversions.mode_create') }}</label>
                    <label class="flex items-center gap-2"><input type="radio" wire:model.live="mode" value="{{ Conversions::MODE_LINK }}" @disabled($linkable->isEmpty()) class="text-primary"> {{ __('recruitment-applicant.conversions.mode_link') }}</label>
                </fieldset>
                <x-forms.input-error field="mode" input-id="mode" />

                @if ($mode === Conversions::MODE_CREATE)
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        @foreach (['firstName' => 'general.first_name', 'lastName' => 'general.last_name', 'fatherName' => 'general.father_name', 'motherName' => 'general.mother_name'] as $name => $label)
                            @foreach (['En', 'Ar'] as $locale)
                                <div wire:key="field-{{ $name.$locale }}">
                                    <x-forms.input-label for="{{ $name.$locale }}" required>{{ __($label) }} ({{ strtoupper($locale) }})</x-forms.input-label>
                                    <x-forms.input id="{{ $name.$locale }}" :model="$field($name.$locale)" :dir="$locale === 'Ar' ? 'rtl' : 'ltr'" required />
                                    <x-forms.input-error :field="$field($name.$locale)" input-id="{{ $name.$locale }}" />
                                </div>
                            @endforeach
                        @endforeach

                        <div>
                            <x-forms.input-label for="nationality" required>{{ __('general.nationality') }}</x-forms.input-label>
                            <x-forms.select2-input id="nationality" wire:model.live="employee.nationality">
                                @foreach (Nationality::getValues() as $value)
                                    <option value="{{ $value }}">{{ Nationality::getLocalizedName($value) }}</option>
                                @endforeach
                            </x-forms.select2-input>
                            <x-forms.input-error field="employee.nationality" input-id="nationality" />
                        </div>
                        <div>
                            <x-forms.input-label for="nationalNumber" required>{{ __('general.national_number') }}</x-forms.input-label>
                            <x-forms.input id="nationalNumber" :model="$field('nationalNumber')" update="blur" inputmode="numeric" required />
                            <x-forms.input-error field="employee.nationalNumber" input-id="nationalNumber" />
                        </div>
                        <div>
                            <x-forms.input-label for="gender" required>{{ __('general.gender') }}</x-forms.input-label>
                            <x-forms.select2-input id="gender" wire:model="employee.gender">
                                <option value="">{{ __('recruitment.choose') }}</option>
                                <option value="{{ Gender::Male }}">{{ __('general.male') }}</option>
                                <option value="{{ Gender::Female }}">{{ __('general.female') }}</option>
                            </x-forms.select2-input>
                            <x-forms.input-error field="employee.gender" input-id="gender" />
                        </div>
                        <div>
                            <x-forms.input-label for="maritalStatus" required>{{ __('general.marital_status') }}</x-forms.input-label>
                            <x-forms.select2-input id="maritalStatus" wire:model="employee.maritalStatus">
                                <option value="">{{ __('recruitment.choose') }}</option>
                                @foreach (MaritalStatus::getValues() as $value)
                                    <option value="{{ $value }}">{{ MaritalStatus::getLocalizedName($value)['value'] }}</option>
                                @endforeach
                            </x-forms.select2-input>
                            <x-forms.input-error field="employee.maritalStatus" input-id="maritalStatus" />
                        </div>
                        <div>
                            <x-forms.input-label for="dob">{{ __('general.dob') }}</x-forms.input-label>
                            <x-forms.input id="dob" type="date" :model="$field('dob')" />
                            <x-forms.input-error field="employee.dob" input-id="dob" />
                        </div>
                        <div>
                            <x-forms.input-label for="username" required>{{ __('general.username') }}</x-forms.input-label>
                            <x-forms.input id="username" :model="$field('username')" dir="ltr" required />
                            <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.conversions.username_hint') }}</p>
                            <x-forms.input-error field="employee.username" input-id="username" />
                        </div>
                        <div>
                            <x-forms.input-label for="personalPhone">{{ __('general.personal_phone') }}</x-forms.input-label>
                            <x-forms.input id="personalPhone" :model="$field('personalPhone')" dir="ltr" inputmode="tel" />
                            <x-forms.input-error field="employee.personalPhone" input-id="personalPhone" />
                        </div>
                        <div>
                            <x-forms.input-label for="personalEmail">{{ __('general.personal_email') }}</x-forms.input-label>
                            <x-forms.input id="personalEmail" type="email" :model="$field('personalEmail')" dir="ltr" />
                            <x-forms.input-error field="employee.personalEmail" input-id="personalEmail" />
                        </div>
                    </div>

                    @if ($linkable->isNotEmpty())
                        <div>
                            <x-forms.input id="confirmNotDuplicate" type="checkbox" model="confirmNotDuplicate">{{ __('recruitment-applicant.conversions.confirm_not_duplicate') }}</x-forms.input>
                            <x-forms.input-error field="confirmNotDuplicate" input-id="confirmNotDuplicate" />
                        </div>
                    @endif
                @endif
            </section>

            @if ($needsSessionDuration)
                <section class="space-y-3 rounded-surface border border-secondary-soft bg-white p-6">
                    <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.conversions.contract_section') }}</h2>
                    <div class="max-w-xs">
                        <x-forms.input-label for="sessionDuration" required>{{ __('recruitment-applicant.conversions.session_duration') }}</x-forms.input-label>
                        <x-forms.select2-input id="sessionDuration" wire:model="sessionDuration">
                            <option value="">{{ __('recruitment.choose') }}</option>
                            @foreach ([SessionDuration::Hour, SessionDuration::HourAndHalf, SessionDuration::TwoHours] as $value)
                                <option value="{{ $value }}">{{ SessionDuration::getLocalizedName($value)['value'] }}</option>
                            @endforeach
                        </x-forms.select2-input>
                        <x-forms.input-error field="sessionDuration" input-id="sessionDuration" />
                    </div>
                </section>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                {{-- Disabled only while the hire itself runs: a field update fired by leaving an input (national number) must not disable it under the click. --}}
                <x-primary-button type="submit" wire:target="confirmHire,save">{{ __('recruitment-applicant.conversions.confirm') }}</x-primary-button>
                <p class="text-xs text-secondary-medium">{{ __('recruitment-applicant.conversions.confirm_hint') }}</p>
            </div>
        </form>
    @endif
</div>
