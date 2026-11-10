{{-- The criterion's own truth table: every case it can produce. --}}
<ul class="flex flex-wrap gap-1.5 {{ $class ?? '' }}" aria-label="{{ __('recruitment-indicator-rules.cases_label', ['name' => $name]) }}">
    @if (in_array($key, ['experience', 'qualification'], true))
        <li><a href="#{{ $key }}-title" class="inline-flex items-center gap-1 rounded-control text-small font-semibold text-neutral-800 underline decoration-neutral-300 underline-offset-4 hover:decoration-neutral-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('recruitment-indicator-rules.see_table') }}<flux:icon.arrow-down variant="micro" class="size-3.5" aria-hidden="true" /></a></li>
    @else
        @foreach ($cases as [$outcome, $case])
            <li @class(['inline-flex items-center gap-1 rounded-control px-1.5 py-0.5 text-caption font-semibold', \App\Enums\RecruitmentIndicatorOutcome::classes($outcome)])>
                @include('livewire.recruitment.indicators.partials.outcome-icon', ['outcome' => $outcome])
                <span class="sr-only">{{ __('recruitment-applicant.indicators.outcomes.'.$outcome) }}:</span>
                {{ __('recruitment-indicator-rules.cases.'.$key.'.'.$case) }}
            </li>
        @endforeach
    @endif
</ul>
