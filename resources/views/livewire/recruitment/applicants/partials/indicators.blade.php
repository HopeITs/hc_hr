@php
    use App\Enums\RecruitmentIndicatorOutcome;
@endphp

{{-- An aid for the reviewer. Nothing here is a decision, and nothing here changes a status. --}}
<details class="mt-4 rounded-control border border-neutral-200 p-4 text-sm" @if ($open ?? false) open @endif>
    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
        <span class="font-semibold">{{ __('recruitment-applicant.indicators.title') }}</span>
        <span class="recruitment-badge rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark">
            @if ($set->assessedCount() === 0)
                {{ __('recruitment-applicant.indicators.none_assessed') }}
            @else
                {{ __('recruitment-applicant.indicators.summary', ['met' => $set->metCount(), 'assessed' => $set->assessedCount()]) }}
            @endif
            @if ($set->unassessedCount() > 0)
                &middot; {{ trans_choice('recruitment-applicant.indicators.unassessed', $set->unassessedCount(), ['count' => $set->unassessedCount()]) }}
            @endif
        </span>
    </summary>

    <p class="mt-3 text-secondary-medium">{{ __('recruitment-applicant.indicators.disclaimer') }}</p>

    <ul class="mt-3 divide-y divide-neutral-100">
        @foreach ($set->indicators as $indicator)
            <li class="flex flex-wrap items-start justify-between gap-3 py-2">
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ $indicator->label() }}</p>
                    <p class="text-secondary-medium">{{ $indicator->explanation() }}</p>
                </div>
                <x-recruitment.pill :tone="RecruitmentIndicatorOutcome::classes($indicator->outcome)" class="recruitment-badge shrink-0">
                    {{ __('recruitment-applicant.indicators.outcomes.'.$indicator->outcome) }}
                </x-recruitment.pill>
            </li>
        @endforeach
    </ul>

    <p class="mt-3 text-xs text-secondary-medium">
        {{ __('recruitment-applicant.indicators.criteria_version', ['version' => $set->criteriaVersion]) }}
        @if ($snapshot)
            &middot; {{ __('recruitment-applicant.indicators.snapshot_saved', [
                'date' => $snapshot->computed_at?->format('Y-m-d H:i'),
                'met' => $snapshot->met_count,
                'assessed' => $snapshot->assessed_count,
                'version' => $snapshot->criteria_version,
            ]) }}
            @if ($snapshot->criteria_version !== $set->criteriaVersion)
                &middot; {{ __('recruitment-applicant.indicators.snapshot_outdated') }}
            @endif
        @endif
    </p>
</details>
