@props([
    'campaign',
    'fields' => [],
    'sections' => [],
    'context' => [],
    'locale' => 'en',
    'activeSection' => 'vacancy',
])

@php
    $direction = $locale === 'ar' ? 'rtl' : 'ltr';
    $activeFields = collect($fields)->where('section', $activeSection)->values();
    $usedSections = collect($sections)->filter(fn ($section, $key) => collect($fields)->contains('section', $key));
    $sectionKeys = $usedSections->keys()->values();
    $activeIndex = max(0, $sectionKeys->search($activeSection));
    $previousSection = $activeIndex > 0 ? $sectionKeys[$activeIndex - 1] : null;
    $nextSection = $activeIndex < $sectionKeys->count() - 1 ? $sectionKeys[$activeIndex + 1] : null;
    $section = $sections[$activeSection] ?? reset($sections);
    $title = $context['title'][$locale] ?: $context['title']['en'] ?: __('recruitment.application_form.title');
    $responsibilities = is_array($context['responsibilities'] ?? null)
        ? ($context['responsibilities'][$locale] ?: $context['responsibilities']['en'])
        : (string) ($context['responsibilities'] ?? '');
    $qualifications = is_array($context['qualifications'] ?? null)
        ? ($context['qualifications'][$locale] ?: $context['qualifications']['en'])
        : (string) ($context['qualifications'] ?? '');
    $responsibilitiesLabel = __('recruitment.application_form.responsibilities', [], $locale);
    $qualificationsLabel = __('recruitment.application_form.qualifications', [], $locale);
@endphp

<div dir="{{ $direction }}" class="mx-auto w-full max-w-[720px] text-start">
    <div class="mb-3 flex gap-1" aria-label="{{ __('recruitment.application_form.preview_progress') }}">
        @foreach ($usedSections as $key => $definition)
            <button
                type="button"
                wire:click="setPreviewSection('{{ $key }}')"
                class="h-1.5 flex-1 rounded-full transition {{ $key === $activeSection ? 'bg-primary' : 'bg-secondary-light hover:bg-secondary-light' }}"
                title="{{ $definition['label'][$locale] ?? $definition['label']['en'] }}"
            ></button>
        @endforeach
    </div>

    <x-recruitment.public-brand-banner />

    <section class="mt-3 overflow-hidden rounded-lg border border-secondary-soft bg-white shadow-e1">
        <div class="border-t-[7px] border-primary px-6 py-5 sm:px-7">
            <h2 class="text-[1.65rem] font-medium leading-tight text-secondary-dark">{{ $title }} - {{ __('recruitment.application_form.vacancy_announcement') }}</h2>

            <div class="mt-4 space-y-1 text-small leading-6 text-secondary-dark">
                @if ($context['weekly_hours'])
                    <p><span class="font-semibold">{{ __('recruitment.application_form.working_hours') }}:</span> {{ $context['weekly_hours'] }} {{ __('recruitment.application_form.hours_weekly') }}</p>
                @endif
                @if ($context['deadline'])
                    <p><span class="font-semibold">{{ __('recruitment.application_form.application_deadline') }}:</span> {{ $context['deadline'] }}</p>
                @endif
            </div>

            <div class="mt-5 border-t border-secondary-soft pt-4 text-small text-secondary-medium">
                <p>{{ __('recruitment.application_form.required_hint') }}</p>
                <p class="mt-2 text-primary">* {{ __('recruitment.application_form.required_question') }}</p>
            </div>
        </div>
    </section>

    @if ($activeSection === 'vacancy' && ($responsibilities || $qualifications))
        <section class="mt-3 rounded-lg border border-secondary-soft bg-white px-6 py-5 shadow-e1 sm:px-7">
            @if ($responsibilities)
                <h3 class="font-semibold text-secondary-dark">{{ $responsibilitiesLabel }}</h3>
                <p class="mt-2 whitespace-pre-line text-small leading-6 text-secondary-dark">{{ $responsibilities }}</p>
            @endif
            @if ($qualifications)
                <h3 class="mt-5 font-semibold text-secondary-dark">{{ $qualificationsLabel }}</h3>
                <p class="mt-2 whitespace-pre-line text-small leading-6 text-secondary-dark">{{ $qualifications }}</p>
            @endif
        </section>
    @endif

    <section class="mt-3 overflow-hidden rounded-lg border border-secondary-soft bg-white shadow-e1">
        <div class="bg-primary px-6 py-3 text-white sm:px-7">
            <h3 class="text-body font-semibold">{{ $section['label'][$locale] ?? $section['label']['en'] }}</h3>
            <p class="mt-0.5 text-small text-white/80">{{ $section['description'][$locale] ?? $section['description']['en'] }}</p>
        </div>
    </section>

    <div class="mt-3 space-y-3">
        @forelse ($activeFields as $field)
            @php
                $label = $field['label'][$locale] ?: $field['label']['en'];
                $help = $field['help'][$locale] ?: $field['help']['en'];
            @endphp
            <section class="rounded-lg border border-secondary-soft bg-white px-6 py-5 shadow-e1 sm:px-7">
                <label class="block text-body font-medium leading-6 text-secondary-dark">
                    {{ $label }}
                    @if ($field['required']) <span class="text-primary">*</span> @endif
                </label>
                @if ($help)
                    <p class="mt-1 text-small leading-5 text-secondary-medium">{{ $help }}</p>
                @endif

                @switch($field['type'])
                    @case('textarea')
                        <div class="mt-5 h-16 border-b border-secondary-light text-small text-secondary-light">{{ __('recruitment.application_form.your_answer') }}</div>
                        @break
                    @case('select')
                        <div class="mt-4 flex min-h-11 max-w-64 items-center justify-between rounded border border-secondary-light px-3 text-small text-secondary-medium">
                            <span>{{ __('recruitment.application_form.choose') }}</span>
                            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                        </div>
                        @break
                    @case('radio')
                        <div class="mt-4 space-y-3">
                            @foreach ($field['options'] as $option)
                                <div class="flex items-center gap-3 text-body text-secondary-dark">
                                    <span class="size-5 shrink-0 rounded-full border-2 border-secondary-light"></span>
                                    <span>{{ $option['label'][$locale] ?: $option['label']['en'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        @break
                    @case('checkbox')
                        <div class="mt-4 flex items-start gap-3 text-body text-secondary-dark">
                            <span class="mt-0.5 size-5 shrink-0 rounded border-2 border-secondary-light"></span>
                            <span>{{ __('recruitment.application_form.agree') }}</span>
                        </div>
                        @break
                    @case('file')
                        <button type="button" disabled class="mt-4 inline-flex items-center gap-2 rounded border border-secondary-light bg-white px-3 py-2 text-small font-semibold text-primary">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3"/></svg>
                            {{ __('recruitment.application_form.add_file') }}
                        </button>
                        @break
                    @case('date')
                        <div class="mt-5 flex max-w-56 items-center justify-between border-b border-secondary-light pb-2 text-small text-secondary-light">
                            <span>{{ __('recruitment.application_form.date_placeholder') }}</span>
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg>
                        </div>
                        @break
                    @default
                        <div class="mt-5 max-w-80 border-b border-secondary-light pb-2 text-small text-secondary-light">{{ __('recruitment.application_form.your_answer') }}</div>
                @endswitch
            </section>
        @empty
            <section class="rounded-lg border border-dashed border-secondary-light bg-white px-6 py-10 text-center text-body text-secondary-medium">
                {{ __('recruitment.application_form.empty_section') }}
            </section>
        @endforelse
    </div>

    <div class="mt-4 flex items-center justify-between gap-3 pb-4">
        <div class="flex gap-2">
            @if ($previousSection)
                <button type="button" wire:click="setPreviewSection('{{ $previousSection }}')" class="rounded border border-secondary-light bg-white px-4 py-2 text-small font-semibold text-primary shadow-sm hover:bg-secondary-soft/30">
                    {{ __('recruitment.application_form.back') }}
                </button>
            @endif
            @if ($nextSection)
                <button type="button" wire:click="setPreviewSection('{{ $nextSection }}')" class="rounded bg-primary px-4 py-2 text-small font-semibold text-white shadow-sm hover:bg-primary/90">
                    {{ __('recruitment.application_form.next') }}
                </button>
            @else
                <button type="button" disabled class="rounded bg-primary px-4 py-2 text-small font-semibold text-white shadow-sm">{{ __('recruitment.application_form.submit') }}</button>
            @endif
        </div>
        <span class="text-small text-secondary-medium">{{ $activeIndex + 1 }} / {{ $sectionKeys->count() }}</span>
    </div>
</div>
