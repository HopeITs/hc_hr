@php
    use App\Enums\HiringCampaignStatus;
    use App\Enums\RecruitmentApplicationStatus as ApplicationStatus;
    use Illuminate\Support\Facades\Gate;
    use Illuminate\Support\Facades\Lang;
    use App\Enums\RecruitmentCvAvailability;
    use App\Models\RecruitmentApplicant;

    $states = app(\App\Services\HiringCampaignStateMachine::class);
    $avatarUrl = $applicant->employee?->avatar_url;
    $initials = collect(preg_split('/\s+/u', trim((string) $applicant->full_name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
        ->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: '?';
    $currentCampaign = $currentApplication?->campaign;
    $profileCity = $applicant->city ?? $currentCampaign?->vacancy?->createdFromRequest?->scopeCity;
    $currentTargets = $currentApplication ? ($statusTargets[$currentApplication->id] ?? []) : [];
    $nextTarget = collect($currentTargets)->first(fn ($target) => $target !== ApplicationStatus::Rejected);

    // Shared recipes. Greys carry structure; red is kept for the one next step and for destructive moves.
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $card = 'rounded-surface border border-neutral-200 bg-white shadow-e1';
    $eyebrow = 'text-caption font-semibold uppercase tracking-wider text-secondary-medium rtl:normal-case rtl:tracking-normal';
    $sectionTitle = 'text-h4 font-semibold text-neutral-900';
    $pill = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-control bg-neutral-100 px-2 py-0.5 text-xs font-semibold text-secondary-dark';
    $quietBtn = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control border border-neutral-200 bg-white px-4 text-small font-semibold text-secondary-dark shadow-e1 transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-10 '.$focus;
    $inkBtn = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control border border-neutral-900 bg-neutral-900 px-4 text-small font-semibold text-white shadow-e1 transition duration-150 hover:bg-neutral-800 motion-reduce:transition-none sm:min-h-10 '.$focus;
    $dangerBtn = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control border border-primary bg-white px-4 text-small font-semibold text-primary shadow-e1 transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-10 '.$focus;

    // Status as a dot: hollow while waiting, mid-grey in review, ink once chosen, faded once out.
    $statusDot = fn (?string $status) => match ($status) {
        ApplicationStatus::Submitted, ApplicationStatus::UnderReview => 'border border-neutral-500 bg-white',
        ApplicationStatus::Shortlisted, ApplicationStatus::InterviewScheduled,
        ApplicationStatus::Interviewed, ApplicationStatus::Finalist => 'bg-neutral-500',
        ApplicationStatus::Selected, ApplicationStatus::OfferSent,
        ApplicationStatus::OfferAccepted, ApplicationStatus::Hired => 'bg-neutral-900',
        default => 'bg-neutral-300',
    };

    // The road an application travels. Anything past "Selected" counts as the whole road walked.
    $pipeline = [
        ApplicationStatus::Submitted, ApplicationStatus::Shortlisted, ApplicationStatus::InterviewScheduled,
        ApplicationStatus::Interviewed, ApplicationStatus::Finalist, ApplicationStatus::Selected,
    ];
    $stepIndex = fn (string $status) => match ($status) {
        ApplicationStatus::UnderReview => 0,
        ApplicationStatus::OfferSent, ApplicationStatus::OfferAccepted, ApplicationStatus::Hired => count($pipeline),
        default => array_search($status, $pipeline, true),
    };
    $endedStatuses = [ApplicationStatus::Rejected, ApplicationStatus::Withdrawn, ApplicationStatus::Archived, ApplicationStatus::OfferRejected];
    $noAnswer = __('recruitment-applicant.application_answers.no_answer');
@endphp

<x-slot:title>{{ $applicant->full_name }}</x-slot:title>

<div class="mx-auto max-w-[1440px] space-y-6"
    x-data="{ openSection(id) { const target = document.getElementById(id); if (!target) return; let parent = target; while (parent && parent !== $el) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; } $nextTick(() => target.scrollIntoView({ block: 'start' })); } }"
    x-on:click="const link = $event.target.closest('a[href^=&quot;#&quot;]'); if (link && document.getElementById(link.hash.slice(1))) { $event.preventDefault(); openSection(link.hash.slice(1)); }">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.profile.title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.applicants'), 'route' => 'recruitment.applicants.index'], ['label' => __('recruitment-applicant.profile.title')]]" />

    {{-- Identity on the left, "where are they and what do I do next" on the right. --}}
    <section id="profile-overview" class="{{ $card }} scroll-mt-24">
        <div class="grid lg:grid-cols-[minmax(0,1fr)_24rem]">
            <div class="min-w-0 p-5 sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-5">
                    @if ($avatarUrl)
                        <img src="{{ $avatarUrl }}" alt="" class="size-20 shrink-0 rounded-full object-cover ring-1 ring-neutral-200 sm:size-24">
                    @else
                        <span aria-hidden="true" class="flex size-20 shrink-0 select-none items-center justify-center rounded-full bg-neutral-100 text-h3 font-semibold text-secondary-dark ring-1 ring-neutral-200 sm:size-24">{{ $initials }}</span>
                    @endif
                    <div class="min-w-0 flex-1">
                        <h1 class="text-h2 font-semibold text-neutral-900 wrap-break-word"><bdi>{{ $applicant->full_name }}</bdi></h1>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <span class="{{ $pill }}">{{ $applicant->isInternal() ? __('recruitment-applicant.profile.internal_candidate') : __('recruitment-applicant.profile.external_candidate') }}</span>
                            @if ($isPreviousApplicant)
                                <span class="{{ $pill }}">{{ __('recruitment-applicant.previous_applicant_flag') }}</span>
                            @endif
                            @if ($applicant->anonymized_at)
                                <span class="{{ $pill }}">{{ __('recruitment-applicant.anonymized') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Contact: things people copy or tap, so each is a link where it can be. --}}
                <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-neutral-100 pt-5 sm:grid-cols-2 xl:grid-cols-3" aria-label="{{ __('recruitment-applicant.profile.contact_information') }}">
                    <div class="flex min-w-0 items-start gap-3">
                        <flux:icon.envelope variant="mini" class="mt-0.5 size-4 shrink-0 text-secondary-medium" aria-hidden="true" />
                        <div class="min-w-0">
                            <dt class="text-caption text-secondary-medium">{{ __('validation.attributes.email') }}</dt>
                            <dd class="wrap-anywhere"><a href="mailto:{{ $applicant->email_normalized }}" class="font-medium text-secondary-dark hover:text-primary {{ $focus }}"><bdi dir="ltr">{{ $applicant->email_normalized }}</bdi></a></dd>
                        </div>
                    </div>
                    @if ($applicant->phone_normalized)
                        <div class="flex min-w-0 items-start gap-3">
                            <flux:icon.phone variant="mini" class="mt-0.5 size-4 shrink-0 text-secondary-medium" aria-hidden="true" />
                            <div class="min-w-0">
                                <dt class="text-caption text-secondary-medium">{{ __('recruitment-applicant.mobile') }}</dt>
                                <dd><a href="tel:{{ $applicant->phone_normalized }}" class="font-medium tabular-nums text-secondary-dark hover:text-primary {{ $focus }}"><bdi dir="ltr">{{ $applicant->phone_normalized }}</bdi></a></dd>
                            </div>
                        </div>
                    @endif
                    <div class="flex min-w-0 items-start gap-3">
                        <flux:icon.map-pin variant="mini" class="mt-0.5 size-4 shrink-0 text-secondary-medium" aria-hidden="true" />
                        <div class="min-w-0">
                            <dt class="text-caption text-secondary-medium">{{ __('recruitment-applicant.city') }}</dt>
                            <dd class="font-medium text-secondary-dark">{{ $profileCity?->name ?? '—' }}</dd>
                        </div>
                    </div>
                    <div class="flex min-w-0 items-start gap-3">
                        <flux:icon.arrow-down-tray variant="mini" class="mt-0.5 size-4 shrink-0 text-secondary-medium" aria-hidden="true" />
                        <div class="min-w-0">
                            <dt class="text-caption text-secondary-medium">{{ __('recruitment-applicant.source') }}</dt>
                            <dd class="font-medium text-secondary-dark">{{ RecruitmentApplicant::sourceLabel($applicant->source) }}</dd>
                        </div>
                    </div>
                    <div class="flex min-w-0 items-start gap-3">
                        <flux:icon.calendar variant="mini" class="mt-0.5 size-4 shrink-0 text-secondary-medium" aria-hidden="true" />
                        <div class="min-w-0">
                            <dt class="text-caption text-secondary-medium">{{ __('recruitment-applicant.first_seen') }}</dt>
                            <dd class="font-medium tabular-nums text-secondary-dark">
                                @if ($applicant->created_at)
                                    <time datetime="{{ $applicant->created_at->toDateString() }}">{{ $applicant->created_at->translatedFormat('j M Y') }}</time>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                    </div>
                </dl>
            </div>

            <aside aria-labelledby="recruitment-overview-title" class="flex flex-col border-t border-neutral-100 bg-neutral-50 p-5 sm:p-6 lg:rounded-e-surface lg:border-s lg:border-t-0">
                <h2 id="recruitment-overview-title" class="{{ $eyebrow }}">{{ __('recruitment-applicant.profile.current_application') }}</h2>
                @if ($currentApplication)
                    <p class="mt-2 text-h4 font-semibold text-neutral-900 wrap-break-word">{{ $currentCampaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy') }}</p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <span class="{{ $pill }} bg-white ring-1 ring-neutral-200"><span aria-hidden="true" class="size-1.5 shrink-0 rounded-full {{ $statusDot($currentApplication->status) }}"></span>{{ __('recruitment-applicant.statuses.'.$currentApplication->status) }}</span>
                        @if ($currentCampaign)
                            <span class="text-small text-secondary-medium">{{ __('recruitment-applicant.campaign_number', ['number' => $currentCampaign->id]) }}</span>
                        @endif
                    </div>

                    <div class="mt-auto pt-5">
                        <p class="{{ $eyebrow }}">{{ __('recruitment-applicant.profile.next_step') }}</p>
                        <div class="mt-2">
                            {{-- The page's single red button: the one move that keeps this application going. --}}
                            @if ($currentApplication->status === ApplicationStatus::Shortlisted && Gate::allows('schedule', [\App\Models\RecruitmentInterview::class, $currentApplication]))
                                <a href="#application-{{ $currentApplication->id }}-interviews" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-primary px-4 text-small font-semibold text-white shadow-e1 hover:opacity-90 {{ $focus }}">{{ __('recruitment-applicant.interviews.schedule') }}<flux:icon.arrow-right variant="micro" class="size-4 rtl:rotate-180" aria-hidden="true" /></a>
                            @elseif ($currentApplication->status === ApplicationStatus::Selected && Gate::allows('create', [\App\Models\RecruitmentJobOffer::class, $currentApplication]))
                                <a href="#application-{{ $currentApplication->id }}-interviews" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-control bg-primary px-4 text-small font-semibold text-white shadow-e1 hover:opacity-90 {{ $focus }}">{{ __('recruitment-applicant.offers.create') }}<flux:icon.arrow-right variant="micro" class="size-4 rtl:rotate-180" aria-hidden="true" /></a>
                            @elseif ($currentApplication->status === ApplicationStatus::InterviewScheduled)
                                <p class="flex items-center gap-2 text-small text-secondary-dark"><flux:icon.clock variant="mini" class="size-4 text-secondary-medium" aria-hidden="true" />{{ __('recruitment-applicant.profile.awaiting_interview') }}</p>
                            @elseif ($nextTarget)
                                <button type="button" wire:click="beginStatusChange({{ $currentApplication->id }}, '{{ $nextTarget }}')" class="inline-flex min-h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-control bg-primary px-4 text-small font-semibold text-white shadow-e1 hover:opacity-90 {{ $focus }}">{{ __('recruitment-applicant.decisions.actions.'.$nextTarget) }}<flux:icon.arrow-right variant="micro" class="size-4 rtl:rotate-180" aria-hidden="true" /></button>
                            @else
                                <p class="text-small text-secondary-medium">{{ __('recruitment-applicant.profile.no_action') }}</p>
                            @endif
                        </div>
                    </div>
                @else
                    <p class="mt-3 text-small text-secondary-medium">{{ __('recruitment-applicant.no_applications') }}</p>
                @endif
            </aside>
        </div>
    </section>

    @if ($currentApplication)
        <livewire:recruitment.applicants.applicant-profile-access :applicant-id="$applicant->id" />
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {{-- Applications --}}
        <section id="applications" aria-labelledby="applications-title" class="min-w-0 scroll-mt-24 space-y-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="applications-title" class="{{ $sectionTitle }}">{{ __('recruitment-applicant.history') }} <span class="text-secondary-medium tabular-nums">({{ $applications->count() }})</span></h2>
                @if ($hiddenApplications > 0)
                    <p class="text-small text-secondary-medium">{{ trans_choice('recruitment-applicant.hidden_applications', $hiddenApplications, ['count' => $hiddenApplications]) }}</p>
                @endif
            </div>

            @forelse ($applications as $application)
                @php
                    $campaign = $application->campaign;
                    $effective = $campaign ? $states->effective($campaign) : null;
                    $campaignOpen = in_array($effective, [HiringCampaignStatus::Published, HiringCampaignStatus::Open], true);
                    $cvFiles = $application->files->where('file_type', \App\Models\RecruitmentApplicantFile::TYPE_CV);
                    $availability = RecruitmentCvAvailability::of($cvFiles);
                    $isDelegated = ! $normalScopeApplicationIds->contains($application->id);
                    $ended = in_array($application->status, $endedStatuses, true);
                    $reached = $stepIndex($application->status);
                    $answerSections = $applicationAnswers[$application->id] ?? [];
                    $targets = $statusTargets[$application->id] ?? [];
                @endphp

                {{-- The newest application opens by default; older ones stay folded. --}}
                <details id="application-{{ $application->id }}" wire:key="application-{{ $application->id }}" class="group {{ $card }} scroll-mt-24" @if ($loop->first || $statusApplicationId === $application->id) open @endif>
                    <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-surface p-5 {{ $focus }} [&::-webkit-details-marker]:hidden">
                        <div class="min-w-0">
                            {{-- Focus lands here after a decision: the buttons that led to it are gone. --}}
                            <h3 id="application-{{ $application->id }}-title" tabindex="-1" class="scroll-mt-24 text-h4 font-semibold text-neutral-900 wrap-break-word">
                                {{ $campaign?->vacancy?->position?->title ?? __('recruitment-applicant.no_vacancy') }}
                            </h3>
                            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-small text-secondary-medium">
                                @if ($campaign)
                                    <span>{{ __('recruitment-applicant.campaign_number', ['number' => $campaign->id]) }}</span>
                                    @if ($effective !== null && ! $campaignOpen)
                                        <span class="{{ $pill }}">{{ HiringCampaignStatus::getLocalizedName($effective)['value'] }}</span>
                                    @endif
                                    <span aria-hidden="true">·</span>
                                @endif
                                <span>{{ __('recruitment-applicant.applied_at') }}
                                    @if ($application->submitted_at)
                                        <time datetime="{{ $application->submitted_at->toIso8601String() }}" class="tabular-nums">{{ $application->submitted_at->translatedFormat('j M Y, H:i') }}</time>
                                    @else
                                        —
                                    @endif
                                </span>
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $pill }}"><flux:icon.document-text variant="micro" class="size-3.5" aria-hidden="true" />{{ __('recruitment-applicant.cv_availability.'.$availability) }}</span>
                            <span class="{{ $pill }} bg-white ring-1 ring-neutral-200"><span aria-hidden="true" class="size-1.5 shrink-0 rounded-full {{ $statusDot($application->status) }}"></span>{{ __('recruitment-applicant.statuses.'.$application->status) }}</span>
                            <span class="sr-only group-open:hidden">{{ __('recruitment-applicant.profile.expand_application') }}</span>
                            <span class="sr-only hidden group-open:inline">{{ __('recruitment-applicant.profile.collapse_application') }}</span>
                            <flux:icon.chevron-down variant="mini" class="size-5 text-secondary-medium transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                        </div>
                    </summary>

                    <div class="divide-y divide-neutral-100 border-t border-neutral-100">
                        {{-- Progress: one glance says how far they got, or where it stopped. --}}
                        <div class="px-5 py-4">
                            <ol aria-label="{{ __('recruitment-applicant.profile.workflow') }}" class="grid grid-cols-6">
                                @foreach ($pipeline as $i => $step)
                                    @php
                                        $done = ! $ended && $reached !== false && $i < $reached;
                                        $current = ! $ended && $reached === $i;
                                    @endphp
                                    <li class="relative flex flex-col items-center gap-1.5 text-center" @if ($current) aria-current="step" @endif>
                                        @unless ($loop->first)
                                            <span aria-hidden="true" class="absolute top-[7px] end-1/2 h-0.5 w-full {{ $done || $current ? 'bg-neutral-900' : 'bg-neutral-200' }}"></span>
                                        @endunless
                                        <span aria-hidden="true" @class([
                                            'relative z-10 flex size-4 items-center justify-center rounded-full',
                                            'bg-neutral-900 text-white' => $done,
                                            'bg-white ring-4 ring-neutral-900 ring-inset' => $current,
                                            'border-2 border-neutral-200 bg-white' => ! $done && ! $current,
                                        ])>
                                            @if ($done)<flux:icon.check variant="micro" class="size-3" />@endif
                                        </span>
                                        <span @class([
                                            'text-caption leading-tight max-sm:sr-only',
                                            'font-semibold text-neutral-900' => $current,
                                            'text-secondary-dark' => $done,
                                            'text-secondary-medium' => ! $done && ! $current,
                                        ])>{{ $step === ApplicationStatus::Finalist ? __('recruitment-applicant.statuses.finalist') : __('recruitment-applicant.statuses.'.$step) }}</span>
                                    </li>
                                @endforeach
                            </ol>
                            @if ($ended)
                                <p class="mt-3 flex items-center gap-2 text-small text-secondary-medium">
                                    <span aria-hidden="true" class="size-1.5 rounded-full bg-neutral-300"></span>
                                    {{ __('recruitment-applicant.statuses.'.$application->status) }}
                                </p>
                            @endif
                        </div>

                        {{-- Decisions sit first inside the application: they are what people come here to do. --}}
                        @unless ($isDelegated)
                            @php
                                $showEndorse = $canEndorse;
                                if ($showEndorse) {
                                    $myEndorsement = $endorsementService->hasEndorsed(auth()->user(), $application);
                                    $hrVoted = $application->endorsements->contains('voter_role', 'hr');
                                    $mgVoted = $application->endorsements->contains('voter_role', 'manager');
                                }
                            @endphp
                            @if (! empty($targets) || $showEndorse || $application->rejectionNotice || $application->decisions->isNotEmpty())
                                <section aria-labelledby="workflow-{{ $application->id }}" class="space-y-4 p-5">
                                    <h4 id="workflow-{{ $application->id }}" class="{{ $eyebrow }}">{{ __('recruitment-applicant.profile.workflow') }}</h4>

                                    @if (! empty($targets))
                                        @if ($statusApplicationId === $application->id)
                                            {{-- Keyed apart from the buttons it replaces, so each is swapped in whole. --}}
                                            <div wire:key="decision-panel-{{ $application->id }}" class="rounded-control border border-neutral-200 bg-neutral-50 p-4">
                                                <h4 id="decision-panel-{{ $application->id }}" tabindex="-1" class="scroll-mt-24 font-semibold text-neutral-900">
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
                                                                class="mt-1 min-h-11 w-full rounded-control border-neutral-300 bg-white shadow-e1 sm:min-h-10">
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
                                                            class="mt-1 w-full rounded-control border-neutral-300 bg-white shadow-e1"></textarea>
                                                        <p id="statusReason-hint" class="mt-1 text-xs text-secondary-medium">{{ __('recruitment-applicant.decisions.internal_note_hint') }}</p>
                                                        <x-forms.input-error field="statusReason" input-id="statusReason" />
                                                    </div>
                                                    @if ($statusTarget === ApplicationStatus::Rejected)
                                                        <div class="sm:col-span-2">
                                                            @if ($application->rejectionNotice?->isSettled())
                                                                <p class="text-small text-secondary-medium">{{ __('recruitment-applicant.decisions.already_notified') }}</p>
                                                            @else
                                                                <label class="flex cursor-pointer items-start gap-2 text-small">
                                                                    <input type="checkbox" wire:model="statusNotify" class="mt-0.5 rounded border-neutral-300 text-neutral-900">
                                                                    <span>
                                                                        {{ __('recruitment-applicant.decisions.notify') }}
                                                                        <span class="block text-xs text-secondary-medium">{{ __('recruitment-applicant.decisions.notify_hint') }}</span>
                                                                    </span>
                                                                </label>
                                                            @endif
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    <x-primary-button type="button" wire:click="openStatusConfirmation" wire:target="openStatusConfirmation">
                                                        {{ __('recruitment-applicant.decisions.confirm.'.$statusTarget) }}
                                                    </x-primary-button>
                                                    <x-secondary-button wire:click="cancelStatusChange">
                                                        {{ __('recruitment-applicant.cancel') }}
                                                    </x-secondary-button>
                                                </div>
                                            </div>
                                        @else
                                            {{-- Forward moves first in ink, then neutral ones; rejection sits apart at the end, outlined red. --}}
                                            @php
                                                $forward = collect($targets)->reject(fn ($t) => $t === ApplicationStatus::Rejected)->values();
                                                $canReject = in_array(ApplicationStatus::Rejected, $targets, true);
                                            @endphp
                                            <div wire:key="decisions-{{ $application->id }}" class="flex flex-wrap items-center gap-2">
                                                @foreach ($forward as $target)
                                                    @php
                                                        $label = $application->status === ApplicationStatus::Rejected
                                                            ? __('recruitment-applicant.decisions.actions.reopen')
                                                            : __('recruitment-applicant.decisions.actions.'.$target);
                                                    @endphp
                                                    <button type="button" id="decide-{{ $application->id }}-{{ $target }}" wire:key="decide-{{ $application->id }}-{{ $target }}" wire:click="beginStatusChange({{ $application->id }}, '{{ $target }}')"
                                                        class="{{ $loop->first ? $inkBtn : $quietBtn }}">
                                                        {{ $label }}
                                                    </button>
                                                @endforeach
                                                @if ($canReject)
                                                    <button type="button" id="decide-{{ $application->id }}-{{ ApplicationStatus::Rejected }}" wire:key="decide-{{ $application->id }}-{{ ApplicationStatus::Rejected }}" wire:click="beginStatusChange({{ $application->id }}, '{{ ApplicationStatus::Rejected }}')"
                                                        class="{{ $dangerBtn }} sm:ms-auto">
                                                        {{ __('recruitment-applicant.decisions.actions.'.ApplicationStatus::Rejected) }}
                                                    </button>
                                                @endif
                                            </div>
                                        @endif
                                    @endif

                                    {{-- Endorsement: HR and managers each cast one shortlist vote. --}}
                                    @if ($showEndorse)
                                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-neutral-200 px-4 py-3">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                                <span class="text-small font-semibold text-secondary-dark">{{ __('recruitment-applicant.endorsement.title') }}</span>
                                                @foreach (['hr' => $hrVoted, 'manager' => $mgVoted] as $role => $voted)
                                                    <span class="inline-flex items-center gap-1.5 text-small {{ $voted ? 'font-semibold text-neutral-900' : 'text-secondary-medium' }}">
                                                        @if ($voted)
                                                            <span class="flex size-4 items-center justify-center rounded-full bg-neutral-900 text-white" aria-hidden="true"><flux:icon.check variant="micro" class="size-3" /></span>
                                                        @else
                                                            <span class="size-4 rounded-full border-2 border-neutral-300" aria-hidden="true"></span>
                                                        @endif
                                                        {{ __('recruitment-applicant.endorsement.role_'.$role) }}
                                                    </span>
                                                @endforeach
                                            </div>
                                            @if ($myEndorsement)
                                                <button type="button" wire:click="retractEndorsement({{ $application->id }})" class="{{ $quietBtn }}">{{ __('recruitment-applicant.endorsement.retract') }}</button>
                                            @else
                                                <button type="button" wire:click="endorse({{ $application->id }})" class="{{ $inkBtn }}"><flux:icon.hand-thumb-up variant="micro" class="size-4" aria-hidden="true" />{{ __('recruitment-applicant.endorsement.endorse') }}</button>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($application->rejectionNotice)
                                        @php $notice = $application->rejectionNotice; @endphp
                                        <p class="flex items-center gap-2 text-small text-secondary-medium">
                                            <flux:icon.envelope variant="micro" class="size-4" aria-hidden="true" />
                                            {{ __('recruitment-applicant.decisions.notice_status.'.$notice->status, [
                                                'date' => ($notice->sent_at ?? $notice->failed_at ?? $notice->queued_at)?->format('Y-m-d H:i'),
                                            ]) }}
                                        </p>
                                    @endif

                                    {{-- Decision history as a timeline: who, when, from/to and why. Staff only. --}}
                                    @if ($application->decisions->isNotEmpty())
                                        <details class="group/history">
                                            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-1.5 rounded-control text-small font-semibold text-secondary-dark hover:text-neutral-900 sm:min-h-8 {{ $focus }} [&::-webkit-details-marker]:hidden">
                                                <flux:icon.chevron-right variant="micro" class="size-4 transition-transform duration-150 group-open/history:rotate-90 rtl:rotate-180 rtl:group-open/history:rotate-90 motion-reduce:transition-none" aria-hidden="true" />
                                                {{ __('recruitment-applicant.status_change.history') }}
                                                <span class="tabular-nums text-secondary-medium">({{ $application->decisions->count() }})</span>
                                            </summary>
                                            <ol class="mt-3 space-y-4 border-s-2 border-neutral-200 ps-5 text-small">
                                                @foreach ($application->decisions as $decision)
                                                    <li class="relative">
                                                        <span aria-hidden="true" class="absolute -start-[27px] top-1 size-3 rounded-full border-2 border-white {{ $statusDot($decision->to_status) }} ring-1 ring-neutral-300"></span>
                                                        <p class="font-semibold text-neutral-900">{{ __('recruitment-applicant.statuses.'.$decision->from_status) }} <x-recruitment.transition-arrow /> {{ __('recruitment-applicant.statuses.'.$decision->to_status) }}</p>
                                                        <p class="text-secondary-medium">
                                                            {{ $decision->decidedBy?->profile?->name ?? $decision->decidedBy?->name ?? '—' }}
                                                            @if ($decision->decided_at)
                                                                &middot; <time datetime="{{ $decision->decided_at->toIso8601String() }}" class="tabular-nums">{{ $decision->decided_at->translatedFormat('j M Y, H:i') }}</time>
                                                            @endif
                                                        </p>
                                                        @if ($decision->to_status === ApplicationStatus::Rejected && $decision->reason_code)
                                                            @php $reasonKey = 'recruitment-applicant.decisions.rejection_reasons.'.$decision->reason_code; @endphp
                                                            <p class="mt-1 text-secondary-dark">{{ __('recruitment-applicant.decisions.reason_code') }}: {{ Lang::has($reasonKey) ? __($reasonKey) : $decision->reason_code }}</p>
                                                        @endif
                                                        @if ($decision->reason_text)
                                                            <p class="mt-1.5 whitespace-pre-line rounded-control bg-neutral-50 px-3 py-2 text-secondary-dark wrap-break-word">{{ $decision->reason_text }}</p>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ol>
                                        </details>
                                    @endif
                                </section>
                            @endif
                        @endunless

                        <div class="p-5">
                            <livewire:recruitment.applicants.application-review-panel :application-id="$application->id" :show-access="false" :key="'application-reviews-'.$application->id" />
                        </div>

                        {{-- Evidence: how they match, and the files behind it. --}}
                        <div class="grid gap-x-8 gap-y-6 p-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                            <section aria-labelledby="evaluation-{{ $application->id }}" class="min-w-0">
                                <h4 id="evaluation-{{ $application->id }}" class="{{ $eyebrow }}">{{ __('recruitment-applicant.profile.evaluation') }}</h4>
                                <div class="mt-3">
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
                                </div>
                            </section>

                            <section aria-labelledby="documents-{{ $application->id }}" class="min-w-0">
                                <h4 id="documents-{{ $application->id }}" class="{{ $eyebrow }}">{{ __('recruitment-applicant.profile.documents') }} <span class="tabular-nums">({{ $application->files->count() }})</span></h4>
                                {{-- The link appears only for users who may download; the route asks the policy again. --}}
                                @if ($application->files->isNotEmpty())
                                    <ul class="mt-2 divide-y divide-neutral-100 text-small">
                                        @foreach ($application->files as $file)
                                            @include('livewire.recruitment.applicants.partials.file-row', ['file' => $file])
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="mt-3 rounded-control border border-dashed border-neutral-300 px-4 py-5 text-center text-small text-secondary-medium">{{ __('recruitment-applicant.cv_availability.missing') }}</p>
                                @endif
                            </section>
                        </div>

                        {{-- Answers read like a form, not a stack of boxes: one heading per section, label above value, two columns. --}}
                        <details class="group/answers" @if (count($answerSections) <= 2) open @endif>
                            <summary class="flex min-h-11 cursor-pointer list-none flex-wrap items-center justify-between gap-3 px-5 py-4 {{ $focus }} [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="{{ $eyebrow }}">{{ __('recruitment-applicant.application_answers.title') }}</span>
                                    <span class="mt-0.5 block text-small text-secondary-medium">{{ __('recruitment-applicant.application_answers.hint') }}</span>
                                </span>
                                <span class="flex items-center gap-2">
                                    @if ($application->formVersion)
                                        <span class="{{ $pill }}">{{ __('recruitment-applicant.application_answers.form_version', ['version' => $application->formVersion->version_no]) }}</span>
                                    @endif
                                    <flux:icon.chevron-down variant="mini" class="size-5 text-secondary-medium transition-transform duration-200 group-open/answers:rotate-180 motion-reduce:transition-none" aria-hidden="true" />
                                </span>
                            </summary>
                            <div class="px-5 pb-5">
                                @if ($answerSections !== [])
                                    <div class="space-y-6">
                                        @foreach ($answerSections as $section)
                                            <section>
                                                <h5 class="border-b border-neutral-100 pb-2 text-small font-semibold text-neutral-900">{{ $section['label'] }}</h5>
                                                <dl class="mt-3 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                                                    @foreach ($section['answers'] as $answer)
                                                        @php
                                                            $empty = $answer['value'] === $noAnswer;
                                                            $long = mb_strlen($answer['value']) > 90 || str_contains($answer['value'], "\n");
                                                        @endphp
                                                        <div @class(['min-w-0', 'sm:col-span-2' => $long])>
                                                            <dt class="text-caption text-secondary-medium">{{ $answer['label'] }}</dt>
                                                            <dd @class([
                                                                'mt-0.5 whitespace-pre-line text-small wrap-break-word',
                                                                'text-secondary-light' => $empty,
                                                                'font-medium text-neutral-900' => ! $empty,
                                                                'max-w-prose leading-relaxed' => $long,
                                                            ])>{{ $answer['value'] }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            </section>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="rounded-control border border-dashed border-neutral-300 px-4 py-5 text-center text-small text-secondary-medium">{{ __('recruitment-applicant.application_answers.none') }}</p>
                                @endif
                            </div>
                        </details>

                        {{-- Interviews and offer. Keyed on the status so a decision re-renders them. --}}
                        @unless ($isDelegated)
                            <section id="application-{{ $application->id }}-interviews" aria-labelledby="interviews-{{ $application->id }}" class="scroll-mt-24 p-5">
                                <h4 id="interviews-{{ $application->id }}" class="mb-3 {{ $eyebrow }}">{{ __('recruitment-applicant.profile.interviews_and_offer') }}</h4>
                                <livewire:recruitment.applicants.application-interviews :application-id="$application->id" :key="'interviews-'.$application->id.'-'.$application->status" />
                                <livewire:recruitment.applicants.application-offer :application-id="$application->id" :key="'offer-'.$application->id.'-'.$application->status" />
                            </section>

                            <div class="space-y-4 p-5">
                                <livewire:recruitment.applicants.application-tags :application-id="$application->id" :key="'tags-'.$application->id" />
                                <livewire:recruitment.applicants.application-flags :application-id="$application->id" :key="'flags-'.$application->id" />
                            </div>
                        @endunless
                    </div>
                </details>
            @empty
                <p class="rounded-surface border border-dashed border-neutral-300 px-4 py-8 text-center text-small text-secondary-medium">{{ __('recruitment-applicant.no_applications') }}</p>
            @endforelse

            {{-- Files with no application behind them: talent pool, or an application since removed. --}}
            @if ($orphanFiles->isNotEmpty())
                <section aria-labelledby="unattached-files" class="{{ $card }} p-5">
                    <h2 id="unattached-files" class="{{ $sectionTitle }}">{{ __('recruitment-applicant.unattached_files') }}</h2>
                    <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment-applicant.unattached_files_hint') }}</p>
                    <ul class="mt-3 divide-y divide-neutral-100 text-small">
                        @foreach ($orphanFiles as $file)
                            @include('livewire.recruitment.applicants.partials.file-row', ['file' => $file, 'panelHeading' => 'h3'])
                        @endforeach
                    </ul>
                </section>
            @endif
        </section>

        {{-- Side rail: a jump list for long profiles, and the internal notes kept in view while reading. --}}
        <aside class="space-y-4 xl:sticky xl:top-24">
            <nav aria-label="{{ __('recruitment-applicant.profile.quick_navigation') }}" class="{{ $card }} p-2">
                <ul class="space-y-0.5 text-small">
                    <li><a href="#profile-overview" class="flex min-h-11 items-center rounded-control px-3 text-secondary-dark hover:bg-selection sm:min-h-9 {{ $focus }}">{{ __('recruitment-applicant.profile.overview') }}</a></li>
                    <li><a href="#applications" class="flex min-h-11 items-center rounded-control px-3 text-secondary-dark hover:bg-selection sm:min-h-9 {{ $focus }}">{{ __('recruitment-applicant.profile.applications') }}</a></li>
                    @if ($applications->count() > 1)
                        @foreach ($applications as $application)
                            <li>
                                <a href="#application-{{ $application->id }}" class="flex min-h-11 items-center justify-between gap-2 rounded-control ps-6 pe-3 text-secondary-medium hover:bg-selection hover:text-secondary-dark sm:min-h-9 {{ $focus }}">
                                    <span class="truncate">{{ __('recruitment-applicant.campaign_number', ['number' => $application->campaign_id]) }}</span>
                                    <span aria-hidden="true" class="size-1.5 shrink-0 rounded-full {{ $statusDot($application->status) }}"></span>
                                    <span class="sr-only">{{ __('recruitment-applicant.statuses.'.$application->status) }}</span>
                                </a>
                            </li>
                        @endforeach
                    @endif
                    @if ($canViewInternalNotes)
                        <li><a href="#internal-notes" class="flex min-h-11 items-center rounded-control px-3 text-secondary-dark hover:bg-selection sm:min-h-9 {{ $focus }}">{{ __('recruitment-applicant.profile.internal_notes') }}</a></li>
                    @endif
                </ul>
            </nav>

            @if ($canViewInternalNotes)
                <div id="internal-notes" class="scroll-mt-24 xl:max-h-[calc(100vh-14rem)] xl:overflow-y-auto xl:rounded-surface">
                    <livewire:recruitment.applicants.notes :applicant-id="$applicant->id" />
                </div>
            @endif
        </aside>
    </div>

    <x-recruitment.confirmation-modal scope="applicant-profile" />
</div>
