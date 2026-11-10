<x-slot:title>{{ __('recruitment.details') }} · {{ $request->request_number }}</x-slot:title>

<div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
        <livewire:layout.breadcrumbs :title="$request->request_number" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment.navigation_requests'), 'route' => 'recruitment.requests.index'], ['label' => $request->request_number]]" />
        <div class="flex flex-wrap items-center gap-3">
            <span class="rounded-full bg-secondary-soft/60 px-4 py-2 text-sm font-semibold">{{ \App\Enums\RecruitmentRequestStatus::getLocalizedName($request->status)['value'] }}</span>
            @can('update', $request)
                <a class="rounded-control border border-secondary-light px-4 py-2 text-sm font-semibold hover:bg-secondary-soft/30" href="{{ route('recruitment.requests.edit', ['requestId' => $request->id]) }}">{{ __('recruitment.edit') }}</a>
            @endcan
            @can('cancel', $request)
                <button type="button" class="cursor-pointer rounded-control border border-secondary-light px-4 py-2 text-sm font-semibold hover:bg-secondary-soft/30" x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'request-details', action: 'cancelRequest', message: @js(__('recruitment.confirm_cancel')), confirmLabel: @js(__('recruitment.cancel_request')), tone: 'danger' })">{{ __('recruitment.cancel_request') }}</button>
            @endcan
            @can('submit', $request)
                {{-- Disabled while in flight so a double click cannot fire twice; the
                     service is idempotent regardless, which is what actually protects it. --}}
                <x-primary-button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('recruitment.submit') }}</x-primary-button>
            @endcan
            @if ($canCreateCampaign)
                <a href="{{ route('recruitment.campaigns.create', ['vacancyId' => $request->vacancy->id]) }}" wire:navigate
                    class="cursor-pointer rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover">
                    {{ __('recruitment.campaign.create') }}
                </a>
            @endif
        </div>
    </header>

    @if ($pendingStep)
        {{-- Shown only to the approver this step is waiting on. The service asks
             the same six questions again, so this is convenience, not a guard. --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-6" aria-label="{{ __('recruitment.your_decision') }}">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.your_decision') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">{{ \App\Enums\WorkflowStepKey::getLocalizedName((int) $pendingStep->workflowStep?->step_key)['value'] }}</p>

            @if (! $askingForChanges && ! $rejecting)
                {{-- The three decisions, and nothing else. Asking for changes is
                     a longer conversation than approving or rejecting, so its
                     fields appear once it is chosen rather than sitting open
                     beside decisions that do not use them. --}}
                <div class="mt-4 flex flex-wrap gap-3">
                    <x-primary-button type="button" wire:click="decide('approve')"
                        wire:loading.attr="disabled" wire:target="decide">
                        {{ __('recruitment.approve') }}
                    </x-primary-button>

                    <button type="button"
                        class="cursor-pointer rounded-control border border-secondary-light px-4 py-2 text-sm font-semibold hover:bg-secondary-soft/30"
                        wire:click="askForChanges">
                        {{ __('recruitment.request_changes') }}
                    </button>

                    <button type="button"
                        class="cursor-pointer rounded-control border border-primary/30 px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/5"
                        wire:click="askToReject">
                        {{ __('recruitment.reject_action') }}
                    </button>
                </div>

                <p class="mt-2 text-sm text-primary">@error('step'){{ $message }}@enderror @error('version'){{ $message }}@enderror @error('request'){{ $message }}@enderror @error('comment'){{ $message }}@enderror</p>
            @elseif ($rejecting)
                {{-- Step two of a rejection: the reason, which the decision
                     cannot be recorded without. --}}
                <div class="mt-4">
                    <x-forms.input-label for="rejectionReason" required>{{ __('recruitment.rejection_reason') }}</x-forms.input-label>
                    <textarea id="rejectionReason" rows="3" wire:model="decisionComment"
                        class="mt-1 w-full rounded-control border-secondary-light" dir="auto"></textarea>
                    <p class="mt-1 text-sm text-primary">@error('comment'){{ $message }}@enderror @error('step'){{ $message }}@enderror @error('version'){{ $message }}@enderror @error('request'){{ $message }}@enderror</p>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button"
                        class="cursor-pointer rounded-control bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover"
                        wire:loading.attr="disabled" wire:target="decide"
                        x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'request-details', action: 'decide', args: ['reject'], message: @js(__('recruitment.confirm_reject')), confirmLabel: @js(__('recruitment.reject_action')), tone: 'danger' })">
                        {{ __('recruitment.reject_action') }}
                    </button>

                    <button type="button"
                        class="cursor-pointer rounded-control border border-secondary-light px-4 py-2 text-sm font-semibold hover:bg-secondary-soft/30"
                        wire:click="cancelChangeRequest">
                        {{ __('recruitment.back_to_decisions') }}
                    </button>
                </div>
            @else
                {{-- Step two: name the fields, say why, send it back. --}}
                <fieldset class="mt-4">
                    <legend class="text-sm font-semibold">{{ __('recruitment.fields_to_unlock') }}</legend>
                    <p class="text-sm text-secondary-medium">{{ __('recruitment.fields_to_unlock_hint') }}</p>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($selectableFields as $field)
                            <label class="flex items-center gap-2 text-sm" wire:key="unlock-{{ $field }}">
                                <input type="checkbox" value="{{ $field }}" wire:model="requestedFields"
                                    class="rounded border-secondary-light text-primary">
                                <span>{{ __('recruitment.fields.'.$field) }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-sm text-primary">@error('fields'){{ $message }}@enderror</p>
                </fieldset>

                <div class="mt-4">
                    <x-forms.input-label for="decisionComment" required>{{ __('recruitment.decision_comment') }}</x-forms.input-label>
                    <textarea id="decisionComment" rows="3" wire:model="decisionComment"
                        class="mt-1 w-full rounded-control border-secondary-light" dir="auto"></textarea>
                    <p class="mt-1 text-sm text-primary">@error('comment'){{ $message }}@enderror @error('step'){{ $message }}@enderror @error('version'){{ $message }}@enderror @error('request'){{ $message }}@enderror</p>
                </div>

                <div class="mt-4 flex flex-wrap gap-3">
                    <x-primary-button type="button" wire:click="decide('request_changes')"
                        wire:loading.attr="disabled" wire:target="decide">
                        {{ __('recruitment.send_change_request') }}
                    </x-primary-button>

                    <button type="button"
                        class="cursor-pointer rounded-control border border-secondary-light px-4 py-2 text-sm font-semibold hover:bg-secondary-soft/30"
                        wire:click="cancelChangeRequest">
                        {{ __('recruitment.back_to_decisions') }}
                    </button>
                </div>
            @endif
        </section>
    @endif

    @include('livewire.recruitment.requests.partials.approval-track')

    @if ($unlockedFields && (int) $request->status === \App\Enums\RecruitmentRequestStatus::ChangesRequested)
        {{-- The requester's side of the same ask: everything else on the
             request stays locked, and the server refuses it either way. --}}
        <section class="rounded-surface border border-secondary-soft bg-white p-6" aria-label="{{ __('recruitment.fields_you_may_edit') }}">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.fields_you_may_edit') }}</h2>
            <p class="mt-1 text-sm text-secondary-medium">{{ __('recruitment.fields_you_may_edit_hint') }}</p>
            <ul class="mt-2 flex flex-wrap gap-2">
                @foreach ($unlockedFields as $field)
                    <li class="rounded-control border border-secondary-soft px-2 py-1 text-sm font-medium">{{ __('recruitment.fields.'.$field) }}</li>
                @endforeach
            </ul>
        </section>
    @endif
    @if ($awaitingExecution)
        {{-- The decision is made; the change has not happened. A reduction or a
             closure dated ahead waits for that date, and until then the post is
             still open. Saying so here is the only place anyone would learn it. --}}
        <section class="rounded-surface border border-secondary-light bg-secondary-soft/60 p-6" aria-label="{{ __('recruitment.awaiting_execution') }}">
            <h2 class="text-h4 font-semibold text-secondary-dark">{{ __('recruitment.awaiting_execution') }}</h2>
            @if ($effectiveDate)
                <p class="mt-1 text-sm text-secondary-dark">{{ __('recruitment.effective_on', ['date' => $effectiveDate->toDateString()]) }}</p>
            @endif
            @if ($mayExecuteNow)
                <div class="mt-4">
                    <button type="button"
                        class="rounded-control border border-secondary-light bg-white px-4 py-2 text-sm font-semibold text-secondary-dark hover:bg-secondary-soft/60"
                        x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'request-details', action: 'executeNow', message: @js(__('recruitment.confirm_execute_now')), confirmLabel: @js(__('recruitment.execute_now')) })">{{ __('recruitment.execute_now') }}</button>
                </div>
            @endif
        </section>
    @endif
    @if ($previousRejections->isNotEmpty())
        {{-- D61: context for whoever reads the request, never a reason to hide it. --}}
        <section role="note" class="rounded-surface border border-secondary-soft bg-white p-6" aria-label="{{ __('recruitment.previous_rejections') }}">
            <h2 class="text-h4 font-semibold text-secondary-dark">{{ __('recruitment.previous_rejections') }}</h2>
            <p class="mt-1 text-sm text-secondary-dark">{{ __('recruitment.previous_rejections_help') }}</p>
            <ul class="mt-4 space-y-3">
                @foreach ($previousRejections as $rejected)
                    @php $decision = $rejected->events->first(); @endphp
                    <li class="rounded-control border border-secondary-light bg-white p-4" wire:key="rejection-{{ $rejected->id }}">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-semibold" dir="auto">{{ $rejected->request_number }}</span>
                            <time class="text-sm text-secondary-medium" datetime="{{ ($decision?->created_at ?? $rejected->updated_at)?->toIso8601String() }}">{{ ($decision?->created_at ?? $rejected->updated_at)?->format('Y-m-d') }}</time>
                        </div>
                        <p class="mt-1 text-sm text-secondary-medium">
                            {{ __('recruitment.requester') }}: {{ $rejected->requester?->profile?->name ?? $rejected->requester?->name ?? '-' }}
                            @if ($decision?->actor)
                                &middot; {{ __('recruitment.rejected_by') }}: {{ $decision->actor->profile?->name ?? $decision->actor->name }}
                            @endif
                        </p>
                        @if (filled($decision?->comment))
                            <p class="mt-2 whitespace-pre-wrap break-words" dir="auto">{{ $decision->comment }}</p>
                        @else
                            <p class="mt-2 text-sm text-secondary-medium">{{ __('recruitment.no_reason_recorded') }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    <section class="rounded-surface border border-secondary-soft bg-white p-6" aria-label="{{ __('recruitment.details') }}">
        <dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                'type' => \App\Enums\RecruitmentRequestType::getLocalizedName($request->request_type)['value'],
                'mode' => \App\Enums\RecruitmentRequestMode::getLocalizedName($request->mode)['value'],
                'requester' => $request->requester?->profile?->name ?? $request->requester?->name,
                'city' => $request->scopeCity?->name,
                'position' => $request->targetPosition?->title,
                'created_at' => $request->created_at?->format('Y-m-d H:i'),
                'submitted_at' => $request->submitted_at?->format('Y-m-d H:i'),
            ] as $label => $value)
                <div><dt class="text-sm text-secondary-medium">{{ __('recruitment.'.$label) }}</dt><dd class="mt-1 break-words font-medium" dir="auto">{{ $value ?? '—' }}</dd></div>
            @endforeach
        </dl>
    </section>
    @if ($request->status === \App\Enums\RecruitmentRequestStatus::Draft)
        <section class="rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.draft_content') }}</h2>
            <dl class="mt-4 grid gap-6 sm:grid-cols-2">
                @php
                    // Stored as slugs; shown by their label, in either language.
                    $draftSlugs = ['recruitment_reason' => 'reason_', 'priority' => 'priority_', 'volunteer_type' => 'volunteer_type_'];
                @endphp
                @foreach ($draftSlugs as $field => $prefix)
                    @if (! empty($request->draft_payload_json[$field]))
                        <div><dt class="text-sm text-secondary-medium">{{ __('recruitment.'.$field) }}</dt><dd class="mt-1 break-words" dir="auto">{{ __('recruitment.'.$prefix.$request->draft_payload_json[$field]) }}</dd></div>
                    @endif
                @endforeach
                @foreach ($draftContext as $label => $value)
                    <div><dt class="text-sm text-secondary-medium">{{ __('recruitment.'.$label) }}</dt><dd class="mt-1 break-words" dir="auto">{{ $value }}</dd></div>
                @endforeach
                @foreach (['title_en', 'title_ar', 'max_emp', 'volunteers_required', 'qualifications', 'responsibilities', 'other_reason', 'reason', 'weekly_hours', 'needed_by_date', 'volunteer_period_start_date', 'volunteer_period_end_date', 'additional_count', 'reduce_by', 'effective_date', 'salary_amount', 'detailed_explanation'] as $field)
                    @if (isset($request->draft_payload_json[$field]) && is_scalar($request->draft_payload_json[$field]))
                        <div><dt class="text-sm text-secondary-medium">{{ __('recruitment.'.$field) }}</dt><dd class="mt-1 whitespace-pre-wrap break-words" dir="auto">{{ $request->draft_payload_json[$field] }}@if ($field === 'salary_amount') {{ \App\Enums\Currency::getLocalizedName((int) ($request->draft_payload_json['currency'] ?? 0))[app()->getLocale()] }} / {{ \App\Enums\SalaryPer::getLocalizedName((int) ($request->draft_payload_json['salary_per'] ?? 0))['value'] }}@endif</dd></div>
                    @endif
                @endforeach
            </dl>
        </section>
    @endif
    @if ($version?->positionDetail)
        <section class="rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.version', ['number' => $version->version_no]) }}</h2>
            <dl class="mt-4 grid gap-6 sm:grid-cols-2">
                @php
                    // The three fixed lists are stored as slugs, so they are
                    // translated here rather than shown raw.
                    $slugLabels = ['recruitment_reason' => 'reason_', 'priority' => 'priority_', 'volunteer_type' => 'volunteer_type_'];
                @endphp
                @foreach (['role_title_en', 'role_title_ar', 'current_headcount', 'requested_headcount', 'salary_amount', 'recruitment_reason', 'other_reason', 'priority', 'volunteer_type', 'weekly_hours', 'expected_start_date', 'volunteer_period_start_date', 'volunteer_period_end_date', 'reason', 'main_duties', 'required_qualifications', 'detailed_explanation'] as $field)
                    @if ($version->positionDetail->{$field} !== null && $version->positionDetail->{$field} !== '')
                        <div>
                            <dt class="text-sm text-secondary-medium">{{ __('recruitment.'.$field) }}</dt>
                            <dd class="mt-1 whitespace-pre-wrap break-words" dir="auto">@isset($slugLabels[$field]){{ __('recruitment.'.$slugLabels[$field].$version->positionDetail->{$field}) }}@else{{ $version->positionDetail->{$field} }}@endisset@if ($field === 'salary_amount') {{ \App\Enums\Currency::getLocalizedName((int) $version->positionDetail->currency)[app()->getLocale()] }} / {{ \App\Enums\SalaryPer::getLocalizedName((int) $version->positionDetail->salary_per)['value'] }}@endif</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
            @php($versionSnapshot = (array) ($version->snapshot_json ?? []))
            @if (isset($versionSnapshot['volunteers_required']) || isset($versionSnapshot['additional_count']))
                <dl class="mt-6 grid gap-6 sm:grid-cols-2">
                    @foreach (['volunteers_required', 'additional_count'] as $field)
                        @if (isset($versionSnapshot[$field]))
                            <div><dt class="text-sm text-secondary-medium">{{ __('recruitment.'.$field) }}</dt><dd class="mt-1 break-words" dir="auto">{{ $versionSnapshot[$field] }}</dd></div>
                        @endif
                    @endforeach
                </dl>
            @endif
        </section>
    @endif
    <div class="grid items-start gap-6 lg:grid-cols-2">
        <section class="rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.versions') }}</h2>
            <ul class="mt-4 divide-y divide-secondary-soft">
                @forelse ($request->versions as $savedVersion)
                    <li class="flex flex-wrap justify-between gap-2 py-3" wire:key="version-{{ $savedVersion->id }}">
                        <button type="button" class="text-primary hover:underline" wire:click="$set('versionId', {{ $savedVersion->id }})" aria-pressed="{{ $version?->id === $savedVersion->id ? 'true' : 'false' }}">{{ __('recruitment.version', ['number' => $savedVersion->version_no]) }}</button>
                        <time class="text-sm text-secondary-medium" datetime="{{ $savedVersion->submitted_at?->toIso8601String() }}">{{ $savedVersion->submitted_at?->format('Y-m-d H:i') }}</time>
                    </li>
                @empty
                    <li class="py-3 text-secondary-medium">{{ __('recruitment.no_versions') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
    <section class="rounded-surface border border-secondary-soft bg-white p-6">
        <h2 class="text-h4 font-semibold">{{ __('recruitment.comments') }}</h2>
        <div class="mt-4 space-y-4">
            @forelse ($request->comments as $comment)
                <article class="border-s-2 border-secondary-soft ps-4" wire:key="comment-{{ $comment->id }}">
                    <p class="text-sm text-secondary-medium">{{ $comment->user?->profile?->name ?? $comment->user?->name }} · {{ $comment->created_at?->format('Y-m-d H:i') }}</p>
                    <p class="mt-2 whitespace-pre-wrap break-words" dir="auto">{{ $comment->body }}</p>
                </article>
            @empty
                <p class="text-secondary-medium">{{ __('recruitment.no_comments') }}</p>
            @endforelse
        </div>
    </section>
    @if ($request->mode === \App\Enums\RecruitmentRequestMode::DirectHr)
        <section class="rounded-surface border border-secondary-soft bg-white p-6">
            <h2 class="text-h4 font-semibold">{{ __('recruitment.external_confirmations') }}</h2>
            <p class="mt-2 text-secondary-medium">{{ __('recruitment.external_description') }}</p>
            <p class="mt-4 whitespace-pre-wrap break-words" dir="auto">{{ $request->direct_open_reason }}</p>
            <ul class="mt-4 space-y-4">
                @forelse ($request->externalConfirmations as $confirmation)
                    <li class="border-s-2 border-secondary-soft ps-4" wire:key="confirmation-{{ $confirmation->id }}">
                        <p>{{ \App\Enums\ExternalConfirmationDecision::getLocalizedName($confirmation->decision)['value'] }}</p>
                        @if ($confirmation->decision === \App\Enums\ExternalConfirmationDecision::NotApplicable)
                            <p class="whitespace-pre-wrap break-words" dir="auto">{{ $confirmation->not_applicable_reason }}</p>
                        @else
                            <p>{{ $confirmation->confirmer_name }} · {{ \App\Enums\ExternalConfirmationMethod::getLocalizedName((int) $confirmation->method)['value'] }} · {{ $confirmation->confirmed_at?->format('Y-m-d') }}</p>
                        @endif
                        <p class="text-sm text-secondary-medium">{{ __('recruitment.recorded_by') }}: {{ $confirmation->recordedBy?->profile?->name ?? $confirmation->recordedBy?->name }}</p>
                    </li>
                @empty
                    <li class="text-secondary-medium">{{ __('recruitment.no_confirmations') }}</li>
                @endforelse
            </ul>
        </section>
    @endif
    <x-recruitment.confirmation-modal scope="request-details" />
</div>
