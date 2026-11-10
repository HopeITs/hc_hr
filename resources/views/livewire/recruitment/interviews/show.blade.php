@php
    use App\Enums\RecruitmentInterviewLocationType;
    use App\Enums\RecruitmentInterviewStatus;
    use App\Services\RecruitmentInterviewSchedulingService;
    use Illuminate\Support\Facades\Lang;

    $timezone = $interview->timezone ?: config('app.timezone');
    $starts = $interview->scheduled_at->copy()->setTimezone($timezone);
    $ends = $interview->ends_at?->copy()->setTimezone($timezone);
    $application = $interview->application;
    $personName = fn ($user) => $user?->profile?->name ?? $user?->email ?? '—';
    // Every time on this page reads in the interview's own time zone, the one shown beside its date.
    $at = fn ($date) => $date?->copy()->setTimezone($timezone)->format('Y-m-d H:i');

    // A bordered neutral button: every action here except the one primary next step.
    $quiet = 'inline-flex min-h-11 cursor-pointer items-center rounded-control border border-secondary-light bg-white px-3 text-sm font-medium text-secondary-dark shadow-e1 transition hover:bg-selection focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:min-h-9';
    $link = 'font-medium text-secondary-dark underline decoration-secondary-light underline-offset-4 transition hover:decoration-secondary-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

<div class="space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.interviews.types.'.$interview->interview_type)" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.interviews.title'), 'route' => 'recruitment.interviews.index'], ['label' => __('recruitment-applicant.interviews.types.'.$interview->interview_type)]]" />

    {{-- With a panel open, its own error shows inside it, next to the action that caused it. --}}
    @if ($panel === null)
        @error('interview')
            <p role="alert" class="rounded-control border border-primary bg-white p-3 text-sm text-primary">{{ $message }}</p>
        @enderror
    @endif

    {{-- Summary --}}
    <section class="rounded-surface border border-secondary-soft bg-white p-6 shadow-e1">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 id="interview-title" tabindex="-1" class="scroll-mt-24 text-h3 font-semibold wrap-break-word">
                    {{ __('recruitment-applicant.interviews.types.'.$interview->interview_type) }}
                    &middot; {{ $application?->applicant?->full_name }}
                </h1>
                <p class="mt-1 text-sm text-secondary-medium">
                    {{ $application?->campaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy') }}
                    @if ($canSeeApplicant)
                        &middot; <a href="{{ route('recruitment.applicants.show', ['applicantId' => $application->applicant_id]) }}" wire:navigate class="{{ $link }}">{{ __('recruitment-applicant.interviews.open_applicant') }}</a>
                    @endif
                </p>
            </div>
            <x-recruitment.pill class="shrink-0" :tone="RecruitmentInterviewStatus::classes($interview->status)">
                {{ __('recruitment-applicant.interviews.statuses.'.$interview->status) }}
            </x-recruitment.pill>
        </div>

        <dl class="mt-6 grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
            <div>
                <dt class="text-secondary-medium">{{ __('recruitment-applicant.interviews.when') }}</dt>
                <dd class="mt-1 font-medium">
                    {{ $starts->translatedFormat('l j F Y') }} &middot; {{ $starts->format('H:i') }}@if ($ends) – {{ $ends->format('H:i') }}@endif
                    <span class="block text-xs font-normal text-secondary-medium">{{ $starts->format('Y-m-d H:i') }} &middot; {{ $timezone }} (UTC{{ $starts->format('P') }})</span>
                </dd>
            </div>
            <div>
                <dt class="text-secondary-medium">{{ __('recruitment-applicant.interviews.location') }}</dt>
                <dd class="mt-1 font-medium">
                    {{ __('recruitment-applicant.interviews.location_types.'.$interview->location_type) }}
                    @if ($interview->location_value)
                        <span class="block font-normal wrap-break-word">{{ $interview->location_value }}</span>
                    @endif
                    @if ($interview->location_type === RecruitmentInterviewLocationType::Online)
                        @if ($meetingUrl && $canJoin)
                            <a href="{{ $meetingUrl }}" target="_blank" rel="noopener noreferrer" class="{{ $quiet }} mt-2 gap-1.5">
                                {{ __('recruitment-applicant.interviews.join_meeting') }}
                                <svg class="size-4 rtl:-scale-x-100" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 4h5v5M16 4l-7 7M14 11.5V15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3.5"/></svg>
                                <span class="sr-only">{{ __('recruitment-applicant.interviews.new_tab') }}</span>
                            </a>
                            <span class="mt-1 block text-xs font-normal break-all text-secondary-medium" dir="ltr">{{ $meetingUrl }}</span>
                        @elseif ($meetingUrl)
                            {{-- Over: the link as plain text for the record, nothing to click. --}}
                            <span class="mt-1 block text-xs font-normal break-all text-secondary-medium" dir="ltr">{{ $meetingUrl }}</span>
                            <span class="block text-xs font-normal text-secondary-medium">{{ __('recruitment-applicant.interviews.meeting_over') }}</span>
                        @else
                            <span class="block text-xs font-normal text-secondary-medium">{{ __('recruitment-applicant.interviews.meeting_url_hidden') }}</span>
                        @endif
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-secondary-medium">{{ __('recruitment-applicant.interviews.scheduled_by') }}</dt>
                <dd class="mt-1">{{ $personName($interview->creator) }} &middot; {{ $at($interview->created_at) }}</dd>
            </div>
            @if ($interview->reschedule_count > 0)
                <div>
                    <dt class="text-secondary-medium">{{ __('recruitment-applicant.interviews.reschedule_count') }}</dt>
                    <dd class="mt-1"><a href="#reschedule-history" class="{{ $link }}">{{ $interview->reschedule_count }}</a></dd>
                </div>
            @endif
        </dl>

        @if ($evaluationDue)
            <p class="mt-6 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-control bg-selection p-3 text-sm">
                <span>{{ __('recruitment-applicant.interviews.evaluation_due') }}</span>
                <a href="#my-evaluation" class="{{ $link }} font-semibold">{{ __('recruitment-applicant.interviews.evaluation_due_link') }}</a>
            </p>
        @endif

        @if ($interview->status === RecruitmentInterviewStatus::Cancelled)
            @php $reasonKey = 'recruitment-applicant.interviews.cancellation_reasons.'.$interview->cancellation_reason; @endphp
            <div class="mt-6 rounded-control border border-secondary-soft bg-secondary-soft/30 p-4 text-sm">
                <p class="font-medium">
                    {{ __('recruitment-applicant.interviews.cancelled_on', ['date' => $at($interview->cancelled_at), 'by' => $interview->cancelledBy ? $personName($interview->cancelledBy) : __('recruitment-applicant.interviews.system')]) }}
                </p>
                <p class="mt-1">{{ __('recruitment-applicant.interviews.cancel_reason') }}: {{ Lang::has($reasonKey) ? __($reasonKey) : $interview->cancellation_reason }}</p>
                @if ($canManage && $interview->cancellation_note)
                    <p class="mt-1 whitespace-pre-line text-secondary-medium">{{ $interview->cancellation_note }}</p>
                @endif
            </div>
        @elseif (in_array($interview->status, [RecruitmentInterviewStatus::Completed, RecruitmentInterviewStatus::NoShow], true))
            <div class="mt-6 rounded-control border border-secondary-soft bg-secondary-soft/30 p-4 text-sm">
                <p class="font-medium">
                    {{ __('recruitment-applicant.interviews.outcome_on', ['date' => $at($interview->outcome_recorded_at), 'by' => $personName($interview->outcomeRecordedBy)]) }}
                </p>
                @if ($canManage && $interview->outcome_note)
                    <p class="mt-1 whitespace-pre-line text-secondary-medium">{{ $interview->outcome_note }}</p>
                @endif
            </div>
        @endif

        {{-- HR actions. Once the interview has started, recording that it was held is the next step. --}}
        @if ($canManage && $interview->isOpen())
            <div class="mt-6 flex flex-wrap items-center gap-2 border-t border-secondary-soft pt-4">
                @if ($hasStarted)
                    <x-primary-button type="button" id="interview-action-{{ RecruitmentInterviewStatus::Completed }}" :show-spinner="false"
                        wire:click="beginOutcome('{{ RecruitmentInterviewStatus::Completed }}')"
                        aria-expanded="{{ $panel === RecruitmentInterviewStatus::Completed ? 'true' : 'false' }}"
                        class="min-h-11 sm:min-h-9">
                        {{ __('recruitment-applicant.interviews.actions.completed') }}
                    </x-primary-button>
                    <button type="button" id="interview-action-{{ RecruitmentInterviewStatus::NoShow }}"
                        wire:click="beginOutcome('{{ RecruitmentInterviewStatus::NoShow }}')"
                        aria-expanded="{{ $panel === RecruitmentInterviewStatus::NoShow ? 'true' : 'false' }}"
                        class="{{ $quiet }}">
                        {{ __('recruitment-applicant.interviews.actions.no_show') }}
                    </button>
                @endif
                <a href="{{ route('recruitment.interviews.edit', ['interviewId' => $interview->id]) }}" wire:navigate class="{{ $quiet }}">
                    {{ __('recruitment-applicant.interviews.actions.edit') }}
                </a>
                <button type="button" id="interview-action-cancel" wire:click="beginCancel"
                    aria-expanded="{{ $panel === 'cancel' ? 'true' : 'false' }}"
                    class="{{ $quiet }} sm:ms-auto">
                    {{ __('recruitment-applicant.interviews.actions.cancel') }}
                </button>
            </div>
            @unless ($hasStarted)
                <p class="mt-2 text-xs text-secondary-medium">{{ __('recruitment-applicant.interviews.outcome_after_start') }}</p>
            @endunless
        @endif

        {{-- The actions open one popup, like the Leaves decision popup (<x-modal.dialog>). Its title keeps the id focus moves to. --}}
        <x-modal.dialog id="interviewActionModal" max-width="xl" wire:model.defer="panelOpen" wire:ignore.self>
            <x-slot:title>
                <span id="interview-panel-title" tabindex="-1" class="focus:outline-none">
                    @if ($panel !== null)
                        {{ $panel === 'cancel' ? __('recruitment-applicant.interviews.cancel_title') : __('recruitment-applicant.interviews.outcome_title.'.$panel) }}
                    @endif
                </span>
            </x-slot:title>
            @if ($panel !== null)
                <div id="interview-panel" class="space-y-3">
                    @error('interview')
                        <div role="alert" class="flex flex-wrap items-center justify-between gap-2 rounded-control border border-primary bg-white p-3 text-sm text-primary">
                            <span>{{ $message }}</span>
                            <a href="{{ route('recruitment.interviews.show', ['interviewId' => $interview->id]) }}" wire:navigate class="font-semibold underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __('recruitment-applicant.interviews.reload') }}</a>
                        </div>
                    @enderror

                    @if ($panel === 'cancel')
                        <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.interviews.cancel_hint') }}</p>
                        <div>
                            <x-forms.input-label for="cancelReason" required :value="__('recruitment-applicant.interviews.cancel_reason')" />
                            <select id="cancelReason" wire:model.live="cancelReason" class="w-full rounded-control border border-secondary-light bg-white px-3 py-2 text-body shadow-e1 transition focus:border-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary">
                                <option value="">{{ __('recruitment.choose') }}</option>
                                @foreach ($cancellationReasons as $code)
                                    <option value="{{ $code }}">{{ __('recruitment-applicant.interviews.cancellation_reasons.'.$code) }}</option>
                                @endforeach
                            </select>
                            <x-forms.input-error field="cancelReason" input-id="cancelReason" />
                        </div>
                        <div>
                            <x-forms.input-label for="cancelNote" :required="$cancelReason === RecruitmentInterviewSchedulingService::REASON_OTHER" :value="__('recruitment-applicant.interviews.internal_note')" />
                            <x-forms.textarea id="cancelNote" wire:model="cancelNote" rows="2" />
                            <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.interviews.internal_note_hint') }}</p>
                            <x-forms.input-error field="cancelNote" input-id="cancelNote" />
                        </div>
                        @unless ($hasStarted)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" wire:model="notifyCandidate" class="mt-0.5 size-4 rounded-inset border-secondary-light text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                                <span>{{ __('recruitment-applicant.interviews.notify_candidate_cancel') }}
                                    <span class="block text-xs text-secondary-medium">{{ __('recruitment-applicant.interviews.notify_candidate_hint') }}</span>
                                </span>
                            </label>
                        @endunless
                        <div class="flex flex-wrap gap-3">
                            <x-primary-button type="button" wire:click="cancelInterview" wire:target="cancelInterview">{{ __('recruitment-applicant.interviews.confirm_cancel') }}</x-primary-button>
                            <x-secondary-button wire:click="dismissPanel">{{ __('recruitment-applicant.interviews.keep') }}</x-secondary-button>
                        </div>
                    @else
                        <div>
                            <x-forms.input-label for="outcomeNote" :value="__('recruitment-applicant.interviews.internal_note')" />
                            <x-forms.textarea id="outcomeNote" wire:model="outcomeNote" rows="2" />
                            <p class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.interviews.outcome_note_hint') }}</p>
                            <x-forms.input-error field="outcome" input-id="outcomeNote" />
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <x-primary-button type="button" wire:click="recordOutcome" wire:target="recordOutcome">{{ __('recruitment-applicant.interviews.confirm_outcome') }}</x-primary-button>
                            <x-secondary-button wire:click="dismissPanel">{{ __('recruitment-applicant.interviews.back') }}</x-secondary-button>
                        </div>
                    @endif
                </div>
            @endif
        </x-modal.dialog>
    </section>

    {{--
        Order follows the moment. Before the interview, who is coming matters
        most. Once it has started, evaluation is the work: a reviewer's own form
        (renders nothing unless they may evaluate), then every reviewer's
        evaluation for HR with view-evaluations in scope.
    --}}
    @unless ($hasStarted)
        @include('livewire.recruitment.interviews.partials.participants')
    @endunless

    <livewire:recruitment.interviews.evaluation-form :interview-id="$interview->id" :key="'evaluation-form-'.$interview->id.'-'.$interview->status" />

    @can('viewEvaluations', $interview)
        <livewire:recruitment.interviews.evaluation-summary :interview-id="$interview->id" :key="'evaluation-summary-'.$interview->id.'-'.$interview->status" />
    @endcan

    @if ($hasStarted)
        @include('livewire.recruitment.interviews.partials.participants')
    @endif

    {{-- Reschedule history --}}
    @if ($interview->reschedules->isNotEmpty())
        <section id="reschedule-history" class="scroll-mt-24 rounded-surface border border-secondary-soft bg-white p-6 shadow-e1">
            <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.interviews.history') }}</h2>
            <ol class="mt-3 space-y-3 text-sm">
                @foreach ($interview->reschedules as $change)
                    <li class="border-s-2 border-secondary-soft ps-3" wire:key="reschedule-{{ $change->id }}">
                        <p class="font-medium">
                            {{ $at($change->from_scheduled_at) }}
                            <svg class="inline size-4 align-[-0.125em] text-secondary-medium rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 10h12m-4-4 4 4-4 4"/></svg>
                            <span class="sr-only">{{ __('recruitment-applicant.interviews.changed_to') }}</span>
                            {{ $at($change->to_scheduled_at) }}
                            @if ($change->location_changed)
                                <x-recruitment.pill class="ms-1 font-normal">{{ __('recruitment-applicant.interviews.location_changed') }}</x-recruitment.pill>
                            @endif
                            @if ($change->reminder_already_sent)
                                <x-recruitment.pill class="ms-1 font-normal">{{ __('recruitment-applicant.interviews.after_reminder') }}</x-recruitment.pill>
                            @endif
                        </p>
                        <p class="text-secondary-medium">{{ $personName($change->rescheduledBy) }} &middot; {{ $at($change->rescheduled_at) }}</p>
                        <p class="mt-1 whitespace-pre-line">{{ $change->reason }}</p>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- Candidate emails: HR only --}}
    @if ($canManage)
        <section class="rounded-surface border border-secondary-soft bg-white p-6 shadow-e1">
            <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.interviews.candidate_emails') }}</h2>
            @if ($notices->isEmpty())
                <p class="mt-2 text-sm text-secondary-medium">{{ __('recruitment-applicant.interviews.no_candidate_emails') }}</p>
            @else
                <ul class="mt-3 divide-y divide-secondary-soft text-sm">
                    @foreach ($notices as $notice)
                        <li class="flex flex-wrap justify-between gap-2 py-2" wire:key="notice-{{ $notice->id }}">
                            <span>
                                {{ __('recruitment-applicant.interviews.notice_kinds.'.$notice->kind) }}
                                <span class="text-secondary-medium">&middot; {{ __('recruitment-applicant.interviews.for_slot', ['slot' => $at($notice->scheduled_for)]) }}</span>
                            </span>
                            <span class="text-secondary-medium">
                                {{ __('recruitment-applicant.interviews.notice_statuses.'.$notice->status) }}
                                &middot; {{ $at($notice->sent_at ?? $notice->failed_at ?? $notice->queued_at) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
