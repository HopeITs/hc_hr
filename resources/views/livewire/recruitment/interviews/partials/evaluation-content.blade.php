{{-- One evaluation's content. Only ever included for a viewer the policy allowed to see it. --}}
@php
    use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;
    use App\Models\RecruitmentInterviewEvaluation;

    // A withdrawn evaluation no longer counts, so its recommendation loses its hue.
    // Revisions (history rows) are a record and keep theirs.
    $counts = ! ($evaluation instanceof RecruitmentInterviewEvaluation && $evaluation->isWithdrawn());
@endphp
<dl class="mt-3 space-y-2 text-sm">
    <div>
        <dt class="text-secondary-medium">{{ __('recruitment-applicant.evaluations.recommendation') }}</dt>
        <dd class="mt-0.5">
            <x-recruitment.pill :tone="$counts ? Recommendation::classes($evaluation->recommendation) : 'bg-secondary-soft/60 text-secondary-medium'">
                {{ __('recruitment-applicant.evaluations.recommendations.'.$evaluation->recommendation) }}
            </x-recruitment.pill>
        </dd>
    </div>
    @foreach (['pros', 'cons', 'notes'] as $field)
        @if ($evaluation->{$field})
            <div>
                <dt class="text-secondary-medium">{{ __('recruitment-applicant.evaluations.'.$field) }}</dt>
                <dd class="mt-0.5 whitespace-pre-line">{{ $evaluation->{$field} }}</dd>
            </div>
        @endif
    @endforeach
</dl>
