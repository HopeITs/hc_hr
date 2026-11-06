@php
    use App\Enums\RecruitmentIndicatorOutcome as Outcome;
    use Illuminate\Support\Facades\Lang;

    $t = fn (string $key, array $replace = []) => __('recruitment-indicator-rules.'.$key, $replace);

    $rangeLabel = fn (string $option): string => Lang::has('recruitment-indicator-rules.range_options.'.$option)
        ? __('recruitment-indicator-rules.range_options.'.$option)
        : $option;
    $levelLabel = fn (string $level): string => __('recruitment-applicant.indicators.levels.'.$level);
    $criterionName = fn (string $key): string => __('recruitment-applicant.indicators.names.'.$key);

    // Which sources each criterion reads. "indicator" feeds the n-of-m count;
    // "reading" is the separate CV reading shown beside the indicators.
    $sources = [
        'city_match' => ['form' => 'indicator', 'record' => 'indicator'],
        'experience' => ['form' => 'indicator', 'cv' => 'reading'],
        'qualification' => ['form' => 'indicator', 'cv' => 'reading'],
        'industry_experience' => ['form' => 'indicator'],
        'previous_applicant' => ['record' => 'info'],
        'cv_availability' => ['cv' => 'indicator'],
    ];
    // Every case the four criteria without a table of their own can produce
    // (RecruitmentMatchingIndicators::cityMatch(), industryExperience(),
    // cvAvailability(), previousApplicant()).
    $cases = [
        'city_match' => [['met', 'same'], ['not_met', 'different'], ['unknown', 'not_given'], ['not_applicable', 'national']],
        'industry_experience' => [['met', 'declared'], ['partial', 'described'], ['not_met', 'none'], ['unknown', 'not_answered']],
        'previous_applicant' => [['info', 'yes'], ['info', 'no']],
        'cv_availability' => [['met', 'cleared'], ['unknown', 'pending'], ['not_met', 'missing'], ['not_applicable', 'not_asked']],
    ];
    $sourceIcons = ['form' => 'clipboard-document-list', 'cv' => 'document-text', 'record' => 'identification'];

    // Greys only, told apart by fill strength and icon: ink = met, outline = partly, light = not met,
    // faint = does not apply, soft = unknown. Local to this page; other pages keep Outcome::classes().
    $tones = [
        'met' => 'bg-neutral-900 text-white',
        'partial' => 'bg-white text-neutral-900 ring-1 ring-inset ring-neutral-900',
        'not_met' => 'bg-neutral-200 text-neutral-800',
        'not_applicable' => 'bg-neutral-50 text-secondary-medium ring-1 ring-inset ring-neutral-200',
        'unknown' => 'bg-neutral-100 text-secondary-medium',
        'info' => 'bg-neutral-100 text-secondary-dark',
    ];
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $card = 'rounded-surface border border-neutral-200 bg-white shadow-e1';
    $countedNow = count(array_filter($enabled)) - (($enabled['previous_applicant'] ?? false) ? 1 : 0);

    $years = range(0, 10);
    $outcomes = ['met', 'partial', 'not_met', 'not_applicable'];

    $ui = [
        'rangeOptions' => collect(array_keys($ranges))->mapWithKeys(fn ($o) => [$o => $rangeLabel($o)])->all(),
        'levels' => collect($levels)->mapWithKeys(fn ($l) => [$l => ['label' => $levelLabel($l), 'rank' => $ranks[$l]]])->all(),
        'criteria' => collect($indicatorKeys)->mapWithKeys(fn ($k) => [$k => $criterionName($k)])->all(),
        'scored' => array_values(array_diff($indicatorKeys, ['previous_applicant'])),
        'tones' => collect([...$outcomes, 'unknown'])->mapWithKeys(fn ($o) => [$o => $tones[$o]])->all(),
        'outcomes' => collect([...$outcomes, 'unknown'])->mapWithKeys(fn ($o) => [$o => __('recruitment-applicant.indicators.outcomes.'.$o)])->all(),
        'counted' => collect(range(0, count($indicatorKeys)))->map(fn ($n) => trans_choice('recruitment-indicator-rules.counted', $n, ['count' => $n]))->all(),
        'changes' => collect(range(0, 40))->map(fn ($n) => $n === 0 ? $t('no_changes') : trans_choice('recruitment-indicator-rules.changes', $n, ['count' => $n]))->all(),
        'text' => [
            'reading' => trans('recruitment-indicator-rules.reading'),
            'qualReading' => trans('recruitment-indicator-rules.qualification_reading'),
            'change' => trans('recruitment-indicator-rules.change'),
            'on' => $t('on'),
            'off' => $t('off'),
            'noLimit' => $t('no_limit'),
            'noDefault' => $t('no_default'),
            'matchedWord' => $t('matched_word'),
            'noText' => $t('no_text'),
            'noLevel' => $t('no_level'),
            'tester' => trans('recruitment-indicator-rules.tester_reads'),
            'yearsShort' => $t('years_short'),
        ],
    ];
@endphp

<div class="mx-auto max-w-7xl space-y-6 p-4 pb-0 sm:p-6 sm:pb-0"
     x-data="matchingRules(@js($ui))">

    {{-- Heading: what this page controls, which rule set is live, and a jump list for a long page. --}}
    <header class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-x-8 gap-y-3">
            <div class="max-w-3xl">
                <livewire:layout.breadcrumbs :title="$t('title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => $t('title')]]" />
            </div>
            <dl class="flex flex-wrap items-center gap-2 text-small">
                <dt class="sr-only">{{ $t('version_short') }}</dt>
                <dd class="inline-flex items-center gap-1.5 rounded-control px-2.5 py-1 font-semibold {{ $customised ? 'bg-neutral-900 text-white' : 'bg-neutral-100 text-secondary-dark' }}"
                    title="{{ $customised ? $t('customised') : $t('defaults') }}">
                    <span aria-hidden="true" class="size-1.5 rounded-full {{ $customised ? 'bg-white' : 'bg-neutral-400' }}"></span>
                    {{ $customised ? $t('customised_short') : $t('defaults_short') }}
                </dd>
                <dd class="inline-flex items-center gap-2 rounded-control border border-neutral-200 bg-white px-2.5 py-1 text-secondary-dark shadow-e1">
                    <span>{{ $t('version_short') }}</span>
                    <code dir="ltr" class="font-mono text-caption font-semibold text-neutral-900">{{ $version }}</code>
                </dd>
            </dl>
        </div>

        <nav aria-label="{{ $t('title') }}" class="flex flex-wrap gap-1 rounded-control border border-neutral-200 bg-white p-1 shadow-e1 w-fit">
            @foreach (['criteria-title' => $t('criteria_title'), 'experience-title' => $t('experience_title'), 'qualification-title' => $t('qualification_title'), 'tester-title' => $t('tester_title')] as $anchor => $label)
                <a href="#{{ $anchor }}" class="inline-flex min-h-11 items-center rounded-inset px-3 text-small font-medium text-secondary-dark hover:bg-selection hover:text-neutral-900 sm:min-h-8 {{ $focus }}">{{ $label }}</a>
            @endforeach
        </nav>
    </header>

    <form wire:submit="save" class="space-y-6">

        {{-- Criteria: one row each. Switch, what it reads, and every result it can give, in that reading order. --}}
        <section aria-labelledby="criteria-title" class="{{ $card }} scroll-mt-48 sm:scroll-mt-36">
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b border-neutral-100 px-5 py-4 sm:px-6">
                <h2 id="criteria-title" class="text-h4 font-semibold text-neutral-900 scroll-mt-48 sm:scroll-mt-36">{{ $t('criteria_title') }}</h2>
                <p class="inline-flex items-center gap-2 rounded-control bg-selection px-2.5 py-1 text-small font-semibold text-secondary-dark" aria-live="polite">
                    <flux:icon.calculator variant="micro" class="size-4 text-secondary-medium" aria-hidden="true" />
                    <span x-text="ui.counted[countedCount]">{{ trans_choice('recruitment-indicator-rules.counted', $countedNow, ['count' => $countedNow]) }}</span>
                </p>
            </div>

            <ul class="divide-y divide-neutral-100">
                @foreach ($indicatorKeys as $key)
                    <li wire:key="criterion-{{ $key }}"
                        class="grid gap-x-6 gap-y-3 px-5 py-4 transition-colors duration-150 sm:px-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]"
                        :class="$wire.enabled['{{ $key }}'] ? '' : 'bg-neutral-50'">
                        <div class="flex min-w-0 items-start gap-4">
                            <label class="relative inline-flex min-h-11 shrink-0 cursor-pointer items-center">
                                <input type="checkbox" role="switch" id="criterion-{{ $key }}"
                                       wire:model="enabled.{{ $key }}"
                                       aria-describedby="criterion-{{ $key }}-reads"
                                       class="peer sr-only">
                                <span aria-hidden="true"
                                      class="h-6 w-11 rounded-round bg-neutral-300 transition-colors duration-200 ease-[var(--ease-fluid)]
                                             peer-checked:bg-neutral-900
                                             peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-primary"></span>
                                <span aria-hidden="true"
                                      class="absolute start-0.5 top-1/2 -translate-y-1/2 size-5 rounded-round bg-white shadow-e1 transition-transform duration-200 ease-[var(--ease-fluid)]
                                             ltr:peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
                            </label>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <label for="criterion-{{ $key }}" class="cursor-pointer font-semibold text-neutral-900"
                                           :class="$wire.enabled['{{ $key }}'] ? '' : 'text-secondary-medium'">
                                        {{ $criterionName($key) }}
                                    </label>
                                    @if ($key === 'previous_applicant')
                                        <span class="inline-flex items-center gap-1 rounded-control bg-neutral-100 px-1.5 py-0.5 text-caption font-semibold text-secondary-dark">
                                            <flux:icon.information-circle variant="micro" class="size-3.5" aria-hidden="true" />{{ $t('not_scored') }}
                                        </span>
                                    @endif
                                    <span x-cloak x-show="! $wire.enabled['{{ $key }}']" class="rounded-control border border-neutral-300 px-1.5 py-0.5 text-caption font-semibold text-secondary-medium">{{ $t('off') }}</span>
                                </div>
                                <p id="criterion-{{ $key }}-reads" class="mt-0.5 max-w-prose text-small text-secondary-medium">{{ $t('criterion_reads.'.$key) }}</p>
                                {{-- Sources as labelled chips: solid = counts, outline = shown as a separate reading, soft = for information. --}}
                                <ul class="mt-2 flex flex-wrap gap-1.5" :class="$wire.enabled['{{ $key }}'] ? '' : 'opacity-50'">
                                    @foreach ($sources[$key] as $source => $kind)
                                        <li @class([
                                            'inline-flex items-center gap-1 rounded-control px-1.5 py-0.5 text-caption font-semibold',
                                            'bg-neutral-900 text-white' => $kind === 'indicator',
                                            'border border-neutral-900 bg-white text-neutral-900' => $kind === 'reading',
                                            'bg-neutral-100 text-secondary-dark' => $kind === 'info',
                                        ]) title="{{ $t('source_kinds.'.$kind) }}">
                                            <flux:icon :name="$sourceIcons[$source]" variant="micro" class="size-3" aria-hidden="true" />{{ $t('sources.'.$source) }}<span class="sr-only">: {{ $t('source_kinds.'.$kind) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                        <div class="min-w-0 lg:pt-0.5" :class="$wire.enabled['{{ $key }}'] ? '' : 'opacity-50'">
                            <p class="mb-1.5 text-caption font-semibold uppercase tracking-wider rtl:normal-case rtl:tracking-normal text-secondary-medium lg:sr-only">{{ $t('possible_outcomes') }}</p>
                            @include('livewire.recruitment.indicators.partials.criterion-cases', ['key' => $key, 'name' => $criterionName($key), 'cases' => $cases[$key] ?? [], 'class' => '', 'tones' => $tones])
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-neutral-100 bg-neutral-50 px-5 py-3 text-small text-secondary-medium sm:px-6 rounded-b-surface" aria-hidden="true">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-5 rounded-inset bg-neutral-900"></span>{{ $t('source_kinds.indicator') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-5 rounded-inset border border-neutral-900"></span>{{ $t('source_kinds.reading') }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-5 rounded-inset bg-neutral-200"></span>{{ $t('source_kinds.info') }}</span>
            </div>
            @error('disabled') <p class="px-6 pb-4 text-body text-primary" role="alert">{{ $message }}</p> @enderror
        </section>

        <p id="grid-keys-hint" class="sr-only">{{ $t('grid_keys') }}</p>

        {{-- Outcome legend, shared by both matrices --}}
        <div class="sticky top-16 z-[5] flex flex-wrap items-center gap-2 rounded-control border border-neutral-200 bg-white/95 px-3 py-2 text-small shadow-e1 backdrop-blur" aria-hidden="true">
            <span class="me-1 font-semibold text-secondary-dark">{{ $t('legend_title') }}</span>
            @foreach ($outcomes as $outcome)
                <span @class(['inline-flex items-center gap-1.5 rounded-control px-2 py-0.5 font-semibold', $tones[$outcome]])>
                    @include('livewire.recruitment.indicators.partials.outcome-icon', ['outcome' => $outcome])
                    {{ __('recruitment-applicant.indicators.outcomes.'.$outcome) }}
                </span>
            @endforeach
        </div>

        {{-- Experience ---------------------------------------------------------- --}}
        <section aria-labelledby="experience-title"
                 class="{{ $card }} scroll-mt-48 sm:scroll-mt-36 p-5 transition-opacity duration-200 sm:p-6"
                 :class="$wire.enabled.experience ? '' : 'opacity-60'">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <h2 id="experience-title" class="scroll-mt-48 sm:scroll-mt-36 text-h4 font-semibold text-neutral-900">{{ $t('experience_title') }}</h2>
                    <p class="mt-1 text-small text-secondary-medium">{{ $t('experience_hint') }}</p>
                </div>
                <p x-cloak x-show="! $wire.enabled.experience" class="inline-flex items-center gap-1.5 rounded-control border border-neutral-300 bg-white px-2.5 py-1 text-small font-medium text-secondary-dark"><span aria-hidden="true" class="size-1.5 rounded-full bg-neutral-400"></span>{{ $t('switched_off') }}</p>
            </div>

            <div class="mt-5 flex flex-wrap items-start gap-x-4 gap-y-1">
                <div>
                    <x-forms.input-label for="defaultYears">{{ $t('default_experience') }}</x-forms.input-label>
                    <input id="defaultYears" type="number" dir="ltr" inputmode="numeric" min="0" max="50" wire:model="defaultYears"
                           aria-describedby="defaultYears-hint @error('defaultYears') defaultYears-error @enderror"
                           @error('defaultYears') aria-invalid="true" @enderror
                           class="mt-1 w-28 rounded-control border-neutral-300 text-body tabular-nums shadow-e1 focus:border-primary focus:ring-2 focus:ring-neutral-200">
                </div>
                <div class="max-w-xl pt-1 sm:pt-7">
                    <p id="defaultYears-hint" class="text-small text-secondary-medium">{{ $t('default_experience_hint') }}</p>
                    @error('defaultYears') <p id="defaultYears-error" class="mt-1 text-body text-primary">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6">
                {{-- Matrix: each answer's range is edited in its own row header --}}
                <div class="min-w-0">
                    <div class="overflow-x-auto pb-1">
                        <table class="w-full min-w-[26rem] border-separate border-spacing-0.5 text-body sm:min-w-[38rem] sm:border-spacing-1" @mouseleave="hoverCol = null" @keydown="gridKey($event, 'exp')" aria-describedby="grid-keys-hint">
                            <caption class="sr-only">{{ $t('experience_title') }}: {{ $t('years_axis') }}</caption>
                            <thead>
                                <tr>
                                    <td></td>
                                    <th scope="colgroup" colspan="{{ count($years) }}" class="pb-1 text-start text-caption font-semibold uppercase tracking-wide rtl:normal-case rtl:tracking-normal text-secondary-medium">
                                        <span class="sticky start-28 inline-block max-w-48 sm:start-52 sm:max-w-none">{{ $t('years_axis') }}</span>
                                    </th>
                                </tr>
                                <tr>
                                    <td class="sticky start-0 z-[1] w-20 bg-white sm:w-48 align-bottom pb-1 text-small text-secondary-medium">{{ $t('answer') }}</td>
                                    @foreach ($years as $year)
                                        <th scope="col" class="relative h-12 min-w-9 rounded-inset text-center align-top text-body font-semibold tabular-nums text-neutral-800 transition-colors duration-150"
                                            :class="colActive({{ $year }}) ? 'bg-selection' : ''">
                                            <span class="block pt-1">{{ $year }}</span>
                                            <span x-cloak x-show="defaultYearsInt === {{ $year }}"
                                                  class="mx-auto mt-0.5 block w-fit rounded-inset bg-neutral-800 px-1 text-caption font-medium text-white">{{ $t('default_marker') }}</span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <th scope="rowgroup" colspan="{{ count($years) + 1 }}" class="pt-1 text-start text-small font-semibold text-secondary-medium">
                                        <span class="inline-flex items-center gap-1.5"><flux:icon.clipboard-document-list variant="micro" class="size-4" />{{ $t('from_form') }}</span>
                                    </th>
                                </tr>
                                @foreach ($ranges as $option => $range)
                                    <tr wire:key="exp-row-{{ $option }}">
                                        @php $invalid = $errors->has('ranges.'.$option); @endphp
                                        <th scope="row" class="sticky start-0 z-[1] rounded-inset px-2 py-1.5 text-start align-middle font-medium text-neutral-900 transition-colors duration-150"
                                            :class="selected?.kind === 'exp' && selected.option === '{{ $option }}' ? 'bg-selection' : 'bg-white'">
                                            <span class="block leading-tight">{{ $rangeLabel($option) }}</span>
                                            <span class="mt-1 flex items-center gap-1 font-normal sm:gap-1.5">
                                                <label class="sr-only" for="min-{{ $option }}">{{ $rangeLabel($option) }}: {{ $t('min_years') }}</label>
                                                <input id="min-{{ $option }}" type="number" dir="ltr" inputmode="numeric" min="0" max="50"
                                                       wire:model="ranges.{{ $option }}.min"
                                                       @focus="select({ kind: 'exp', option: '{{ $option }}', req: selected?.req ?? 3 })"
                                                       @if ($invalid) aria-invalid="true" aria-describedby="range-{{ $option }}-error" @endif
                                                       class="w-10 rounded-control border-neutral-300 bg-white px-1 py-0.5 text-body tabular-nums sm:w-16 sm:px-2 shadow-e1 focus:border-primary focus:ring-2 focus:ring-neutral-200">
                                                <span aria-hidden="true" class="text-secondary-light">–</span>
                                                <label class="sr-only" for="max-{{ $option }}">{{ $rangeLabel($option) }}: {{ $t('max_years') }} ({{ $t('open_ended') }})</label>
                                                <input id="max-{{ $option }}" type="number" dir="ltr" inputmode="numeric" min="0" max="50"
                                                       wire:model="ranges.{{ $option }}.max" placeholder="{{ $t('no_limit') }}"
                                                       @focus="select({ kind: 'exp', option: '{{ $option }}', req: selected?.req ?? 3 })"
                                                       @if ($invalid) aria-invalid="true" aria-describedby="range-{{ $option }}-error" @endif
                                                       class="w-10 rounded-control border-neutral-300 bg-white px-1 py-0.5 text-body tabular-nums sm:w-20 sm:px-2 shadow-e1 placeholder:text-small placeholder:text-secondary-light focus:border-primary focus:ring-2 focus:ring-neutral-200">
                                            </span>
                                            @error('ranges.'.$option) <span id="range-{{ $option }}-error" class="mt-1 block text-small font-normal text-primary">{{ $message }}</span> @enderror
                                        </th>
                                        @foreach ($years as $year)
                                            <td class="p-0">
                                                <button type="button" data-grid="exp" data-r="{{ $loop->parent->index }}" data-c="{{ $year }}"
                                                        :tabindex="tabStop('exp', {{ $loop->parent->index }}, {{ $year }})"
                                                        class="flex h-10 w-full min-w-8 items-center sm:min-w-9 justify-center rounded-inset transition-[background-color,color,box-shadow] duration-200
                                                               focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                                                        :class="[toneFor(expOutcome('{{ $option }}', {{ $year }})), isSelected('exp', '{{ $option }}', {{ $year }}) ? 'ring-2 ring-neutral-900 ring-offset-1' : '']"
                                                        :aria-label="cellLabel(@js($rangeLabel($option)), {{ $year }}, expOutcome('{{ $option }}', {{ $year }}))"
                                                        :aria-current="isSelected('exp', '{{ $option }}', {{ $year }}) ? 'true' : null"
                                                        @mouseenter="hoverCol = {{ $year }}"
                                                        @focus="hoverCol = {{ $year }}"
                                                        @click="select({ kind: 'exp', option: '{{ $option }}', req: {{ $year }} }, 'exp', {{ $loop->parent->index }}, {{ $year }})">
                                                    @include('livewire.recruitment.indicators.partials.outcome-icon', ['expr' => "expOutcome('{$option}', {$year})"])
                                                </button>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach

                                <tr>
                                    <th scope="rowgroup" colspan="{{ count($years) + 1 }}" class="pt-3 text-start text-small font-semibold text-secondary-medium">
                                        <span class="inline-flex items-center gap-1.5"><flux:icon.document-text variant="micro" class="size-4" />{{ $t('from_cv') }}</span>
                                    </th>
                                </tr>
                                <tr>
                                    <th scope="row" class="sticky start-0 z-[1] rounded-inset px-2 py-1.5 text-start align-middle font-medium text-neutral-900"
                                        :class="selected?.kind === 'cv' ? 'bg-selection' : 'bg-white'">
                                        <label for="cvYears" class="sr-only">{{ $t('cv_years_label') }}</label>
                                        <span class="flex items-center gap-1">
                                            <button type="button" @click="cvYears = Math.max(0, cvYears - 1)"
                                                    class="grid size-6 cursor-pointer place-items-center rounded-control text-secondary-dark sm:size-7 hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-primary"
                                                    aria-label="{{ $t('fewer_years') }}">
                                                <flux:icon.minus variant="micro" class="size-4" />
                                            </button>
                                            <input id="cvYears" type="number" dir="ltr" min="0" max="50" x-model.number="cvYears"
                                                   class="w-10 rounded-control border-neutral-300 px-1 py-1 text-center text-body tabular-nums sm:w-12 focus:border-primary focus:ring-2 focus:ring-neutral-200">
                                            <button type="button" @click="cvYears = Math.min(50, cvYears + 1)"
                                                    class="grid size-6 cursor-pointer place-items-center rounded-control text-secondary-dark sm:size-7 hover:bg-neutral-100 focus-visible:outline-2 focus-visible:outline-primary"
                                                    aria-label="{{ $t('more_years') }}">
                                                <flux:icon.plus variant="micro" class="size-4" />
                                            </button>
                                        </span>
                                        <span class="mt-0.5 block text-small font-normal text-secondary-medium">{{ $t('cv_years_label') }}</span>
                                    </th>
                                    @foreach ($years as $year)
                                        <td class="p-0">
                                            <button type="button" data-grid="exp" data-r="{{ count($ranges) }}" data-c="{{ $year }}"
                                                    :tabindex="tabStop('exp', {{ count($ranges) }}, {{ $year }})"
                                                    class="flex h-10 w-full min-w-8 items-center sm:min-w-9 justify-center rounded-inset transition-[background-color,color,box-shadow] duration-200
                                                           focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                                                    :class="[toneFor(cvOutcome({{ $year }})), isSelected('cv', null, {{ $year }}) ? 'ring-2 ring-neutral-900 ring-offset-1' : '']"
                                                    :aria-label="cellLabel(ui.text.yearsShort.replace(':count', cvYears), {{ $year }}, cvOutcome({{ $year }}))"
                                                    :aria-current="isSelected('cv', null, {{ $year }}) ? 'true' : null"
                                                    @mouseenter="hoverCol = {{ $year }}"
                                                    @focus="hoverCol = {{ $year }}"
                                                    @click="select({ kind: 'cv', option: null, req: {{ $year }} }, 'exp', {{ count($ranges) }}, {{ $year }})">
                                                @include('livewire.recruitment.indicators.partials.outcome-icon', ['expr' => "cvOutcome({$year})"])
                                            </button>
                                        </td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 flex min-h-12 items-start gap-2 rounded-control bg-neutral-50 px-3 py-2.5 text-body text-neutral-800" aria-live="polite">
                        <flux:icon.cursor-arrow-rays variant="micro" class="mt-0.5 size-4 shrink-0 text-secondary-light" />
                        <span x-text="expReading">{{ $t('select_cell') }}</span>
                    </p>
                </div>
            </div>
        </section>

        {{-- Qualification ------------------------------------------------------- --}}
        <section aria-labelledby="qualification-title"
                 class="{{ $card }} scroll-mt-48 sm:scroll-mt-36 p-5 transition-opacity duration-200 sm:p-6"
                 :class="$wire.enabled.qualification ? '' : 'opacity-60'">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-3xl">
                    <h2 id="qualification-title" class="scroll-mt-48 sm:scroll-mt-36 text-h4 font-semibold text-neutral-900">{{ $t('qualification_title') }}</h2>
                    <p class="mt-1 text-small text-secondary-medium">{{ $t('qualification_hint') }}</p>
                </div>
                <p x-cloak x-show="! $wire.enabled.qualification" class="inline-flex items-center gap-1.5 rounded-control border border-neutral-300 bg-white px-2.5 py-1 text-small font-medium text-secondary-dark"><span aria-hidden="true" class="size-1.5 rounded-full bg-neutral-400"></span>{{ $t('switched_off') }}</p>
            </div>

            <div class="mt-6 grid gap-8 xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
                {{-- Level ladder with its words --}}
                <div class="space-y-6">
                    <div>
                        <x-forms.input-label for="defaultLevel">{{ $t('default_qualification') }}</x-forms.input-label>
                        <select id="defaultLevel" wire:model="defaultLevel"
                                aria-describedby="defaultLevel-hint @error('defaultLevel') defaultLevel-error @enderror"
                                @error('defaultLevel') aria-invalid="true" @enderror
                                class="mt-1 w-full max-w-xs rounded-control border-neutral-300 text-body shadow-e1 focus:border-primary focus:ring-2 focus:ring-neutral-200">
                            <option value="">{{ $t('no_default') }}</option>
                            @foreach ($levels as $level)
                                <option value="{{ $level }}">{{ $levelLabel($level) }}</option>
                            @endforeach
                        </select>
                        <p id="defaultLevel-hint" class="mt-1 text-small text-secondary-medium">{{ $t('default_qualification_hint') }}</p>
                        @error('defaultLevel') <p id="defaultLevel-error" class="mt-1 text-body text-primary">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <h3 class="text-body font-medium text-secondary-medium">{{ $t('keywords_title') }}</h3>
                        <ol class="mt-2 space-y-2">
                            @foreach (array_reverse($levels) as $level)
                                @php $invalid = $errors->has('keywords.'.$level); @endphp
                                <li wire:key="keywords-{{ $level }}"
                                    class="rounded-control border px-3 py-2.5 transition-colors duration-150"
                                    :class="levelActive('{{ $level }}') ? 'border-neutral-400 bg-selection' : 'border-neutral-200'">
                                    <div class="flex items-center gap-2">
                                        <span class="grid size-6 place-items-center rounded-inset bg-neutral-800 text-caption font-semibold tabular-nums text-white"
                                              title="{{ $t('level_rank', ['rank' => $ranks[$level]]) }}">{{ $ranks[$level] }}</span>
                                        <span class="font-semibold text-neutral-900">{{ $levelLabel($level) }}</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                        <template x-for="(word, i) in chips('{{ $level }}')" :key="word + i">
                                            <span class="inline-flex items-center gap-1 rounded-control bg-neutral-100 py-0.5 ps-2 pe-1 text-small font-medium text-neutral-800"
                                                  :class="testResult?.keyword && norm(testResult.keyword) === norm(word) && testResult.level === '{{ $level }}' ? 'bg-neutral-800 text-white' : ''">
                                                <span x-text="word" dir="auto"></span>
                                                <button type="button" @click="removeChip('{{ $level }}', i)"
                                                        class="-me-0.5 grid size-6 cursor-pointer place-items-center rounded-inset opacity-60 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-primary"
                                                        :aria-label="@js($t('remove_keyword', ['word' => '__W__'])).replace('__W__', word)">
                                                    <flux:icon.x-mark variant="micro" class="size-3" />
                                                </button>
                                            </span>
                                        </template>
                                        <p x-cloak x-show="chips('{{ $level }}').length === 0" class="text-small text-secondary-medium">{{ $t('no_keywords') }}</p>
                                        <label for="kw-{{ $level }}" class="sr-only">{{ $t('add_keyword', ['level' => $levelLabel($level)]) }}</label>
                                        <input id="kw-{{ $level }}" type="text" x-model="drafts['{{ $level }}']"
                                               @keydown.enter.prevent="addChip('{{ $level }}')"
                                               @keydown.comma.prevent="addChip('{{ $level }}')"
                                               @blur="addChip('{{ $level }}')"
                                               placeholder="{{ $t('add_keyword_placeholder') }}"
                                               @if ($invalid) aria-invalid="true" aria-describedby="keywords-{{ $level }}-error" @endif
                                               class="min-w-32 flex-1 border-0 bg-transparent px-1 py-0.5 text-small placeholder:text-secondary-light focus:ring-0 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary rounded-inset">
                                    </div>
                                    @error('keywords.'.$level) <p id="keywords-{{ $level }}-error" class="mt-1 text-small text-primary">{{ $message }}</p> @enderror
                                </li>
                            @endforeach
                        </ol>
                    </div>
                </div>

                {{-- Level matrix --}}
                <div class="min-w-0">
                    <div class="overflow-x-auto pb-1">
                        <table class="w-full min-w-[32rem] border-separate border-spacing-1 text-body" @keydown="gridKey($event, 'qual')" aria-describedby="grid-keys-hint">
                            <caption class="sr-only">{{ $t('qualification_title') }}</caption>
                            <thead>
                                <tr>
                                    <td></td>
                                    <th scope="colgroup" colspan="{{ count($levels) + 1 }}" class="pb-1 text-center text-caption font-semibold uppercase tracking-wide rtl:normal-case rtl:tracking-normal text-secondary-medium">
                                        {{ $t('required_level') }}
                                    </th>
                                </tr>
                                <tr>
                                    <td class="sticky start-0 z-[1] w-36 bg-white align-bottom text-small text-secondary-medium">{{ $t('applicant_level') }}</td>
                                    @foreach ($levels as $level)
                                        <th scope="col" class="rounded-inset px-1 py-1.5 text-center align-bottom text-small font-semibold text-neutral-800 transition-colors duration-150"
                                            :class="testMode === 'request' && testResult?.level === '{{ $level }}' ? 'bg-selection' : ''">
                                            {{ $levelLabel($level) }}
                                            <span x-cloak x-show="$wire.defaultLevel === '{{ $level }}'"
                                                  class="mx-auto mt-0.5 block w-fit rounded-inset bg-neutral-800 px-1 text-caption font-medium text-white">{{ $t('default_marker') }}</span>
                                        </th>
                                    @endforeach
                                    @foreach (['no_text', 'no_level'] as $special)
                                        <th scope="col" class="rounded-inset border border-dashed border-neutral-300 px-1 py-1.5 text-center align-bottom text-small font-semibold text-secondary-dark">
                                            {{ $t($special) }}
                                            <span class="mt-1 flex flex-col items-center gap-0.5 text-caption font-medium text-secondary-medium">
                                                <span class="inline-flex items-center gap-1"><flux:icon.clipboard-document-list variant="micro" class="size-3" aria-hidden="true" />{{ $t('split_form') }}</span>
                                                <span class="inline-flex items-center gap-1"><flux:icon.document-text variant="micro" class="size-3" aria-hidden="true" />{{ $t('split_cv') }}</span>
                                            </span>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach (array_reverse($levels) as $found)
                                    <tr wire:key="qual-row-{{ $found }}">
                                        <th scope="row" class="sticky start-0 z-[1] rounded-inset px-2 text-start font-medium text-neutral-900 transition-colors duration-150"
                                            :class="testMode === 'cv' && testResult?.level === '{{ $found }}' ? 'bg-selection' : 'bg-white'">
                                            {{ $levelLabel($found) }}
                                        </th>
                                        @foreach ([...$levels, 'no_text', 'no_level'] as $col)
                                            @php
                                                $args = "'{$found}', '{$col}'";
                                                $split = in_array($col, ['no_text', 'no_level'], true);
                                            @endphp
                                            <td class="p-0">
                                                <button type="button" data-grid="qual" data-r="{{ $loop->parent->index }}" data-c="{{ $loop->index }}"
                                                        :tabindex="tabStop('qual', {{ $loop->parent->index }}, {{ $loop->index }})"
                                                        @class([
                                                            'flex w-full overflow-hidden rounded-inset transition-[background-color,color,box-shadow] duration-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary',
                                                            'h-12 flex-col' => $split,
                                                            'h-10 items-center justify-center' => ! $split,
                                                        ])
                                                        :class="[{{ $split ? "''" : "toneFor(qualCell({$args}).form)" }}, isSelected('qual', '{{ $found }}', '{{ $col }}') ? 'ring-2 ring-neutral-900 ring-offset-1' : '']"
                                                        :aria-label="qualLabel({{ $args }})"
                                                        :aria-current="isSelected('qual', '{{ $found }}', '{{ $col }}') ? 'true' : null"
                                                        @click="select({ kind: 'qual', option: '{{ $found }}', req: '{{ $col }}' }, 'qual', {{ $loop->parent->index }}, {{ $loop->index }})">
                                                    @if ($split)
                                                        @foreach (['form' => 'clipboard-document-list', 'cv' => 'document-text'] as $part => $icon)
                                                            <span class="flex flex-1 items-center justify-center" :class="toneFor(qualCell({{ $args }}).{{ $part }})">
                                                                @include('livewire.recruitment.indicators.partials.outcome-icon', ['expr' => "qualCell({$args}).{$part}", 'size' => 'size-3'])
                                                            </span>
                                                        @endforeach
                                                    @else
                                                        @include('livewire.recruitment.indicators.partials.outcome-icon', ['expr' => "qualCell({$args}).form"])
                                                    @endif
                                                </button>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 flex min-h-12 items-start gap-2 rounded-control bg-neutral-50 px-3 py-2.5 text-body text-neutral-800" aria-live="polite">
                        <flux:icon.cursor-arrow-rays variant="micro" class="mt-0.5 size-4 shrink-0 text-secondary-light" />
                        <span x-text="qualReading">{{ $t('select_cell') }}</span>
                    </p>

                    {{-- Word tester --}}
                    <div class="mt-6 border-t border-neutral-100 pt-5">
                        <h3 id="tester-title" class="scroll-mt-48 sm:scroll-mt-36 flex items-center gap-2 text-body font-semibold text-neutral-900"><flux:icon.beaker variant="mini" class="size-4 text-secondary-medium" aria-hidden="true" />{{ $t('tester_title') }}</h3>
                        <p class="mt-0.5 text-small text-secondary-medium">{{ $t('tester_hint') }}</p>

                        <div role="radiogroup" aria-label="{{ $t('tester_title') }}" class="mt-3 inline-flex rounded-control bg-neutral-100 p-0.5">
                            @foreach (['request', 'cv'] as $mode)
                                <label class="cursor-pointer">
                                    <input type="radio" name="testMode" value="{{ $mode }}" x-model="testMode" class="peer sr-only">
                                    <span class="block rounded-[calc(var(--radius-control)-2px)] px-3 py-1 text-small font-semibold text-secondary-dark transition-colors duration-150
                                                 peer-checked:bg-white peer-checked:text-neutral-900 peer-checked:shadow-e1
                                                 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-1 peer-focus-visible:outline-primary">
                                        {{ $t('tester_modes.'.$mode) }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <label for="testText" class="sr-only">{{ $t('tester_title') }}</label>
                        <textarea id="testText" rows="3" dir="auto" x-model="testText"
                                  :placeholder="testMode === 'request' ? @js($t('tester_placeholder.request')) : @js($t('tester_placeholder.cv'))"
                                  class="mt-3 w-full rounded-control border-neutral-300 text-body shadow-e1 placeholder:text-secondary-light focus:border-primary focus:ring-2 focus:ring-neutral-200"></textarea>

                        <div x-cloak x-show="testText.trim() !== ''" class="mt-3 space-y-2">
                            <p dir="auto" class="rounded-control border border-neutral-100 bg-neutral-50 px-3 py-2 text-body leading-relaxed text-neutral-800 whitespace-pre-wrap"><template x-for="(part, i) in testSegments" :key="i"><span :class="part.chosen ? 'rounded-inset bg-neutral-800 px-0.5 text-white' : (part.hit ? 'rounded-inset bg-selection px-0.5 underline decoration-neutral-400 underline-offset-2' : '')" x-text="part.text"></span></template></p>
                            <ul class="space-y-1.5 text-body" aria-live="polite">
                                <template x-for="line in testLines" :key="line.who">
                                    <li class="flex flex-wrap items-center gap-2">
                                        <span class="text-secondary-medium" x-text="line.who"></span>
                                        <template x-if="line.level">
                                            <span class="inline-flex flex-wrap items-center gap-2">
                                                <span class="rounded-control bg-neutral-800 px-2 py-0.5 text-small font-semibold text-white" x-text="ui.levels[line.level].label"></span>
                                                <span class="text-small text-secondary-medium" x-text="ui.text.matchedWord.replace(':word', line.keyword)"></span>
                                            </span>
                                        </template>
                                        <template x-if="! line.level">
                                            <span class="text-secondary-dark" x-text="line.note"></span>
                                        </template>
                                    </li>
                                </template>
                            </ul>
                            <p class="text-small text-secondary-medium" x-text="testMode === 'request' ? @js($t('tester_rule.request')) : @js($t('tester_rule.cv'))"></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Save bar ------------------------------------------------------------ --}}
        <div class="sticky bottom-4 z-10 {{ $card }} px-5 py-4 sm:px-6">
            <div x-cloak x-show="showChanges && changes.length" x-collapse class="mb-3">
                <ul class="max-h-48 space-y-1 overflow-y-auto text-small text-neutral-800">
                    <template x-for="(line, i) in changes" :key="i">
                        <li class="flex items-start gap-2"><span aria-hidden="true" class="mt-1.5 size-1.5 shrink-0 rounded-round bg-neutral-800"></span><span x-text="line" dir="auto"></span></li>
                    </template>
                </ul>
            </div>

            <div class="flex items-center justify-between gap-3">
                <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    <p class="inline-flex items-center gap-2 text-body font-semibold" aria-live="polite"
                       :class="changes.length ? 'text-neutral-900' : 'text-secondary-medium'">
                        <span aria-hidden="true" class="size-2 rounded-full" :class="changes.length ? 'bg-primary' : 'bg-neutral-300'"></span>
                        <span x-text="ui.changes[Math.min(changes.length, 40)]">{{ $t('no_changes') }}</span>
                    </p>
                    <span x-cloak x-show="changes.length" class="flex items-center gap-3 text-small">
                        <button type="button" @click="showChanges = ! showChanges" :aria-expanded="showChanges"
                                class="cursor-pointer rounded-inset font-semibold text-neutral-800 underline decoration-neutral-300 underline-offset-4 hover:decoration-neutral-800 focus-visible:outline-2 focus-visible:outline-primary"
                                x-text="showChanges ? @js($t('hide_changes')) : @js($t('show_changes'))"></button>
                        <button type="button" @click="discard()"
                                class="cursor-pointer rounded-inset text-secondary-medium underline decoration-neutral-300 underline-offset-4 hover:text-neutral-900 focus-visible:outline-2 focus-visible:outline-primary">{{ $t('discard') }}</button>
                    </span>
                    @if ($customised)
                        <span x-show="! changes.length && ! confirmingReset" class="text-small">
                            <button type="button" @click="confirmingReset = true"
                                    class="cursor-pointer rounded-inset text-secondary-medium underline decoration-neutral-300 underline-offset-4 hover:text-neutral-900 focus-visible:outline-2 focus-visible:outline-primary">{{ $t('reset') }}</button>
                        </span>
                        <span x-cloak x-show="confirmingReset" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-small" role="group" aria-label="{{ $t('reset') }}">
                            <span class="text-neutral-800">{{ $t('reset_confirm') }}</span>
                            <button type="button" wire:click="resetToDefaults" wire:loading.attr="disabled" wire:target="resetToDefaults" @click="confirmingReset = false"
                                    class="cursor-pointer rounded-inset font-semibold text-neutral-900 underline decoration-neutral-400 underline-offset-4 focus-visible:outline-2 focus-visible:outline-primary">{{ $t('confirm_reset') }}</button>
                            <button type="button" @click="confirmingReset = false"
                                    class="cursor-pointer rounded-inset text-secondary-medium underline decoration-neutral-300 underline-offset-4 hover:text-neutral-900 focus-visible:outline-2 focus-visible:outline-primary">{{ $t('cancel') }}</button>
                        </span>
                    @endif
                    <p x-cloak x-show="changes.length" class="hidden basis-full text-small text-secondary-medium sm:block">{{ $t('form_hint') }}</p>
                </div>

                <x-primary-button type="submit" wire:target="save" class="shrink-0 disabled:cursor-not-allowed disabled:opacity-50" x-bind:disabled="! changes.length">{{ $t('save') }}</x-primary-button>
            </div>
        </div>
    </form>
</div>

@script
<script>
    const TASHKEEL = /[ً-ٰٟـ]/;
    const FOLD = { 'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ة': 'ه', 'ى': 'ي', 'ؤ': 'و', 'ئ': 'ي', '–': '-', '—': '-' };
    const DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };

    // Mirrors CvAnalyzer::normalize(), keeping where each character came from
    // so a match in the folded text can be highlighted in the original.
    const fold = (text, full) => {
        let out = '';
        const map = [];
        [...text].forEach((ch, index) => {
            if (full && TASHKEEL.test(ch)) return;
            let c = full ? (DIGITS[ch] ?? FOLD[ch] ?? ch) : ch;
            c = c.toLowerCase();
            for (const unit of c) { out += unit; map.push(index); }
        });
        return { out, map, chars: [...text] };
    };

    const format = (template, params) => template.replace(/:(\w+)/g, (all, key) => key in params ? params[key] : all);
    const splitWords = (value) => String(value ?? '').split(/[,،\n]+/u).map((w) => w.trim()).filter(Boolean);

    Alpine.data('matchingRules', (ui) => ({
        ui,
        selected: { kind: 'exp', option: Object.keys(ui.rangeOptions)[1] ?? null, req: 3 },
        hoverCol: null,
        cvYears: 3,
        testMode: 'request',
        testText: '',
        drafts: {},
        showChanges: false,
        confirmingReset: false,

        // ---- numbers ----------------------------------------------------
        int(value) {
            if (value === '' || value === null || value === undefined) return null;
            const n = Number(value);
            return Number.isInteger(n) && n >= 0 && n <= 50 ? n : NaN;
        },
        range(option) {
            const r = this.$wire.ranges?.[option] ?? {};
            const min = this.int(r.min);
            const max = this.int(r.max);
            if (min === null || Number.isNaN(min) || Number.isNaN(max) || (max !== null && max < min)) return null;
            return { min, max };
        },
        rangeText(option) {
            const r = this.range(option);
            if (! r) return '—';
            if (r.max === null) return `${r.min}+`;
            return r.min === r.max ? `${r.min}` : `${r.min}–${r.max}`;
        },
        get defaultYearsInt() {
            const n = this.int(this.$wire.defaultYears);
            return Number.isNaN(n) ? null : n;
        },

        // ---- outcomes (RecruitmentMatchingIndicators / CvAnalyzer) --------
        expOutcome(option, req) {
            if (req <= 0) return 'met';
            const r = this.range(option);
            if (! r) return 'invalid';
            if (r.min >= req) return 'met';
            if (r.max !== null && r.max < req) return 'not_met';
            return 'partial';
        },
        cvOutcome(req) {
            if (req <= 0) return 'met';
            return Number(this.cvYears) >= req ? 'met' : 'not_met';
        },
        // Form: RecruitmentMatchingIndicators::qualification(); CV: CvAnalyzer::compareEducation().
        // The saved minimum only ever reaches the form indicator.
        qualCell(found, col) {
            const rank = (level) => this.ui.levels[level].rank;
            if (this.ui.levels[col]) {
                const outcome = rank(found) >= rank(col) ? 'met' : 'not_met';
                return { form: outcome, cv: outcome };
            }
            const fallback = this.ui.levels[this.$wire.defaultLevel] ? this.$wire.defaultLevel : null;
            const cv = col === 'no_text' ? 'not_applicable' : 'unknown';
            const form = fallback ? (rank(found) >= rank(fallback) ? 'met' : 'not_met') : cv;
            return { form, cv };
        },
        toneFor(outcome) {
            return outcome === 'invalid'
                ? 'bg-white text-secondary-light border border-dashed border-neutral-300'
                : this.ui.tones[outcome];
        },
        outcomeLabel(outcome) {
            return outcome === 'invalid' ? '—' : this.ui.outcomes[outcome];
        },
        cellLabel(row, req, outcome) {
            return `${row}, ${this.ui.text.yearsShort.replace(':count', req)}: ${this.outcomeLabel(outcome)}`;
        },
        qualLabel(found, col) {
            const cell = this.qualCell(found, col);
            const head = `${this.ui.levels[found].label} / ${this.ui.levels[col]?.label ?? (col === 'no_text' ? this.ui.text.noText : this.ui.text.noLevel)}`;
            return this.ui.levels[col]
                ? `${head}: ${this.outcomeLabel(cell.form)}`
                : `${head}: ${this.ui.text.tester.form} ${this.outcomeLabel(cell.form)}, ${this.ui.text.tester.cv} ${this.outcomeLabel(cell.cv)}`;
        },

        // ---- selection & readings ---------------------------------------
        grids: { exp: { r: 1, c: 3 }, qual: { r: 0, c: 0 } },
        select(cell, grid, r, c) {
            this.selected = cell;
            if (grid) this.grids[grid] = { r, c };
        },
        tabStop(grid, r, c) {
            return this.grids[grid].r === r && this.grids[grid].c === c ? 0 : -1;
        },
        // Arrow keys move through a matrix as one widget (one tab stop each).
        gridKey(event, grid) {
            const cell = event.target.closest('[data-grid="' + grid + '"]');
            if (! cell) return;
            const rtl = getComputedStyle(cell).direction === 'rtl';
            const cells = [...this.$root.querySelectorAll('[data-grid="' + grid + '"]')];
            const rows = [...new Set(cells.map((el) => +el.dataset.r))].sort((a, b) => a - b);
            const cols = [...new Set(cells.map((el) => +el.dataset.c))].sort((a, b) => a - b);
            let ri = rows.indexOf(+cell.dataset.r);
            let ci = cols.indexOf(+cell.dataset.c);
            const far = 1000;
            const step = {
                ArrowUp: [-1, 0], ArrowDown: [1, 0],
                ArrowLeft: [0, rtl ? 1 : -1], ArrowRight: [0, rtl ? -1 : 1],
                Home: event.ctrlKey ? [-far, -far] : [0, -far],
                End: event.ctrlKey ? [far, far] : [0, far],
            }[event.key];
            if (! step) return;
            ri = Math.min(rows.length - 1, Math.max(0, ri + step[0]));
            ci = Math.min(cols.length - 1, Math.max(0, ci + step[1]));
            const r = rows[ri];
            const c = cols[ci];
            const next = cells.find((el) => +el.dataset.r === r && +el.dataset.c === c);
            if (! next) return;
            event.preventDefault();
            next.focus();
            next.click();
        },
        isSelected(kind, option, req) {
            return this.selected?.kind === kind && this.selected.option === option && this.selected.req === req;
        },
        colActive(year) {
            return this.hoverCol === year || (['exp', 'cv'].includes(this.selected?.kind) && this.selected.req === year);
        },
        get expReading() {
            const s = this.selected;
            if (! s || ! ['exp', 'cv'].includes(s.kind)) return @js($t('select_cell'));
            if (s.req <= 0) return this.ui.text.reading.none_required;
            if (s.kind === 'cv') {
                const outcome = this.cvOutcome(s.req);
                return format(this.ui.text.reading['cv_' + outcome], { required: s.req, found: this.cvYears });
            }
            const outcome = this.expOutcome(s.option, s.req);
            if (outcome === 'invalid') return @js($t('invalid_range'));
            return format(this.ui.text.reading[outcome], {
                required: s.req,
                answer: this.ui.rangeOptions[s.option],
                range: this.rangeText(s.option),
            });
        },
        get qualReading() {
            const s = this.selected;
            if (s?.kind !== 'qual') return @js($t('select_cell'));
            const found = this.ui.levels[s.option].label;
            const cell = this.qualCell(s.option, s.req);
            if (this.ui.levels[s.req]) {
                return format(this.ui.text.qualReading[cell.form], { found, required: this.ui.levels[s.req].label });
            }
            const fallback = this.ui.levels[this.$wire.defaultLevel];
            return fallback
                ? format(this.ui.text.qualReading[s.req + '_default'], { required: fallback.label, form: this.outcomeLabel(cell.form) })
                : this.ui.text.qualReading[s.req + '_none'];
        },

        // ---- criteria ---------------------------------------------------
        get countedCount() {
            return this.ui.scored.filter((key) => this.$wire.enabled?.[key]).length;
        },

        // ---- keyword chips ----------------------------------------------
        norm(word) { return fold(String(word).trim(), true).out; },
        chips(level) { return splitWords(this.$wire.keywords?.[level]); },
        setChips(level, words) { this.$wire.$set('keywords.' + level, words.join(', '), false); },
        addChip(level) {
            const incoming = splitWords(this.drafts[level]);
            this.drafts[level] = '';
            if (! incoming.length) return;
            const words = this.chips(level);
            incoming.forEach((word) => {
                if (! words.some((w) => w.toLowerCase() === word.toLowerCase())) words.push(word);
            });
            this.setChips(level, words);
        },
        removeChip(level, index) {
            const words = this.chips(level);
            words.splice(index, 1);
            this.setChips(level, words);
        },
        levelActive(level) {
            return this.testResult?.level === level
                || (this.selected?.kind === 'qual' && (this.selected.option === level || this.selected.req === level));
        },

        // ---- word tester ------------------------------------------------
        // full = false: RecruitmentMatchingIndicators::requiredLevel(), plain
        // lower-cased containment. full = true: CvAnalyzer::levels(), folded
        // Arabic and word-bounded Latin.
        matchesFor(text, full) {
            if (! text.trim()) return [];
            const hay = fold(text, full);
            const found = [];
            Object.keys(this.ui.levels).forEach((level) => {
                this.chips(level).forEach((keyword) => {
                    const needle = fold(keyword, full).out.trim();
                    if (! needle) return;
                    const quoted = needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    const pattern = full && ! /\p{Script=Arabic}/u.test(needle)
                        ? new RegExp('(?<![\\p{L}\\p{N}])' + quoted + '(?![\\p{L}\\p{N}])', 'gu')
                        : new RegExp(quoted, 'gu');
                    for (const m of hay.out.matchAll(pattern)) {
                        found.push({ level, keyword, start: hay.map[m.index], end: hay.map[m.index + m[0].length - 1] + 1 });
                    }
                });
            });
            return found;
        },
        pick(matches, highest) {
            if (! matches.length) return null;
            const rank = (m) => this.ui.levels[m.level].rank;
            return matches.reduce((best, m) => (highest ? rank(m) > rank(best) : rank(m) < rank(best)) ? m : best);
        },
        get testMatches() {
            return this.matchesFor(this.testText, this.testMode === 'cv');
        },
        get testResult() {
            return this.pick(this.testMatches, this.testMode === 'cv');
        },
        get testLines() {
            const t = this.ui.text.tester;
            if (this.testMode === 'cv') {
                const r = this.testResult;
                return [{ who: t.cv_reads, level: r?.level, keyword: r?.keyword, note: t.cv_nothing }];
            }
            const form = this.testResult;
            const cv = this.pick(this.matchesFor(this.testText, true), false);
            const fallback = this.ui.levels[this.$wire.defaultLevel];
            const lines = [{
                who: t.form_reads,
                level: form?.level,
                keyword: form?.keyword,
                note: fallback ? format(t.form_default, { level: fallback.label }) : t.form_nothing,
            }];
            if ((form?.level ?? null) !== (cv?.level ?? null)) {
                lines.push({ who: t.cv_reads, level: cv?.level, keyword: cv?.keyword, note: t.cv_nothing_request });
            }
            return lines;
        },
        get testSegments() {
            const chars = [...this.testText];
            const matches = this.testMatches;
            const chosen = this.testResult;
            const marks = new Array(chars.length).fill(0);
            matches.forEach((m) => {
                const isChosen = chosen && m.level === chosen.level && m.keyword === chosen.keyword;
                for (let i = m.start; i < m.end; i++) marks[i] = Math.max(marks[i], isChosen ? 2 : 1);
            });
            const parts = [];
            chars.forEach((ch, i) => {
                const last = parts[parts.length - 1];
                if (last && last.mark === marks[i]) last.text += ch;
                else parts.push({ text: ch, mark: marks[i] });
            });
            return parts.map((p) => ({ text: p.text, hit: p.mark === 1, chosen: p.mark === 2 }));
        },

        // ---- unsaved changes --------------------------------------------
        get changes() {
            const saved = this.$wire.saved ?? {};
            const t = this.ui.text.change;
            const show = (v) => (v === '' || v === null || v === undefined) ? t.empty : String(v);
            const lines = [];

            Object.entries(this.ui.criteria).forEach(([key, name]) => {
                if (!! this.$wire.enabled?.[key] !== !! saved.enabled?.[key]) {
                    lines.push(format(t.enabled, { name, state: this.$wire.enabled[key] ? this.ui.text.on : this.ui.text.off }));
                }
            });
            if (String(this.$wire.defaultYears ?? '') !== String(saved.defaultYears ?? '')) {
                lines.push(format(t.default_years, { before: show(saved.defaultYears), after: show(this.$wire.defaultYears) }));
            }
            if (String(this.$wire.defaultLevel ?? '') !== String(saved.defaultLevel ?? '')) {
                const label = (v) => v ? (this.ui.levels[v]?.label ?? v) : this.ui.text.noDefault;
                lines.push(format(t.default_level, { before: label(saved.defaultLevel), after: label(this.$wire.defaultLevel) }));
            }
            Object.entries(this.ui.rangeOptions).forEach(([option, answer]) => {
                const now = this.$wire.ranges?.[option] ?? {};
                const was = saved.ranges?.[option] ?? {};
                if (String(now.min ?? '') !== String(was.min ?? '') || String(now.max ?? '') !== String(was.max ?? '')) {
                    const text = (r) => `${show(r.min)}–${r.max === '' || r.max === null || r.max === undefined ? this.ui.text.noLimit : r.max}`;
                    lines.push(format(t.range, { answer, before: text(was), after: text(now) }));
                }
            });
            Object.entries(this.ui.levels).forEach(([level, meta]) => {
                const now = splitWords(this.$wire.keywords?.[level]);
                const was = splitWords(saved.keywords?.[level]);
                if (now.join('|') !== was.join('|')) {
                    lines.push(format(t.keywords, {
                        level: meta.label,
                        before: was.length ? was.join(', ') : t.empty,
                        after: now.length ? now.join(', ') : t.empty,
                    }));
                }
            });
            return lines;
        },
        discard() {
            const saved = JSON.parse(JSON.stringify(this.$wire.saved ?? {}));
            ['enabled', 'defaultYears', 'defaultLevel', 'ranges', 'keywords'].forEach((prop) => {
                this.$wire.$set(prop, saved[prop], false);
            });
            this.showChanges = false;
        },
    }));
</script>
@endscript
