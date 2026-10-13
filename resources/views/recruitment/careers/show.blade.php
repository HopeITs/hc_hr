@extends('layouts.public-recruitment')

@section('title', $job_post['job_title'] ?: __('careers.title'))
@section('meta_description', $job_post['body'][$locale] ?: __('careers.meta_description'))
@section('robots', $link_mode === 'public_open' ? 'index, follow' : 'noindex, nofollow')

@section('content')
@php
    $direction = $locale === 'ar' ? 'rtl' : 'ltr';
    $title = $job_post['title'][$locale] ?: $job_post['title']['en'];
    $body = $job_post['body'][$locale] ?: $job_post['body']['en'];
    $sections = collect($form['sections'])->keyBy('key');
    $fieldsBySection = collect($form['fields'])->groupBy('section');
    $stepCount = $fieldsBySection->count();
    $internal ??= null;
    $invitation ??= null;
    $invitedEmail = ($invitation['state'] ?? null) === 'ready' ? $invitation['email'] : null;
    $showForm = ($internal === null || $internal['state'] === 'ready')
        && ($invitation === null || $invitation['state'] === 'ready');
    $prefill = $internal['prefill'] ?? ($invitedEmail !== null ? ['email' => $invitedEmail] : []);
    $careerUrl = fn (string $language): string => route('careers.show', [
        'token' => request()->route('token'),
        'lang' => $language,
    ]);
    $canonicalUrl = $careerUrl($locale);
@endphp

<main dir="{{ $direction }}" class="mx-auto w-full max-w-4xl px-4 py-6 sm:px-6 sm:py-10">
    <div class="mb-4 flex justify-end gap-2 text-small font-semibold">
        <a href="{{ $careerUrl('en') }}" class="cursor-pointer rounded-full px-3 py-1.5 {{ $locale === 'en' ? 'bg-primary text-white' : 'bg-white text-neutral-600' }}">EN</a>
        <a href="{{ $careerUrl('ar') }}" class="cursor-pointer rounded-full px-3 py-1.5 {{ $locale === 'ar' ? 'bg-primary text-white' : 'bg-white text-neutral-600' }}">AR</a>
    </div>

    <x-recruitment.public-brand-banner />

    <section class="mt-4 overflow-hidden rounded-[16px] border border-neutral-200 bg-white shadow-e1">
        <div class="border-t-[7px] border-primary px-6 py-6 sm:px-9 sm:py-8">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-small font-semibold uppercase tracking-wide text-primary">{{ __('careers.vacancy_announcement') }}</p>
                    <h1 class="mt-2 text-title font-bold leading-tight text-neutral-900">{{ $title }}</h1>
                </div>
                @if ($link_mode === 'invitation_only')
                    <span class="rounded-full border border-primary bg-white px-3 py-1 text-small font-semibold text-primary">{{ __('careers.invitation_only') }}</span>
                @elseif ($internal)
                    <span class="rounded-full border border-primary bg-white px-3 py-1 text-small font-semibold text-primary">{{ __('careers.internal.badge') }}</span>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-body text-neutral-600">
                @if ($job_post['city']) <p><strong>{{ __('careers.city') }}:</strong> {{ $job_post['city'] }}</p> @endif
                @if ($job_post['program']) <p><strong>{{ __('careers.program') }}:</strong> {{ $job_post['program'] }}</p> @endif
                @if ($context['weekly_hours']) <p><strong>{{ __('careers.working_hours') }}:</strong> {{ $context['weekly_hours'] }}</p> @endif
                @if ($context['deadline']) <p><strong>{{ __('careers.deadline') }}:</strong> {{ $context['deadline'] }}</p> @endif
                @if ($age_range['minimum'] !== null && $age_range['maximum'] !== null)
                    <p><strong>{{ __('careers.age_requirement') }}:</strong> {{ __('careers.age_requirement_value', ['minimum' => $age_range['minimum'], 'maximum' => $age_range['maximum']]) }}</p>
                @endif
            </div>

            @if ($body)
                <div class="mt-6 whitespace-pre-line border-t border-neutral-200 pt-5 text-body leading-7 text-neutral-700">{{ $body }}</div>
            @endif
        </div>
    </section>

    @if (($context['responsibilities'][$locale] ?? $context['responsibilities']['en'] ?? '') || ($context['qualifications'][$locale] ?? $context['qualifications']['en'] ?? ''))
        <section class="mt-4 rounded-[16px] border border-neutral-200 bg-white px-6 py-6 shadow-e1 sm:px-9">
            @if ($responsibilities = ($context['responsibilities'][$locale] ?? $context['responsibilities']['en'] ?? ''))
                <h2 class="font-bold text-neutral-900">{{ __('careers.responsibilities') }}</h2>
                <p class="mt-2 whitespace-pre-line text-body leading-7 text-neutral-700">{{ $responsibilities }}</p>
            @endif
            @if ($qualifications = ($context['qualifications'][$locale] ?? $context['qualifications']['en'] ?? ''))
                <h2 class="mt-5 font-bold text-neutral-900">{{ __('careers.qualifications') }}</h2>
                <p class="mt-2 whitespace-pre-line text-body leading-7 text-neutral-700">{{ $qualifications }}</p>
            @endif
        </section>
    @endif

    @if ($internal)
        <x-recruitment.internal-applicant-card :internal="$internal" />
    @endif

    @if ($invitation)
        <x-recruitment.invitation-card :invitation="$invitation" :token="request()->route('token')" :locale="$locale" />
    @endif

    @if ($submissionError)
        <div class="mt-4 rounded-control border border-primary bg-white p-4 text-body text-primary" role="alert">
            <p class="font-semibold">{{ $submissionError }}</p>
            @if ($submissionErrors ?? [])
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    @foreach ($submissionErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
    @if (! $accepting_applications)
        <div class="mt-4 rounded-[16px] border border-neutral-200 bg-white p-5 text-body text-neutral-700 shadow-e1" role="status">
            <p class="font-semibold text-neutral-900">{{ __('careers.not_accepting') }}</p>
            <p class="mt-1">{{ __('careers.view_only_notice') }}</p>
        </div>
    @endif
    @if ($showForm && $accepting_applications)
    <form
        method="POST"
        action="{{ route('careers.submit', ['token' => request()->route('token')]) }}"
        enctype="multipart/form-data"
        class="mt-4 space-y-4"
        data-recruitment-wizard
        novalidate
    >
        @csrf
        <input type="hidden" name="locale" value="{{ $locale }}">
        <input type="hidden" name="submission_key" value="{{ $submissionKey }}">
        <div class="absolute -start-[10000px] top-auto size-px overflow-hidden" aria-hidden="true">
            <label for="website">Website</label>
            <input id="website" type="text" name="website" tabindex="-1" autocomplete="off">
        </div>

        @if ($stepCount > 1)
            <div class="rounded-[16px] border border-neutral-200 bg-white px-6 py-5 shadow-e1 sm:px-9">
                <div class="flex items-center justify-between gap-4">
                    <p class="font-semibold text-neutral-900" aria-live="polite">
                        {{ __('careers.step') }} <span data-current-step>1</span> {{ __('careers.of') }} {{ $stepCount }}
                    </p>
                    <span class="text-small text-neutral-500" data-current-section></span>
                </div>
                <div class="mt-4 grid gap-2" style="grid-template-columns: repeat({{ $stepCount }}, minmax(0, 1fr));" aria-hidden="true">
                    @for ($step = 0; $step < $stepCount; $step++)
                        <span class="h-1.5 rounded-full {{ $step === 0 ? 'bg-primary' : 'bg-neutral-200' }}" data-progress-segment></span>
                    @endfor
                </div>
            </div>
        @endif

        @foreach ($fieldsBySection as $sectionKey => $sectionFields)
            @php $section = $sections->get($sectionKey); @endphp
            <section
                class="space-y-3"
                data-wizard-step
                data-section-label="{{ $section['label'][$locale] ?? $section['label']['en'] ?? '' }}"
                @if (! $loop->first) hidden @endif
            >
                <header class="overflow-hidden rounded-[16px] bg-primary px-6 py-4 text-white shadow-e1 sm:px-9">
                    <h2 class="text-subtitle font-bold">{{ $section['label'][$locale] ?? $section['label']['en'] ?? '' }}</h2>
                    @if ($description = ($section['description'][$locale] ?? $section['description']['en'] ?? ''))
                        <p class="mt-1 text-small text-white/80">{{ $description }}</p>
                    @endif
                </header>

                <div class="space-y-3">
                    @foreach ($sectionFields as $field)
                        @php
                            $key = $field['key'];
                            $label = $field['label'][$locale] ?: $field['label']['en'];
                            $help = $field['help'][$locale] ?: $field['help']['en'];
                        @endphp
                        <div class="rounded-[8px] border border-neutral-200 bg-white px-6 py-6 shadow-e1 sm:px-9">
                            <label id="field-{{ $key }}-label" class="block font-medium text-neutral-900" @if (!in_array($field['type'], ['radio', 'checkbox'], true)) for="field-{{ $key }}" @endif>
                                {{ $label }} @if ($field['required']) <span class="text-primary">*</span> @endif
                            </label>
                            @if ($help) <p class="mt-1 text-small leading-5 text-neutral-500">{{ $help }}</p> @endif

                            @switch($field['type'])
                                @case('textarea')
                                    <textarea id="field-{{ $key }}" name="answers[{{ $key }}]" rows="1" placeholder="{{ __('careers.your_answer') }}" class="mt-2 block min-h-9 w-full max-w-xl field-sizing-content resize-none rounded-none border-0 border-b border-neutral-300 bg-transparent px-0 py-1 text-body shadow-none outline-none placeholder:text-neutral-500 focus:border-primary focus:ring-0" @if ($field['required']) required @endif></textarea>
                                    @break
                                @case('select')
                                    <div class="public-career-select-wrap mt-4 w-full max-w-52">
                                        <select id="field-{{ $key }}" name="answers[{{ $key }}]" class="public-career-select block w-full cursor-pointer rounded-control border border-neutral-300 bg-white px-3 py-3 text-body shadow-e1 outline-none focus:border-primary focus:ring-1 focus:ring-primary" @if ($field['required']) required @endif>
                                            <option value="">{{ __('careers.choose') }}</option>
                                            @foreach ($field['options'] as $option)
                                                <option value="{{ $option['value'] }}">{{ $option['label'][$locale] ?: $option['label']['en'] }}</option>
                                            @endforeach
                                        </select>
                                        <p id="field-{{ $key }}-error" data-public-select-error class="mt-2 text-small text-primary" role="alert" hidden>{{ __('careers.choose_an_option') }}</p>
                                    </div>
                                    @break
                                @case('radio')
                                    <div class="mt-3 space-y-2">
                                        @foreach ($field['options'] as $option)
                                            <label class="flex cursor-pointer items-center gap-3 text-body text-neutral-700">
                                                <input type="radio" name="answers[{{ $key }}]" value="{{ $option['value'] }}" class="text-primary focus:ring-primary" @if ($field['required']) required @endif>
                                                <span>{{ $option['label'][$locale] ?: $option['label']['en'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    @break
                                @case('checkbox')
                                    <label class="mt-3 flex cursor-pointer items-start gap-3 text-body text-neutral-700">
                                        <input type="checkbox" name="answers[{{ $key }}]" value="1" class="mt-1 rounded text-primary focus:ring-primary" @if ($field['required']) required @endif>
                                        <span>{{ __('careers.agree') }}</span>
                                    </label>
                                    @break
                                @case('file')
                                    <input
                                        id="field-{{ $key }}"
                                        type="file"
                                        name="answers[{{ $key }}]{{ $field['multiple'] ? '[]' : '' }}"
                                        accept="{{ collect($field['accept'])->map(fn ($extension) => '.'.$extension)->implode(',') }}"
                                        @if ($field['multiple']) multiple @endif
                                        @if ($field['required']) required @endif
                                        class="sr-only"
                                        data-file-input
                                    >
                                    <label for="field-{{ $key }}" class="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-control border border-neutral-300 bg-white px-4 py-2.5 text-body font-semibold text-primary hover:bg-neutral-50 focus-within:ring-2 focus-within:ring-primary">
                                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" />
                                        </svg>
                                        {{ __('careers.add_file') }}
                                    </label>
                                    <p class="mt-2 text-small text-neutral-500" data-file-name>{{ __('careers.no_file_selected') }}</p>
                                    @break
                                @default
                                    @php
                                        $workEmail = $internal && $key === 'email';
                                        $invitedEmailField = $invitedEmail !== null && $field['type'] === 'email';
                                        $isPhone = $key === 'phone';
                                        $isBirthDate = $key === 'date_of_birth';
                                        $hasAgeRange = $age_range['minimum'] !== null && $age_range['maximum'] !== null;
                                    @endphp
                                    <input
                                        id="field-{{ $key }}"
                                        type="{{ in_array($field['type'], ['email', 'tel', 'number', 'date', 'url'], true) ? $field['type'] : 'text' }}"
                                        name="answers[{{ $key }}]"
                                        value="{{ $prefill[$key] ?? '' }}"
                                        placeholder="{{ $field['type'] === 'date' ? '' : __('careers.your_answer') }}"
                                        class="mt-4 block w-full max-w-sm rounded-none border-0 border-b border-neutral-300 bg-transparent px-0 py-2 text-body shadow-none outline-none placeholder:text-neutral-500 focus:border-primary focus:ring-0 {{ in_array($field['type'], ['email', 'tel'], true) ? 'rtl:text-right' : '' }} {{ $workEmail || $invitedEmailField ? 'cursor-not-allowed text-neutral-600' : '' }}"
                                        @if ($field['type'] === 'email' || $field['type'] === 'tel') dir="ltr" @endif
                                        @if ($isPhone)
                                            inputmode="numeric"
                                            pattern="09[0-9]{8}"
                                            minlength="10"
                                            maxlength="10"
                                            autocomplete="tel"
                                            data-phone-input
                                        @endif
                                        @if ($isBirthDate)
                                            max="{{ today()->toDateString() }}"
                                            data-birthdate-input
                                            data-birthdate-error-message="{{ __('careers.errors.invalid_birth_date') }}"
                                            @if ($hasAgeRange)
                                                data-minimum-age="{{ $age_range['minimum'] }}"
                                                data-maximum-age="{{ $age_range['maximum'] }}"
                                                data-age-error-message="{{ __('careers.age_ineligible', ['minimum' => $age_range['minimum'], 'maximum' => $age_range['maximum']]) }}"
                                            @endif
                                        @endif
                                        @if ($workEmail || $invitedEmailField) readonly aria-describedby="field-{{ $key }}-note" @endif
                                        @if ($field['required']) required @endif
                                    >
                                    @if ($isPhone)
                                        <p class="mt-2 text-small text-neutral-500">{{ __('careers.phone_help') }}</p>
                                        <p id="field-{{ $key }}-format-error" data-phone-format-error class="mt-1 text-small text-primary" role="alert" hidden>{{ __('careers.errors.invalid_phone') }}</p>
                                    @elseif ($isBirthDate)
                                        @if ($hasAgeRange)
                                            <p class="mt-2 text-small text-neutral-500">{{ __('careers.age_help', ['minimum' => $age_range['minimum'], 'maximum' => $age_range['maximum']]) }}</p>
                                        @endif
                                        <p id="field-{{ $key }}-birthdate-error" data-birthdate-error class="mt-1 text-small text-primary" role="alert" hidden>{{ __('careers.errors.invalid_birth_date') }}</p>
                                        @if ($hasAgeRange)
                                            <p id="field-{{ $key }}-age-error" data-age-error class="mt-1 text-small text-primary" role="alert" hidden>{{ __('careers.age_ineligible', ['minimum' => $age_range['minimum'], 'maximum' => $age_range['maximum']]) }}</p>
                                        @endif
                                    @elseif ($workEmail)
                                        <p id="field-{{ $key }}-note" class="mt-2 text-small text-neutral-500">{{ __('careers.internal.work_email_note') }}</p>
                                    @elseif ($invitedEmailField)
                                        <p id="field-{{ $key }}-note" class="mt-2 text-small text-neutral-500">{{ __('careers.invitation.email_note') }}</p>
                                    @elseif (isset($prefill[$key]))
                                        <p class="mt-2 text-small text-neutral-500">{{ __('careers.internal.from_record') }}</p>
                                    @endif
                            @endswitch
                        </div>
                    @endforeach
                </div>

                <footer class="flex flex-wrap items-center justify-between gap-3 rounded-[8px] border border-neutral-200 bg-white px-6 py-4 shadow-e1 sm:px-9">
                    <div>
                        @if (! $loop->first)
                            <button type="button" data-wizard-previous class="cursor-pointer rounded-control border border-neutral-300 bg-white px-5 py-2.5 font-semibold text-neutral-700 hover:bg-neutral-100">
                                {{ __('careers.previous') }}
                            </button>
                        @endif
                    </div>

                    @if (! $loop->last)
                        <button type="button" data-wizard-next class="cursor-pointer rounded-control bg-primary px-6 py-2.5 font-semibold text-white hover:bg-primary/90">
                            {{ __('careers.next') }}
                        </button>
                    @else
                        <button type="submit" @disabled(! $accepting_applications) class="cursor-pointer rounded-control bg-primary px-6 py-2.5 font-semibold text-white hover:bg-primary/90 disabled:cursor-not-allowed disabled:bg-neutral-300">
                            {{ $accepting_applications ? __('careers.submit_application') : __('careers.not_accepting') }}
                        </button>
                    @endif
                </footer>
            </section>
        @endforeach
    </form>
    @endif

    <footer class="py-8 text-center text-small text-neutral-500">© {{ now()->year }} Christian Hope Center</footer>
</main>
@endsection

@if ($showForm && $accepting_applications)
@push('scripts')
<script nonce="{{ Vite::cspNonce() }}">
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('[data-recruitment-wizard]');
        if (!form) return;

        const canonicalUrl = @json($canonicalUrl);
        if (window.location.pathname.endsWith('/applications') && window.history?.replaceState) {
            window.history.replaceState({}, document.title, canonicalUrl);
        }

        const steps = Array.from(form.querySelectorAll('[data-wizard-step]'));
        const segments = Array.from(form.querySelectorAll('[data-progress-segment]'));
        const currentStep = form.querySelector('[data-current-step]');
        const currentSection = form.querySelector('[data-current-section]');
        let activeStep = 0;

        if (window.jQuery?.fn?.select2) {
            window.jQuery(form).find('.public-career-select').each((_, element) => {
                const select = window.jQuery(element);
                select.select2({
                    width: '100%',
                    dir: form.closest('[dir]')?.dir || document.documentElement.dir || 'ltr',
                    minimumResultsForSearch: Infinity,
                    dropdownCssClass: 'public-career-dropdown',
                });

                const selection = element.nextElementSibling?.querySelector('.select2-selection');
                selection?.setAttribute('aria-labelledby', `${element.id}-label select2-${element.id}-container`);

                select.on('change', () => {
                    const error = element.parentElement.querySelector('[data-public-select-error]');
                    if (error) error.hidden = true;
                    selection?.removeAttribute('aria-invalid');
                    selection?.removeAttribute('aria-describedby');
                });
            });

            form.noValidate = true;
        }

        const visibleControl = (control) => control?.matches('.public-career-select')
            ? control.nextElementSibling?.querySelector('.select2-selection') ?? control
            : control;

        const validatePhone = (input) => {
            if (!input) return true;

            const valid = input.value === '' || /^09[0-9]{8}$/.test(input.value);
            const error = input.parentElement?.querySelector('[data-phone-format-error]');
            input.setCustomValidity(valid ? '' : @json(__('careers.errors.invalid_phone')));
            if (error) error.hidden = valid;

            return valid;
        };

        const ageOnDate = (dateValue) => {
            const parts = dateValue.split('-').map(Number);
            if (parts.length !== 3 || parts.some(Number.isNaN)) return null;

            const birthDate = new Date(parts[0], parts[1] - 1, parts[2]);
            if (birthDate.getFullYear() !== parts[0] || birthDate.getMonth() !== parts[1] - 1 || birthDate.getDate() !== parts[2]) return null;

            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const birthdayHasPassed = today.getMonth() > birthDate.getMonth()
                || (today.getMonth() === birthDate.getMonth() && today.getDate() >= birthDate.getDate());

            if (!birthdayHasPassed) age -= 1;

            return age;
        };

        const validateBirthDate = (input) => {
            if (!input || input.value === '') return true;

            const ageError = input.parentElement?.querySelector('[data-age-error]');
            const dateError = input.parentElement?.querySelector('[data-birthdate-error]');
            const clear = () => { if (ageError) ageError.hidden = true; if (dateError) dateError.hidden = true; };

            const age = ageOnDate(input.value);
            const parts = input.value.split('-').map(Number);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const picked = parts.length === 3 && !parts.some(Number.isNaN)
                ? new Date(parts[0], parts[1] - 1, parts[2])
                : null;

            // A real calendar date, strictly before today.
            if (age === null || picked === null || picked >= today) {
                clear();
                input.setCustomValidity(input.dataset.birthdateErrorMessage);
                if (dateError) dateError.hidden = false;
                return false;
            }

            // Age range, only when the campaign fixed one.
            if ((input.dataset.minimumAge ?? '') !== '') {
                const minimum = Number(input.dataset.minimumAge);
                const maximum = Number(input.dataset.maximumAge);
                if (age < minimum || age > maximum) {
                    clear();
                    input.setCustomValidity(input.dataset.ageErrorMessage);
                    if (ageError) ageError.hidden = false;
                    return false;
                }
            }

            clear();
            input.setCustomValidity('');
            return true;
        };

        form.querySelectorAll('[data-phone-input]').forEach((input) => {
            input.addEventListener('input', () => {
                input.value = input.value
                    .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
                    .replace(/[^0-9]/g, '')
                    .slice(0, 10);
                validatePhone(input);
            });
            input.addEventListener('blur', () => validatePhone(input));
        });

        form.querySelectorAll('[data-birthdate-input]').forEach((input) => {
            input.addEventListener('change', () => validateBirthDate(input));
            input.addEventListener('blur', () => validateBirthDate(input));
        });

        const showStep = (index, focus = true) => {
            activeStep = Math.max(0, Math.min(index, steps.length - 1));
            steps.forEach((step, stepIndex) => { step.hidden = stepIndex !== activeStep; });
            segments.forEach((segment, segmentIndex) => {
                segment.classList.toggle('bg-primary', segmentIndex <= activeStep);
                segment.classList.toggle('bg-neutral-200', segmentIndex > activeStep);
            });
            if (currentStep) currentStep.textContent = String(activeStep + 1);
            if (currentSection) currentSection.textContent = steps[activeStep]?.dataset.sectionLabel ?? '';

            if (focus) {
                steps[activeStep]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                visibleControl(steps[activeStep]?.querySelector('input:not([disabled]), select:not([disabled]), textarea:not([disabled])'))?.focus({ preventScroll: true });
            }
        };

        const validateStep = (index) => {
            const controls = Array.from(steps[index]?.querySelectorAll('input:not([disabled]), select:not([disabled]), textarea:not([disabled])') ?? []);
            controls.filter((control) => control.matches('[data-phone-input]')).forEach(validatePhone);
            controls.filter((control) => control.matches('[data-birthdate-input]')).forEach(validateBirthDate);
            const invalid = controls.find((control) => !control.checkValidity());
            if (!invalid) return true;

            showStep(index, false);
            if (invalid.matches('.public-career-select') && window.jQuery?.fn?.select2) {
                const selection = visibleControl(invalid);
                const error = invalid.parentElement.querySelector('[data-public-select-error]');
                if (error) error.hidden = false;
                selection?.setAttribute('aria-invalid', 'true');
                selection?.setAttribute('aria-describedby', `${invalid.id}-error`);
                selection?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                selection?.focus({ preventScroll: true });
                window.jQuery(invalid).select2('open');
                return false;
            }

            invalid.reportValidity();
            invalid.focus({ preventScroll: true });
            invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        };

        form.querySelectorAll('[data-wizard-next]').forEach((button) => {
            button.addEventListener('click', () => {
                if (validateStep(activeStep)) showStep(activeStep + 1);
            });
        });

        form.querySelectorAll('[data-wizard-previous]').forEach((button) => {
            button.addEventListener('click', () => showStep(activeStep - 1));
        });

        form.querySelectorAll('[data-file-input]').forEach((input) => {
            input.addEventListener('change', () => {
                const output = input.parentElement?.querySelector('[data-file-name]');
                if (!output) return;
                const names = Array.from(input.files ?? []).map((file) => file.name);
                output.textContent = names.length ? names.join(', ') : @json(__('careers.no_file_selected'));
            });
        });

        form.addEventListener('submit', (event) => {
            for (let index = 0; index < steps.length; index += 1) {
                if (!validateStep(index)) {
                    event.preventDefault();
                    return;
                }
            }
        });

        showStep(0, false);
    });
</script>
@endpush
@endif
