@php
    use App\Enums\HiringCampaignStatus;
    use App\Enums\RecruitmentApplicationStatus as ApplicationStatus;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Lang;
    use App\Enums\RecruitmentCvAvailability;
    use App\Models\RecruitmentApplicant;

    $states = app(\App\Services\HiringCampaignStateMachine::class);
    $profilePhoto = $applicant->employee?->avatar_url ?: asset('images/default-avatar.png');
    $currentCampaign = $currentApplication?->campaign;
    $profileCity = $applicant->city ?? $currentCampaign?->vacancy?->createdFromRequest?->scopeCity;
    $currentTargets = $currentApplication ? ($statusTargets[$currentApplication->id] ?? []) : [];
    $nextTarget = $currentTargets[0] ?? null;
@endphp

<x-slot:title>{{ $applicant->full_name }}</x-slot:title>

<div class="mx-auto max-w-[1440px] space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.profile.title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.applicants'), 'route' => 'recruitment.applicants.index'], ['label' => __('recruitment-applicant.profile.title')]]" />

    <section id="profile-overview" class="scroll-mt-24 overflow-hidden rounded-surface border border-neutral-200 bg-white shadow-e1">
        <div class="h-1.5 bg-primary" aria-hidden="true"></div>
        <div class="grid gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <img src="{{ $profilePhoto }}" alt="{{ $applicant->full_name }}" class="size-24 shrink-0 rounded-full border-4 border-white object-cover shadow-e1 outline outline-1 outline-neutral-200 sm:size-28">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-primary">{{ __('recruitment-applicant.profile.title') }}</p>
                        <h1 class="mt-1 text-h2 font-semibold wrap-break-word">{{ $applicant->full_name }}</h1>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-control bg-neutral-100 px-3 py-1 text-sm font-semibold text-secondary-dark">{{ $applicant->isInternal() ? __('recruitment-applicant.profile.internal_candidate') : __('recruitment-applicant.profile.external_candidate') }}</span>
                            @if ($isPreviousApplicant)
                                <span class="rounded-control border border-neutral-300 bg-white px-3 py-1 text-sm font-semibold text-secondary-dark">{{ __('recruitment-applicant.previous_applicant_flag') }}</span>
                            @endif
                            @if ($applicant->anonymized_at)
                                <span class="rounded-control bg-neutral-100 px-3 py-1 text-sm font-semibold text-secondary-dark">{{ __('recruitment-applicant.anonymized') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-6 border-t border-neutral-200 pt-5">
                    <h2 class="text-sm font-semibold">{{ __('recruitment-applicant.profile.contact_information') }}</h2>
                    <dl class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-secondary-medium">Email</dt>
                            <dd class="mt-1 wrap-anywhere"><bdi dir="ltr">{{ $applicant->email_normalized }}</bdi></dd>
                        </div>
                        @if ($applicant->phone_normalized)
                            <div class="min-w-0">
                                <dt class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.mobile') }}</dt>
                                <dd class="mt-1 font-medium"><bdi dir="ltr">{{ $applicant->phone_normalized }}</bdi></dd>
                            </div>
                        @endif
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.city') }}</dt>
                            <dd class="mt-1 font-medium">{{ $profileCity?->name ?? '—' }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.source') }}</dt>
                            <dd class="mt-1 font-medium">{{ RecruitmentApplicant::sourceLabel($applicant->source) }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.first_seen') }}</dt>
                            <dd class="mt-1 font-medium"><bdi dir="ltr">{{ $applicant->created_at?->format('Y-m-d') ?? '—' }}</bdi></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <aside class="rounded-surface border border-neutral-200 bg-neutral-50 p-5" aria-labelledby="recruitment-overview-title">
                <h2 id="recruitment-overview-title" class="font-semibold">{{ __('recruitment-applicant.profile.recruitment_overview') }}</h2>
                @if ($currentApplication)
                    <div class="mt-4 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.profile.current_application') }}</p>
                            <p class="mt-1 font-semibold wrap-break-word">{{ $currentCampaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy') }}</p>
                            @if ($currentCampaign)
                                <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.campaign_number', ['number' => $currentCampaign->id]) }}</p>
                            @endif
                        </div>
                        <span class="recruitment-badge shrink-0 rounded-control px-3 py-1 text-sm font-semibold {{ ApplicationStatus::classes($currentApplication->status) }}">{{ __('recruitment-applicant.statuses.'.$currentApplication->status) }}</span>
                    </div>
                    <div class="mt-5 border-t border-neutral-200 pt-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.profile.next_step') }}</p>
                        <div class="mt-2 text-sm leading-6">
                            @if ($currentApplication->status === ApplicationStatus::Shortlisted)
                                <a href="#application-{{ $currentApplication->id }}-interviews" class="font-semibold text-primary hover:underline">{{ __('recruitment-applicant.interviews.schedule') }}</a>
                            @elseif ($currentApplication->status === ApplicationStatus::Selected)
                                <a href="#application-{{ $currentApplication->id }}-interviews" class="font-semibold text-primary hover:underline">{{ __('recruitment-applicant.offers.create') }}</a>
                            @elseif ($currentApplication->status === ApplicationStatus::InterviewScheduled)
                                <span class="text-secondary-medium">{{ __('recruitment-applicant.profile.awaiting_interview') }}</span>
                            @elseif ($nextTarget)
                                <a href="#application-{{ $currentApplication->id }}" class="font-semibold text-primary hover:underline">{{ __('recruitment-applicant.decisions.actions.'.$nextTarget) }}</a>
                            @else
                                <span class="text-secondary-medium">{{ __('recruitment-applicant.profile.no_action') }}</span>
                            @endif
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-sm text-secondary-medium">{{ __('recruitment-applicant.no_applications') }}</p>
                @endif
            </aside>
        </div>
    </section>

    <nav aria-label="{{ __('recruitment-applicant.profile.quick_navigation') }}" class="flex flex-wrap gap-2 rounded-surface border border-neutral-200 bg-white p-3 shadow-e1">
        <a href="#profile-overview" class="rounded-control px-3 py-2 text-sm font-semibold text-secondary-dark hover:bg-neutral-100 hover:text-primary">{{ __('recruitment-applicant.profile.overview') }}</a>
        <a href="#applications" class="rounded-control px-3 py-2 text-sm font-semibold text-secondary-dark hover:bg-neutral-100 hover:text-primary">{{ __('recruitment-applicant.profile.applications') }}</a>
        <a href="#internal-notes" class="rounded-control px-3 py-2 text-sm font-semibold text-secondary-dark hover:bg-neutral-100 hover:text-primary">{{ __('recruitment-applicant.profile.internal_notes') }}</a>
    </nav>

    @if ($applications->count() > 1)
        <nav aria-label="{{ __('recruitment-applicant.profile.applications_navigation') }}" class="flex flex-wrap gap-2">
            @foreach ($applications as $application)
                <a href="#application-{{ $application->id }}" class="rounded-control border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium hover:border-primary hover:text-primary">
                    {{ __('recruitment-applicant.campaign_number', ['number' => $application->campaign_id]) }}
                    <span class="text-secondary-medium">· {{ __('recruitment-applicant.statuses.'.$application->status) }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    {{-- Application history --}}
    <section id="applications" class="scroll-mt-24 rounded-surface border border-neutral-200 bg-white p-4 shadow-e1 sm:p-6">
        <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.history') }}</h2>

        @if ($hiddenApplications > 0)
            <p class="mt-2 text-sm text-secondary-medium">{{ trans_choice('recruitment-applicant.hidden_applications', $hiddenApplications, ['count' => $hiddenApplications]) }}</p>
        @endif

        @forelse ($applications as $application)
            @php
                $campaign = $application->campaign;
                $effective = $campaign ? $states->effective($campaign) : null;
                $campaignOpen = in_array($effective, [HiringCampaignStatus::Published, HiringCampaignStatus::Open], true);
                $cvFiles = $application->files->where('file_type', \App\Models\RecruitmentApplicantFile::TYPE_CV);
                $availability = RecruitmentCvAvailability::of($cvFiles);
            @endphp

            <details id="application-{{ $application->id }}" wire:key="application-{{ $application->id }}" class="group mt-4 scroll-mt-24 rounded-surface border border-neutral-200 bg-neutral-50/40 p-4 sm:p-5" @if ($statusApplicationId === $application->id) open @endif>
                <summary class="flex cursor-pointer list-none flex-wrap items-start justify-between gap-3 rounded-control outline-none focus-visible:ring-2 focus-visible:ring-primary [&::-webkit-details-marker]:hidden">
                    <div class="min-w-0">
                        {{-- Focus lands here after a decision: the buttons that led to it are gone. --}}
                        <h3 id="application-{{ $application->id }}-title" tabindex="-1" class="scroll-mt-24 font-semibold wrap-break-word">
                            {{ $campaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy') }}
                            @if ($effective !== null && ! $campaignOpen)
                                <span class="ms-1 rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-normal text-secondary-dark">{{ HiringCampaignStatus::getLocalizedName($effective)['value'] }}</span>
                            @endif
                        </h3>
                        <p class="mt-1 text-sm text-secondary-medium">
                            {{ __('recruitment-applicant.applied_at') }}: {{ $application->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                            @if ($campaign)
                                @can('view', $campaign)
                                    &middot;
                                    <a href="{{ route('recruitment.campaigns.show', ['campaignId' => $campaign->id]) }}" wire:navigate class="text-primary hover:underline">{{ __('recruitment-applicant.open_campaign') }}</a>
                                @endcan
                            @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-recruitment.pill :tone="RecruitmentCvAvailability::classes($availability)" class="recruitment-badge">{{ __('recruitment-applicant.cv_availability.'.$availability) }}</x-recruitment.pill>
                        <span class="recruitment-badge rounded-control px-3 py-1 text-sm font-semibold {{ ApplicationStatus::classes($application->status) }}">{{ __('recruitment-applicant.statuses.'.$application->status) }}</span>
                        <span class="rounded-control border border-neutral-300 bg-white px-3 py-1 text-sm font-semibold text-secondary-dark group-open:hidden">{{ __('recruitment-applicant.profile.expand_application') }}</span>
                        <span class="hidden rounded-control border border-neutral-300 bg-white px-3 py-1 text-sm font-semibold text-secondary-dark group-open:inline-flex">{{ __('recruitment-applicant.profile.collapse_application') }}</span>
                    </div>
                </summary>

                <div class="mt-5 border-t border-neutral-200 pt-5">
                <div class="grid gap-4 xl:grid-cols-2">
                    <section class="rounded-control border border-neutral-200 bg-white p-4">
                        <h4 class="font-semibold">{{ __('recruitment-applicant.profile.documents') }}</h4>
                        {{-- Files. The link appears only for users who may download,
                             but the download route asks the policy again regardless. --}}
                        @if ($application->files->isNotEmpty())
                            <ul class="mt-3 divide-y divide-neutral-100 text-sm">
                                @foreach ($application->files as $file)
                                    @include('livewire.recruitment.applicants.partials.file-row', ['file' => $file])
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-3 text-sm text-secondary-medium">{{ __('recruitment-applicant.cv_availability.missing') }}</p>
                        @endif
                    </section>

                    <section class="rounded-control border border-neutral-200 bg-white p-4">
                        <h4 class="font-semibold">{{ __('recruitment-applicant.profile.evaluation') }}</h4>
                        @if (isset($indicatorSets[$application->id]))
                            @include('livewire.recruitment.applicants.partials.indicators', [
                                'set' => $indicatorSets[$application->id],
                                'snapshot' => $application->indicatorSnapshot,
                            ])
                        @endif

                        {{-- Automatic CV reading: only for a cleared CV this user may download. --}}
                        @foreach ($cvFiles as $cvFile)
                            @if ($cvFile->cvAnalysis && Gate::allows('download', $cvFile))
                                @include('livewire.recruitment.applicants.partials.cv-analysis', ['analysis' => $cvFile->cvAnalysis])
                            @endif
                        @endforeach
                    </section>
                </div>

                @php $answerSections = $applicationAnswers[$application->id] ?? []; @endphp
                <section class="mt-4 rounded-control border border-neutral-200 bg-white p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="font-semibold">{{ __('recruitment-applicant.application_answers.title') }}</h4>
                            <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.application_answers.hint') }}</p>
                        </div>
                        @if ($application->formVersion)
                            <span class="rounded-control bg-neutral-100 px-3 py-1 text-xs font-semibold text-secondary-dark">
                                {{ __('recruitment-applicant.application_answers.form_version', ['version' => $application->formVersion->version_no]) }}
                            </span>
                        @endif
                    </div>

                    @if ($answerSections !== [])
                        <div class="mt-4 grid items-start gap-3">
                            @foreach ($answerSections as $section)
                                <section class="h-fit self-start rounded-control border border-neutral-200 bg-neutral-50/50 p-3">
                                    <h5 class="font-semibold">{{ $section['label'] }}</h5>
                                    <dl class="mt-2 space-y-2">
                                        @foreach ($section['answers'] as $answer)
                                            <div class="rounded-control bg-white p-3 ring-1 ring-neutral-100">
                                                <dt class="text-xs font-medium uppercase tracking-wide text-secondary-medium">{{ $answer['label'] }}</dt>
                                                <dd class="mt-1 whitespace-pre-line text-sm font-medium text-secondary-dark wrap-break-word">{{ $answer['value'] }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </section>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-4 rounded-control bg-neutral-50 p-3 text-sm text-secondary-medium">{{ __('recruitment-applicant.application_answers.none') }}</p>
                    @endif
                </section>

                <div class="mt-4 rounded-control border border-neutral-200 bg-white p-4">
                    <livewire:recruitment.applicants.application-tags :application-id="$application->id" :key="'tags-'.$application->id" />
                </div>

                {{-- Endorsement: HR and managers each cast one shortlist vote. --}}
                @if ($canEndorse)
                @php
                    $myEndorsement = $endorsementService->hasEndorsed(auth()->user(), $application);
                    $endorsementCount = $application->endorsements_count ?? $application->endorsements()->count();
                    $hrVoted    = $application->endorsements->contains('voter_role', 'hr');
                    $mgVoted    = $application->endorsements->contains('voter_role', 'manager');
                @endphp
                <section class="mt-4 rounded-control border border-neutral-200 bg-white p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h4 class="font-semibold">{{ __('recruitment-applicant.endorsement.title') }}</h4>
                            <div class="mt-1 flex flex-wrap gap-2 text-xs">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 {{ $hrVoted ? 'bg-primary/10 text-primary' : 'bg-neutral-100 text-secondary-medium' }}">
                                    <span>{{ __('recruitment-applicant.endorsement.role_hr') }}</span>
                                    @if ($hrVoted) <svg class="size-3" viewBox="0 0 16 16" fill="currentColor"><path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.75.75 0 0 1 1.06-1.06L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0z"/></svg> @endif
                                </span>
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 {{ $mgVoted ? 'bg-primary/10 text-primary' : 'bg-neutral-100 text-secondary-medium' }}">
                                    <span>{{ __('recruitment-applicant.endorsement.role_manager') }}</span>
                                    @if ($mgVoted) <svg class="size-3" viewBox="0 0 16 16" fill="currentColor"><path d="M13.78 4.22a.75.75 0 0 1 0 1.06l-7.25 7.25a.75.75 0 0 1-1.06 0L2.22 9.28a.75.75 0 0 1 1.06-1.06L6 10.94l6.72-6.72a.75.75 0 0 1 1.06 0z"/></svg> @endif
                                </span>
                            </div>
                        </div>
                        @if ($myEndorsement)
                            <button type="button" wire:click="retractEndorsement({{ $application->id }})"
                                class="cursor-pointer rounded-control border border-neutral-300 px-4 py-2 text-sm font-semibold hover:bg-neutral-50">
                                {{ __('recruitment-applicant.endorsement.retract') }}
                            </button>
                        @else
                            <button type="button" wire:click="endorse({{ $application->id }})"
                                class="cursor-pointer rounded-control border border-primary bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                                {{ __('recruitment-applicant.endorsement.endorse') }}
                            </button>
                        @endif
                    </div>
                </section>
                @endif

                {{-- Decisions: one button per move this user may make. The button
                     only picks the target; the service checks it all again. --}}
                @php $targets = $statusTargets[$application->id] ?? []; @endphp
                <section class="mt-4 rounded-control border border-neutral-200 bg-white p-4">
                    <h4 class="font-semibold">{{ __('recruitment-applicant.profile.workflow') }}</h4>
                @if (! empty($targets))
                    @if ($statusApplicationId === $application->id)
                        {{-- Keyed apart from the buttons it replaces, so each is swapped in whole. --}}
                        <div wire:key="decision-panel-{{ $application->id }}" class="mt-4 rounded-control border p-4 {{ $statusTarget === ApplicationStatus::Rejected ? 'border-neutral-200 bg-neutral-50/50' : 'border-neutral-200 bg-neutral-50' }}">
                            <h4 id="decision-panel-{{ $application->id }}" tabindex="-1" class="scroll-mt-24 font-semibold">
                                {{ __('recruitment-applicant.decisions.titles.'.$statusTarget) }}
                                <span class="font-normal text-secondary-medium">
                                    &middot; {{ __('recruitment-applicant.statuses.'.$statusFrom) }} <x-recruitment.transition-arrow /> {{ __('recruitment-applicant.statuses.'.$statusTarget) }}
                                </span>
                            </h4>
                            <x-forms.input-error field="statusTarget" :input-id="'decision-'.$application->id" />

                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                @if ($statusTarget === ApplicationStatus::Rejected)
                                    <div>
                                        <x-forms.input-label for="statusReasonCode" required>{{ __('recruitment-applicant.decisions.reason_code') }}</x-forms.input-label>
                                        <select id="statusReasonCode" wire:model.live="statusReasonCode" aria-required="true"
                                            @error('statusReasonCode') aria-invalid="true" aria-describedby="statusReasonCode-error" @enderror
                                            class="mt-1 w-full rounded-control border-neutral-300">
                                            <option value="">—</option>
                                            @foreach ($rejectionReasons as $code)
                                                <option value="{{ $code }}">{{ __('recruitment-applicant.decisions.rejection_reasons.'.$code) }}</option>
                                            @endforeach
                                        </select>
                                        <x-forms.input-error field="statusReasonCode" input-id="statusReasonCode" />
                                    </div>
                                @endif
                                <div class="sm:col-span-2">
                                    <x-forms.input-label for="statusReason" required>{{ __('recruitment-applicant.decisions.internal_note') }}</x-forms.input-label>
                                    <textarea id="statusReason" wire:model="statusReason" rows="3" aria-required="true"
                                        aria-describedby="statusReason-hint @error('statusReason') statusReason-error @enderror" @error('statusReason') aria-invalid="true" @enderror
                                        class="mt-1 w-full rounded-control border-neutral-300"></textarea>
                                    <p id="statusReason-hint" class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.decisions.internal_note_hint') }}</p>
                                    <x-forms.input-error field="statusReason" input-id="statusReason" />
                                </div>
                                @if ($statusTarget === ApplicationStatus::Rejected)
                                    <div class="sm:col-span-2">
                                        @if ($application->rejectionNotice?->isSettled())
                                            <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.decisions.already_notified') }}</p>
                                        @else
                                            <label class="flex items-start gap-2 text-sm">
                                                <input type="checkbox" wire:model="statusNotify" class="mt-0.5 rounded border-neutral-300">
                                                <span>
                                                    {{ __('recruitment-applicant.decisions.notify') }}
                                                    <span class="block text-xs text-secondary-medium">{{ __('recruitment-applicant.decisions.notify_hint') }}</span>
                                                </span>
                                            </label>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <x-primary-button type="button" wire:click="openStatusConfirmation" wire:target="openStatusConfirmation">
                                    {{ __('recruitment-applicant.decisions.confirm.'.$statusTarget) }}
                                </x-primary-button>
                                <x-secondary-button wire:click="cancelStatusChange">
                                    {{ __('recruitment-applicant.cancel') }}
                                </x-secondary-button>
                            </div>
                        </div>
                    @else
                        <div wire:key="decisions-{{ $application->id }}" class="mt-4 flex flex-wrap gap-2">
                            @foreach ($targets as $target)
                                @php
                                    $style = match ($target) {
                                        ApplicationStatus::Rejected => 'border-primary text-primary-hover hover:bg-neutral-50',
                                        ApplicationStatus::Selected => 'border-neutral-300 text-neutral-800 hover:bg-neutral-50',
                                        ApplicationStatus::Shortlisted => 'border-neutral-300 text-primary hover:bg-selection',
                                        default => 'border-neutral-300 hover:bg-neutral-50',
                                    };
                                    $label = $application->status === ApplicationStatus::Rejected
                                        ? __('recruitment-applicant.decisions.actions.reopen')
                                        : __('recruitment-applicant.decisions.actions.'.$target);
                                @endphp
                                <button type="button" id="decide-{{ $application->id }}-{{ $target }}" wire:key="decide-{{ $application->id }}-{{ $target }}" wire:click="beginStatusChange({{ $application->id }}, '{{ $target }}')"
                                    class="cursor-pointer rounded-control border px-4 py-2 text-sm font-semibold {{ $style }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if ($application->rejectionNotice)
                    @php $notice = $application->rejectionNotice; @endphp
                    <p class="mt-3 text-sm text-secondary-medium">
                        {{ __('recruitment-applicant.decisions.notice_status.'.$notice->status, [
                            'date' => ($notice->sent_at ?? $notice->failed_at ?? $notice->queued_at)?->format('Y-m-d H:i'),
                        ]) }}
                    </p>
                @endif

                {{-- Decision history: who, when, from/to and why. Staff only. --}}
                @if ($application->decisions->isNotEmpty())
                    <details class="mt-4 text-sm">
                        <summary class="cursor-pointer font-medium">{{ __('recruitment-applicant.status_change.history') }} ({{ $application->decisions->count() }})</summary>
                        <ol class="mt-2 space-y-2">
                            @foreach ($application->decisions as $decision)
                                <li class="border-s-2 border-neutral-200 ps-3">
                                    <span class="font-medium">{{ __('recruitment-applicant.statuses.'.$decision->from_status) }} <x-recruitment.transition-arrow /> {{ __('recruitment-applicant.statuses.'.$decision->to_status) }}</span>
                                    <span class="text-secondary-medium">
                                        &middot; {{ $decision->decidedBy?->profile?->name ?? $decision->decidedBy?->name ?? '—' }}
                                        &middot; {{ $decision->decided_at?->format('Y-m-d H:i') }}
                                    </span>
                                    @if ($decision->to_status === ApplicationStatus::Rejected && $decision->reason_code)
                                        @php $reasonKey = 'recruitment-applicant.decisions.rejection_reasons.'.$decision->reason_code; @endphp
                                        <p class="mt-1">{{ __('recruitment-applicant.decisions.reason_code') }}: {{ Lang::has($reasonKey) ? __($reasonKey) : $decision->reason_code }}</p>
                                    @endif
                                    @if ($decision->reason_text)
                                        <p class="mt-1 whitespace-pre-line text-secondary-medium wrap-break-word">{{ $decision->reason_text }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </details>
                @endif
                </section>

                {{-- Task 7 (Team 2): this application's interviews. Keyed on the status so a decision re-renders it. --}}
                <section id="application-{{ $application->id }}-interviews" class="mt-4 scroll-mt-24 rounded-control border border-neutral-200 bg-white p-4">
                    <h4 class="mb-4 font-semibold">{{ __('recruitment-applicant.profile.interviews_and_offer') }}</h4>
                    <livewire:recruitment.applicants.application-interviews :application-id="$application->id" :key="'interviews-'.$application->id.'-'.$application->status" />

                    {{-- Task 9 (Team 2): this application's job offer (view-offers only). --}}
                    <livewire:recruitment.applicants.application-offer :application-id="$application->id" :key="'offer-'.$application->id.'-'.$application->status" />
                </section>
                </div>
            </details>
        @empty
            <p class="mt-4 text-sm text-secondary-medium">{{ __('recruitment-applicant.no_applications') }}</p>
        @endforelse
    </section>

    {{-- Files with no application behind them: talent pool, or an application since removed. --}}
    @if ($orphanFiles->isNotEmpty())
        <section class="rounded-surface border border-neutral-200 bg-white p-4 shadow-e1 sm:p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.unattached_files') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment-applicant.unattached_files_hint') }}</p>
            <ul class="mt-4 divide-y divide-neutral-100 text-sm">
                @foreach ($orphanFiles as $file)
                    @include('livewire.recruitment.applicants.partials.file-row', ['file' => $file, 'panelHeading' => 'h3'])
                @endforeach
            </ul>
        </section>
    @endif

    <div id="internal-notes" class="scroll-mt-24">
        <livewire:recruitment.applicants.notes :applicant-id="$applicant->id" />
    </div>

    <x-recruitment.confirmation-modal scope="applicant-profile" />
</div>
