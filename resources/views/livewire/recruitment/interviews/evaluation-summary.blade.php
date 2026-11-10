@php
    use App\Enums\RecruitmentEvaluationRecommendation as Recommendation;

    // Times read in the interview's own time zone, as on the rest of its page.
    $at = fn ($date) => $date?->copy()->setTimezone($timezone)->format('Y-m-d H:i');
@endphp
<section class="rounded-surface border border-secondary-soft bg-white p-6 shadow-e1">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 id="evaluations-title" tabindex="-1" class="scroll-mt-24 text-h4 font-semibold">{{ __('recruitment-applicant.evaluations.summary_title') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.evaluations.summary_hint') }}</p>
        </div>
        @if ($canClose)
            <button type="button"
                    x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'interview-evaluations', action: 'closeEvaluations', message: @js(__('recruitment-applicant.evaluations.close_confirm')), confirmLabel: @js(__('recruitment-applicant.evaluations.close')), tone: 'danger' })"
                    class="min-h-11 cursor-pointer rounded-control border border-secondary-light bg-white px-3 text-sm shadow-e1 transition hover:bg-selection sm:min-h-9 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                {{ __('recruitment-applicant.evaluations.close') }}
            </button>
        @elseif ($interview->evaluations_closed_at)
            <span class="text-xs text-secondary-medium">{{ __('recruitment-applicant.evaluations.closed_on', ['date' => $at($interview->evaluations_closed_at)]) }}</span>
        @endif
    </div>

    {{-- With a panel open, its own error shows inside it, next to the action that caused it. --}}
    @if ($panel === null)
        @error('evaluation')
            <p role="alert" class="mt-4 rounded-control border border-primary bg-white p-3 text-sm text-primary">{{ $message }}</p>
        @enderror
    @endif

    {{-- Tally: advisory, never a score or a decision. --}}
    <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
        <span class="font-medium">{{ trans_choice('recruitment-applicant.evaluations.count', $summary['total'], ['count' => $summary['total']]) }}</span>
        @foreach ($summary['counts'] as $value => $count)
            @if ($count > 0)
                <x-recruitment.pill :tone="Recommendation::classes($value)">{{ __('recruitment-applicant.evaluations.recommendations.'.$value) }}: {{ $count }}</x-recruitment.pill>
            @endif
        @endforeach
    </div>
    <p class="mt-2 text-xs text-secondary-medium">{{ __('recruitment-applicant.evaluations.advisory') }}</p>

    @if ($evaluations->isEmpty())
        <p class="mt-4 text-sm text-secondary-medium">
            {{ $closedReason ? __('recruitment-applicant.evaluations.errors.'.$closedReason) : __('recruitment-applicant.evaluations.none_yet') }}
        </p>
    @else
        <ul class="mt-4 space-y-4">
            @foreach ($evaluations as $evaluation)
                <li class="rounded-control border border-secondary-soft p-4 {{ $evaluation->isWithdrawn() ? 'bg-secondary-soft/30 text-secondary-medium' : '' }}" wire:key="evaluation-{{ $evaluation->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="font-semibold">
                            {{ $evaluation->reviewer?->profile?->name ?? $evaluation->reviewer?->email }}
                            @if ($roles->has($evaluation->reviewer_user_id))
                                <span class="font-normal text-secondary-medium">&middot; {{ __('recruitment-applicant.interviews.roles.'.$roles->get($evaluation->reviewer_user_id)) }}</span>
                            @endif
                        </p>
                        <span class="text-xs text-secondary-medium">
                            {{ __('recruitment-applicant.evaluations.submitted_on', ['date' => $at($evaluation->submitted_at)]) }}
                            @if ($evaluation->revisions->contains('action', \App\Models\RecruitmentInterviewEvaluation::ACTION_CORRECTED))
                                &middot; <span class="font-semibold text-secondary-dark">{{ __('recruitment-applicant.evaluations.corrected_badge') }}</span>
                            @endif
                        </span>
                    </div>

                    @if ($evaluation->isWithdrawn())
                        <p class="mt-2 text-sm">
                            {{ __('recruitment-applicant.evaluations.withdrawn_on', ['date' => $at($evaluation->withdrawn_at), 'by' => $evaluation->withdrawnBy?->profile?->name ?? $evaluation->withdrawnBy?->email]) }}
                            — {{ $evaluation->withdrawal_reason }}
                        </p>
                    @endif

                    @include('livewire.recruitment.interviews.partials.evaluation-content', ['evaluation' => $evaluation])

                    @if (! $evaluation->isWithdrawn())
                        @can('correct', $evaluation)
                            <div class="mt-3 flex gap-3 text-sm">
                                @foreach (['correct' => 'beginCorrect', 'withdraw' => 'beginWithdraw'] as $action => $method)
                                    <button type="button" id="evaluation-{{ $evaluation->id }}-{{ $action }}" wire:click="{{ $method }}({{ $evaluation->id }})"
                                        aria-expanded="{{ $panel === $action && $evaluationId === $evaluation->id ? 'true' : 'false' }}"
                                        class="min-h-11 cursor-pointer font-medium text-secondary-dark underline decoration-secondary-light underline-offset-4 transition hover:decoration-secondary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-h-0">{{ __('recruitment-applicant.evaluations.'.$action) }}</button>
                                @endforeach
                            </div>
                        @endcan
                    @endif

                    @if ($evaluation->revisions->count() > 1)
                        <details class="mt-3 text-sm">
                            <summary class="cursor-pointer font-medium">{{ __('recruitment-applicant.evaluations.history') }} ({{ $evaluation->revisions->count() }})</summary>
                            @include('livewire.recruitment.interviews.partials.evaluation-history', ['revisions' => $evaluation->revisions])
                        </details>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Correct and withdraw open one popup, like the Leaves decision popup (<x-modal.dialog>). Its title keeps the id focus moves to. --}}
    @php $panelEvaluation = $evaluationId !== null ? $evaluations->firstWhere('id', $evaluationId) : null; @endphp
    <x-modal.dialog id="evaluationActionModal" max-width="xl" wire:model.defer="panelOpen" wire:ignore.self>
        <x-slot:title>
            <span id="evaluation-panel-title" tabindex="-1" class="focus:outline-none">
                @if ($panel !== null)
                    {{ __('recruitment-applicant.evaluations.'.$panel.'_title') }}
                @endif
            </span>
        </x-slot:title>
        @if ($panel !== null)
            <div class="space-y-3">
                {{-- Whose evaluation: the popup is no longer under it. --}}
                @if ($panelEvaluation)
                    <p class="text-sm font-semibold">{{ $panelEvaluation->reviewer?->profile?->name ?? $panelEvaluation->reviewer?->email }}</p>
                @endif

                @error('evaluation')
                    <p role="alert" class="rounded-control border border-primary bg-white p-3 text-sm text-primary">{{ $message }}</p>
                @enderror

                @if ($panel === 'correct')
                    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('recruitment-applicant.evaluations.recommendation') }}"
                         @if ($errors->has('recommendation')) aria-invalid="true" aria-describedby="correct-recommendation-error" @endif>
                        @foreach (Recommendation::values() as $value)
                            <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-secondary-light bg-white px-3 text-sm sm:min-h-9 has-[:checked]:border-secondary-dark has-[:checked]:bg-selection has-[:checked]:font-semibold has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary">
                                <input type="radio" wire:model="recommendation" value="{{ $value }}" class="text-primary focus:ring-0">
                                {{ __('recruitment-applicant.evaluations.recommendations.'.$value) }}
                            </label>
                        @endforeach
                    </div>
                    <x-forms.input-error field="recommendation" input-id="correct-recommendation" />
                    @foreach (['pros', 'cons', 'notes'] as $field)
                        <div>
                            <x-forms.input-label :for="'correct-'.$field" :value="__('recruitment-applicant.evaluations.'.$field)" />
                            <x-forms.textarea :id="'correct-'.$field" wire:model="{{ $field }}" rows="2" />
                            <x-forms.input-error :field="$field" :input-id="'correct-'.$field" />
                        </div>
                    @endforeach
                @endif

                <div>
                    <x-forms.input-label for="evaluation-reason" required :value="__('recruitment-applicant.evaluations.reason')" />
                    <x-forms.textarea id="evaluation-reason" wire:model="reason" rows="2" />
                    <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.evaluations.reason_hint') }}</p>
                    <x-forms.input-error field="reason" input-id="evaluation-reason" />
                </div>

                <div class="flex flex-wrap gap-3">
                    <x-primary-button type="button" wire:click="{{ $panel === 'correct' ? 'saveCorrection' : 'withdraw' }}">
                        {{ __('recruitment-applicant.evaluations.'.$panel.'_confirm') }}
                    </x-primary-button>
                    <x-secondary-button wire:click="dismissPanel">{{ __('recruitment-applicant.cancel') }}</x-secondary-button>
                </div>
            </div>
        @endif
    </x-modal.dialog>

    <x-recruitment.confirmation-modal scope="interview-evaluations" />
</section>
