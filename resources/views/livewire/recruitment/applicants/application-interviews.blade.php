@php use App\Enums\RecruitmentInterviewStatus; @endphp
<div class="mt-4 rounded-control border border-secondary-soft p-3 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h4 class="font-semibold">{{ __('recruitment-applicant.interviews.title') }} ({{ $interviews->count() }})</h4>

        @if ($canSchedule)
            <a href="{{ route('recruitment.interviews.create', ['applicationId' => $application->id]) }}" wire:navigate
               class="rounded-control border border-primary px-3 py-1 text-sm font-semibold text-primary hover:bg-selection">
                {{ __('recruitment-applicant.interviews.schedule') }}
            </a>
        @endif
    </div>

    @if ($mayScheduleAtAll && ! $canSchedule)
        <p class="mt-2 text-xs text-secondary-medium">
            {{ $isRejected ? __('recruitment-applicant.interviews.errors.application_rejected') : __('recruitment-applicant.interviews.errors.not_shortlisted') }}
        </p>
    @endif

    @if ($interviews->isEmpty())
        <p class="mt-2 text-secondary-medium">{{ __('recruitment-applicant.interviews.none_yet') }}</p>
    @else
        <ul class="mt-2 divide-y divide-secondary-soft">
            @foreach ($interviews as $interview)
                @php $timezone = $interview->timezone ?: config('app.timezone'); @endphp
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="application-interview-{{ $interview->id }}">
                    <a href="{{ route('recruitment.interviews.show', ['interviewId' => $interview->id]) }}" wire:navigate class="hover:underline">
                        <span class="font-medium">{{ __('recruitment-applicant.interviews.types.'.$interview->interview_type) }}</span>
                        <span class="text-secondary-medium">
                            &middot; {{ $interview->scheduled_at->copy()->setTimezone($timezone)->format('Y-m-d H:i') }}
                            &middot; {{ __('recruitment-applicant.interviews.location_types.'.$interview->location_type) }}
                            &middot; {{ trans_choice('recruitment-applicant.interviews.participant_count', $interview->participants_count, ['count' => $interview->participants_count]) }}
                        </span>
                    </a>
                    <x-recruitment.pill :tone="RecruitmentInterviewStatus::classes($interview->status)" class="recruitment-badge">
                        {{ __('recruitment-applicant.interviews.statuses.'.$interview->status) }}
                    </x-recruitment.pill>
                    @if (isset($tallies[$interview->id]) && $tallies[$interview->id]['total'] > 0)
                        <div class="flex w-full flex-wrap items-center gap-1 text-xs">
                            <span class="text-secondary-medium">{{ trans_choice('recruitment-applicant.evaluations.count', $tallies[$interview->id]['total'], ['count' => $tallies[$interview->id]['total']]) }}:</span>
                            @foreach ($tallies[$interview->id]['counts'] as $value => $count)
                                @if ($count > 0)
                                    <span class="recruitment-badge rounded-control px-1.5 py-0.5 font-semibold {{ \App\Enums\RecruitmentEvaluationRecommendation::classes($value) }}">{{ __('recruitment-applicant.evaluations.recommendations.'.$value) }} {{ $count }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
