<x-slot:title>{{ __('recruitment.details') }} · {{ $request->request_number }}</x-slot:title>

@php
    use App\Enums\RecruitmentRequestStatus as Status;

    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $card = 'rounded-surface border border-neutral-200 bg-white shadow-e1';
    $eyebrow = 'text-caption font-semibold uppercase tracking-wider rtl:normal-case rtl:tracking-normal text-secondary-medium';
    $quietBtn = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control border border-neutral-200 bg-white px-4 text-small font-semibold text-secondary-dark shadow-e1 transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-10 '.$focus;
    $dangerBtn = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control border border-primary bg-white px-4 text-small font-semibold text-primary shadow-e1 transition duration-150 hover:bg-selection motion-reduce:transition-none sm:min-h-10 '.$focus;
    $redBtn = 'inline-flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-control bg-primary px-4 text-small font-semibold text-white shadow-e1 transition duration-150 hover:opacity-90 motion-reduce:transition-none sm:min-h-10 '.$focus;
    $field = 'mt-1 w-full rounded-control border-neutral-300 bg-white shadow-e1 focus:border-primary focus:ring-primary';

    $status = (int) $request->status;
    $statusDot = match ($status) {
        Status::Draft, Status::ChangesRequested => 'border border-neutral-500 bg-white',
        Status::Submitted, Status::UnderReview, Status::Resubmitted => 'bg-neutral-500',
        Status::Approved, Status::Applied => 'bg-neutral-900',
        Status::FailedApplication => 'bg-primary',
        default => 'bg-neutral-300',
    };
    $requesterName = $request->requester?->profile?->name ?? $request->requester?->name;
    $date = fn ($value) => $value ? '<time datetime="'.e($value->toIso8601String()).'" class="tabular-nums">'.e($value->translatedFormat('j M Y, H:i')).'</time>' : '—';
@endphp

<div class="mx-auto max-w-[1440px] space-y-6">
    {{-- Header: which request, for what, where it stands, and what you can do with it. --}}
    <header class="space-y-4">
        <livewire:layout.breadcrumbs :title="__('recruitment.details')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment.navigation_requests'), 'route' => 'recruitment.requests.index'], ['label' => $request->request_number]]" />

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-h2 font-semibold text-neutral-900 tabular-nums"><bdi>{{ $request->request_number }}</bdi></h1>
                    <span class="inline-flex items-center gap-1.5 rounded-control bg-neutral-100 px-2.5 py-1 text-small font-semibold text-secondary-dark">
                        <span aria-hidden="true" class="size-2 rounded-full {{ $statusDot }}"></span>
                        {{ Status::getLocalizedName($status)['value'] }}
                    </span>
                </div>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-body text-secondary-medium">
                    <span class="font-medium text-secondary-dark">{{ $request->targetPosition?->title ?? '—' }}</span>
                    @if ($request->scopeCity?->name)
                        <span aria-hidden="true">·</span><span>{{ $request->scopeCity->name }}</span>
                    @endif
                    <span aria-hidden="true">·</span>
                    <span>{{ \App\Enums\RecruitmentRequestType::getLocalizedName($request->request_type)['value'] }}</span>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @can('cancel', $request)
                    <button type="button" class="{{ $dangerBtn }}" x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'request-details', action: 'cancelRequest', message: @js(__('recruitment.confirm_cancel')), confirmLabel: @js(__('recruitment.cancel_request')), tone: 'danger' })">{{ __('recruitment.cancel_request') }}</button>
                @endcan
                @can('update', $request)
                    <a class="{{ $quietBtn }}" href="{{ route('recruitment.requests.edit', ['requestId' => $request->id]) }}">
                        <flux:icon.pencil-square variant="micro" class="size-4" aria-hidden="true" />{{ __('recruitment.edit') }}
                    </a>
                @endcan
                @can('submit', $request)
                    {{-- Disabled while in flight; the service is idempotent regardless. --}}
                    <x-primary-button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="submit">{{ __('recruitment.submit') }}</x-primary-button>
                @endcan
                @if ($canCreateCampaign)
                    <a href="{{ route('recruitment.campaigns.create', ['vacancyId' => $request->vacancy->id]) }}" wire:navigate class="{{ $redBtn }}">
                        <flux:icon.plus variant="micro" class="size-4" aria-hidden="true" />{{ __('recruitment.campaign.create') }}
                    </a>
                @endif
            </div>
        </div>
    </header>

    @if ($pendingStep)
        {{-- Shown only to the approver this step waits on; the service asks again, so this is convenience, not a guard. --}}
        <section class="{{ $card }} overflow-hidden" aria-labelledby="decision-title">
            <div class="flex flex-wrap items-start justify-between gap-4 p-5 sm:p-6">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary text-white" aria-hidden="true">
                        <flux:icon.hand-raised variant="mini" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 id="decision-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.your_decision') }}</h2>
                        <p class="mt-0.5 text-small text-secondary-medium">{{ \App\Enums\WorkflowStepKey::getLocalizedName((int) $pendingStep->workflowStep?->step_key)['value'] }}</p>
                    </div>
                </div>

                @if (! $askingForChanges && ! $rejecting)
                    {{-- Approve leads; changes is neutral; reject is set apart in red outline. --}}
                    <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                        <button type="button" class="{{ $dangerBtn }}" wire:click="askToReject">{{ __('recruitment.reject_action') }}</button>
                        <button type="button" class="{{ $quietBtn }}" wire:click="askForChanges">{{ __('recruitment.request_changes') }}</button>
                        <button type="button" class="{{ $redBtn }}" wire:click="decide('approve')" wire:loading.attr="disabled" wire:target="decide">
                            <flux:icon.check variant="micro" class="size-4" aria-hidden="true" />{{ __('recruitment.approve') }}
                        </button>
                    </div>
                @endif
            </div>

            @if (! $askingForChanges && ! $rejecting)
                @if ($errors->hasAny(['step', 'version', 'request', 'comment']))
                    <p class="border-t border-neutral-100 px-6 py-3 text-small text-primary" role="alert">@error('step'){{ $message }}@enderror @error('version'){{ $message }}@enderror @error('request'){{ $message }}@enderror @error('comment'){{ $message }}@enderror</p>
                @endif
            @elseif ($rejecting)
                <div class="border-t border-neutral-100 bg-neutral-50 p-5 sm:p-6">
                    <x-forms.input-label for="rejectionReason" required>{{ __('recruitment.rejection_reason') }}</x-forms.input-label>
                    <textarea id="rejectionReason" rows="3" wire:model="decisionComment" class="{{ $field }}" dir="auto"></textarea>
                    <p class="mt-1 text-small text-primary">@error('comment'){{ $message }}@enderror @error('step'){{ $message }}@enderror @error('version'){{ $message }}@enderror @error('request'){{ $message }}@enderror</p>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" class="{{ $redBtn }}" wire:loading.attr="disabled" wire:target="decide"
                            x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'request-details', action: 'decide', args: ['reject'], message: @js(__('recruitment.confirm_reject')), confirmLabel: @js(__('recruitment.reject_action')), tone: 'danger' })">
                            {{ __('recruitment.reject_action') }}
                        </button>
                        <button type="button" class="{{ $quietBtn }}" wire:click="cancelChangeRequest">{{ __('recruitment.back_to_decisions') }}</button>
                    </div>
                </div>
            @else
                <div class="border-t border-neutral-100 bg-neutral-50 p-5 sm:p-6">
                    <fieldset>
                        <legend class="text-small font-semibold text-neutral-900">{{ __('recruitment.fields_to_unlock') }}</legend>
                        <p class="text-small text-secondary-medium">{{ __('recruitment.fields_to_unlock_hint') }}</p>
                        {{-- Fields as toggle chips: easier to scan and tap than a column of checkboxes. --}}
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach ($selectableFields as $unlockable)
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control border border-neutral-200 bg-white px-3 text-small text-secondary-dark shadow-e1 transition duration-150 hover:border-neutral-300 has-checked:border-neutral-900 has-checked:bg-neutral-900 has-checked:text-white has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary motion-reduce:transition-none sm:min-h-9" wire:key="unlock-{{ $unlockable }}">
                                    <input type="checkbox" value="{{ $unlockable }}" wire:model="requestedFields" class="sr-only">
                                    {{ __('recruitment.fields.'.$unlockable) }}
                                </label>
                            @endforeach
                        </div>
                        <p class="mt-1 text-small text-primary">@error('fields'){{ $message }}@enderror</p>
                    </fieldset>

                    <div class="mt-4">
                        <x-forms.input-label for="decisionComment" required>{{ __('recruitment.decision_comment') }}</x-forms.input-label>
                        <textarea id="decisionComment" rows="3" wire:model="decisionComment" class="{{ $field }}" dir="auto"></textarea>
                        <p class="mt-1 text-small text-primary">@error('comment'){{ $message }}@enderror @error('step'){{ $message }}@enderror @error('version'){{ $message }}@enderror @error('request'){{ $message }}@enderror</p>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-primary-button type="button" wire:click="decide('request_changes')" wire:loading.attr="disabled" wire:target="decide">{{ __('recruitment.send_change_request') }}</x-primary-button>
                        <button type="button" class="{{ $quietBtn }}" wire:click="cancelChangeRequest">{{ __('recruitment.back_to_decisions') }}</button>
                    </div>
                </div>
            @endif
        </section>
    @endif

    {{-- Notices that change what the reader should do, right under the header. --}}
    @if ($unlockedFields && $status === Status::ChangesRequested)
        <section class="{{ $card }} flex flex-wrap items-start gap-3 p-5" aria-labelledby="unlocked-title">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-secondary-dark" aria-hidden="true"><flux:icon.lock-open variant="mini" class="size-5" /></span>
            <div class="min-w-0 flex-1">
                <h2 id="unlocked-title" class="font-semibold text-neutral-900">{{ __('recruitment.fields_you_may_edit') }}</h2>
                <p class="mt-0.5 text-small text-secondary-medium">{{ __('recruitment.fields_you_may_edit_hint') }}</p>
                <ul class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($unlockedFields as $unlocked)
                        <li class="rounded-control bg-neutral-900 px-2 py-1 text-small font-medium text-white">{{ __('recruitment.fields.'.$unlocked) }}</li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @if ($awaitingExecution)
        {{-- Decided but not yet carried out; until the date the post is still open. --}}
        <section class="{{ $card }} flex flex-wrap items-center justify-between gap-4 bg-neutral-50 p-5" aria-labelledby="awaiting-title">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-white text-secondary-dark ring-1 ring-neutral-200" aria-hidden="true"><flux:icon.clock variant="mini" class="size-5" /></span>
                <div>
                    <h2 id="awaiting-title" class="font-semibold text-neutral-900">{{ __('recruitment.awaiting_execution') }}</h2>
                    @if ($effectiveDate)
                        <p class="mt-0.5 text-small text-secondary-dark">{{ __('recruitment.effective_on', ['date' => $effectiveDate->translatedFormat('j M Y')]) }}</p>
                    @endif
                </div>
            </div>
            @if ($mayExecuteNow)
                <button type="button" class="{{ $quietBtn }}"
                    x-on:click="$dispatch('open-recruitment-confirmation', { scope: 'request-details', action: 'executeNow', message: @js(__('recruitment.confirm_execute_now')), confirmLabel: @js(__('recruitment.execute_now')) })">{{ __('recruitment.execute_now') }}</button>
            @endif
        </section>
    @endif

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-6">
            @include('livewire.recruitment.requests.partials.approval-track')

            {{-- The request's content, grouped the way a reader asks about it: what role, on what terms, why, and what the job is. --}}
            @if ($version?->positionDetail)
                @php
                    $detail = $version->positionDetail;
                    $slugLabels = ['recruitment_reason' => 'reason_', 'priority' => 'priority_', 'volunteer_type' => 'volunteer_type_'];
                    $snapshot = (array) ($version->snapshot_json ?? []);
                    $value = function (string $f) use ($detail, $slugLabels) {
                        $v = $detail->{$f};
                        if ($v === null || $v === '') {
                            return null;
                        }
                        if (isset($slugLabels[$f])) {
                            return __('recruitment.'.$slugLabels[$f].$v);
                        }
                        if ($f === 'salary_amount') {
                            return $v.' '.\App\Enums\Currency::getLocalizedName((int) $detail->currency)[app()->getLocale()].' / '.\App\Enums\SalaryPer::getLocalizedName((int) $detail->salary_per)['value'];
                        }
                        if ($v instanceof \DateTimeInterface) {
                            return $v->translatedFormat('j M Y');
                        }
                        return $v;
                    };
                    $groups = [
                        'role' => ['role_title_en', 'role_title_ar'],
                        'terms' => ['priority', 'salary_amount', 'weekly_hours', 'expected_start_date', 'volunteer_type', 'volunteer_period_start_date', 'volunteer_period_end_date'],
                        'reason' => ['recruitment_reason', 'other_reason', 'reason', 'detailed_explanation'],
                        'description' => ['main_duties', 'required_qualifications'],
                    ];
                    $cur = $detail->current_headcount;
                    $req = $detail->requested_headcount;
                @endphp
                <section class="{{ $card }}" aria-labelledby="content-title">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-5 py-4 sm:px-6">
                        <h2 id="content-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.version', ['number' => $version->version_no]) }}</h2>
                        {{-- Version switcher in place, instead of a separate card further down. --}}
                        @if ($request->versions->count() > 1)
                            <div role="group" aria-label="{{ __('recruitment.versions') }}" class="inline-flex flex-wrap gap-1 rounded-control border border-neutral-200 p-1">
                                @foreach ($request->versions as $savedVersion)
                                    @php $on = $version?->id === $savedVersion->id; @endphp
                                    <button type="button" wire:key="version-{{ $savedVersion->id }}" wire:click="$set('versionId', {{ $savedVersion->id }})" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                        title="{{ $savedVersion->submitted_at?->translatedFormat('j M Y, H:i') }}"
                                        @class(['inline-flex min-h-11 cursor-pointer items-center rounded-inset px-3 text-small transition duration-150 motion-reduce:transition-none sm:min-h-8', $focus, 'bg-selection font-semibold text-secondary-dark' => $on, 'text-secondary-medium hover:text-secondary-dark' => ! $on])>
                                        {{ __('recruitment.version_no', ['no' => $savedVersion->version_no]) }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="divide-y divide-neutral-100">
                        @if ($cur !== null || $req !== null || isset($snapshot['volunteers_required']) || isset($snapshot['additional_count']))
                            {{-- Headcount as numbers people compare, not two separate fields. --}}
                            <div class="grid gap-4 px-5 py-5 sm:grid-cols-3 sm:px-6">
                                @if ($cur !== null || $req !== null)
                                    <div class="sm:col-span-2">
                                        <p class="{{ $eyebrow }}">{{ __('recruitment.requested_headcount') }}</p>
                                        <p class="mt-2 flex items-baseline gap-3">
                                            <span class="text-h3 font-semibold tabular-nums text-secondary-medium">{{ $cur ?? '—' }}</span>
                                            <flux:icon.arrow-right variant="mini" class="size-5 self-center text-secondary-medium rtl:rotate-180" aria-hidden="true" />
                                            <span class="text-h2 font-semibold tabular-nums text-neutral-900">{{ $req ?? '—' }}</span>
                                            @if (is_numeric($cur) && is_numeric($req) && (int) $req !== (int) $cur)
                                                <span class="rounded-control bg-neutral-100 px-2 py-0.5 text-small font-semibold tabular-nums text-secondary-dark">{{ (int) $req > (int) $cur ? '+' : '' }}{{ (int) $req - (int) $cur }}</span>
                                            @endif
                                        </p>
                                        <p class="mt-1 text-caption text-secondary-medium">{{ __('recruitment.current_headcount') }} <span aria-hidden="true">→</span> {{ __('recruitment.requested_headcount') }}</p>
                                    </div>
                                @endif
                                @foreach (['volunteers_required', 'additional_count'] as $snapField)
                                    @if (isset($snapshot[$snapField]))
                                        <div>
                                            <p class="{{ $eyebrow }}">{{ __('recruitment.'.$snapField) }}</p>
                                            <p class="mt-2 text-h3 font-semibold tabular-nums text-neutral-900">{{ $snapshot[$snapField] }}</p>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @foreach ($groups as $group => $fields)
                            @php $present = collect($fields)->filter(fn ($f) => $value($f) !== null); @endphp
                            @if ($present->isNotEmpty())
                                <div class="px-5 py-5 sm:px-6">
                                    <h3 class="{{ $eyebrow }}">{{ __('recruitment.sections.'.$group) }}</h3>
                                    <dl @class(['mt-3 grid gap-x-8 gap-y-4', 'sm:grid-cols-2' => $group !== 'description'])>
                                        @foreach ($present as $f)
                                            @php $long = in_array($f, ['main_duties', 'required_qualifications', 'detailed_explanation', 'reason', 'other_reason'], true); @endphp
                                            <div @class(['min-w-0', 'sm:col-span-2' => $long])>
                                                <dt class="text-caption text-secondary-medium">{{ __('recruitment.'.($f === 'expected_start_date' ? 'needed_by_date' : $f)) }}</dt>
                                                <dd @class(['mt-0.5 whitespace-pre-wrap break-words text-neutral-900', 'max-w-prose text-body leading-relaxed' => $long, 'font-medium' => ! $long]) dir="auto">{{ $value($f) }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif

            @if (! $version?->positionDetail && $request->versions->count() > 1)
                <section class="{{ $card }} p-5 sm:p-6" aria-label="{{ __('recruitment.versions') }}">
                    <h2 class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.versions') }}</h2>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ($request->versions as $savedVersion)
                            <button type="button" wire:key="version-{{ $savedVersion->id }}"
                                wire:click="$set('versionId', {{ $savedVersion->id }})"
                                aria-pressed="{{ $version?->id === $savedVersion->id ? 'true' : 'false' }}"
                                class="{{ $quietBtn }}">
                                {{ __('recruitment.version_no', ['no' => $savedVersion->version_no]) }}
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($status === Status::Draft)
                <section class="{{ $card }} p-5 sm:p-6" aria-labelledby="draft-title">
                    <h2 id="draft-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.draft_content') }}</h2>
                    <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        @php $draftSlugs = ['recruitment_reason' => 'reason_', 'priority' => 'priority_', 'volunteer_type' => 'volunteer_type_']; @endphp
                        @foreach ($draftSlugs as $draftField => $prefix)
                            @if (! empty($request->draft_payload_json[$draftField]))
                                <div><dt class="text-caption text-secondary-medium">{{ __('recruitment.'.$draftField) }}</dt><dd class="mt-0.5 break-words font-medium text-neutral-900" dir="auto">{{ __('recruitment.'.$prefix.$request->draft_payload_json[$draftField]) }}</dd></div>
                            @endif
                        @endforeach
                        @foreach ($draftContext as $label => $draftValue)
                            <div><dt class="text-caption text-secondary-medium">{{ __('recruitment.'.$label) }}</dt><dd class="mt-0.5 break-words font-medium text-neutral-900" dir="auto">{{ $draftValue }}</dd></div>
                        @endforeach
                        @foreach (['title_en', 'title_ar', 'max_emp', 'volunteers_required', 'qualifications', 'responsibilities', 'other_reason', 'reason', 'weekly_hours', 'needed_by_date', 'volunteer_period_start_date', 'volunteer_period_end_date', 'additional_count', 'reduce_by', 'effective_date', 'salary_amount', 'detailed_explanation'] as $draftField)
                            @if (isset($request->draft_payload_json[$draftField]) && is_scalar($request->draft_payload_json[$draftField]))
                                @php $long = in_array($draftField, ['qualifications', 'responsibilities', 'other_reason', 'reason', 'detailed_explanation'], true); @endphp
                                <div @class(['min-w-0', 'sm:col-span-2' => $long])><dt class="text-caption text-secondary-medium">{{ __('recruitment.'.$draftField) }}</dt><dd @class(['mt-0.5 whitespace-pre-wrap break-words text-neutral-900', 'max-w-prose leading-relaxed' => $long, 'font-medium' => ! $long]) dir="auto">{{ $request->draft_payload_json[$draftField] }}@if ($draftField === 'salary_amount') {{ \App\Enums\Currency::getLocalizedName((int) ($request->draft_payload_json['currency'] ?? 0))[app()->getLocale()] }} / {{ \App\Enums\SalaryPer::getLocalizedName((int) ($request->draft_payload_json['salary_per'] ?? 0))['value'] }}@endif</dd></div>
                            @endif
                        @endforeach
                    </dl>
                </section>
            @endif

            <section class="{{ $card }} p-5 sm:p-6" aria-labelledby="comments-title">
                <h2 id="comments-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.comments') }} <span class="tabular-nums text-secondary-medium">({{ $request->comments->count() }})</span></h2>
                <div class="mt-4 space-y-4">
                    @forelse ($request->comments as $comment)
                        @php
                            $author = $comment->user?->profile?->name ?? $comment->user?->name ?? '—';
                            $initials = collect(preg_split('/\s+/u', trim($author), -1, PREG_SPLIT_NO_EMPTY) ?: [])->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?';
                        @endphp
                        <article class="flex gap-3" wire:key="comment-{{ $comment->id }}">
                            <span aria-hidden="true" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-neutral-100 text-caption font-semibold text-secondary-dark">{{ $initials }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="text-small"><span class="font-semibold text-neutral-900">{{ $author }}</span> <span class="text-secondary-medium">· {!! $date($comment->created_at) !!}</span></p>
                                <p class="mt-1 whitespace-pre-wrap break-words rounded-control bg-neutral-50 px-3 py-2 text-secondary-dark" dir="auto">{{ $comment->body }}</p>
                            </div>
                        </article>
                    @empty
                        <p class="rounded-control border border-dashed border-neutral-300 px-4 py-5 text-center text-small text-secondary-medium">{{ __('recruitment.no_comments') }}</p>
                    @endforelse
                </div>
            </section>

            @if ($request->mode === \App\Enums\RecruitmentRequestMode::DirectHr)
                <section class="{{ $card }} p-5 sm:p-6" aria-labelledby="external-title">
                    <h2 id="external-title" class="text-h4 font-semibold text-neutral-900">{{ __('recruitment.external_confirmations') }}</h2>
                    <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.external_description') }}</p>
                    @if (filled($request->direct_open_reason))
                        <p class="mt-4 max-w-prose whitespace-pre-wrap break-words rounded-control bg-neutral-50 px-3 py-2 text-secondary-dark" dir="auto">{{ $request->direct_open_reason }}</p>
                    @endif
                    <ul class="mt-4 divide-y divide-neutral-100">
                        @forelse ($request->externalConfirmations as $confirmation)
                            <li class="py-3" wire:key="confirmation-{{ $confirmation->id }}">
                                <p class="font-semibold text-neutral-900">{{ \App\Enums\ExternalConfirmationDecision::getLocalizedName($confirmation->decision)['value'] }}</p>
                                @if ($confirmation->decision === \App\Enums\ExternalConfirmationDecision::NotApplicable)
                                    <p class="mt-0.5 whitespace-pre-wrap break-words text-small text-secondary-dark" dir="auto">{{ $confirmation->not_applicable_reason }}</p>
                                @else
                                    <p class="mt-0.5 text-small text-secondary-dark">{{ $confirmation->confirmer_name }} · {{ \App\Enums\ExternalConfirmationMethod::getLocalizedName((int) $confirmation->method)['value'] }} · {{ $confirmation->confirmed_at?->translatedFormat('j M Y') }}</p>
                                @endif
                                <p class="mt-0.5 text-caption text-secondary-medium">{{ __('recruitment.recorded_by') }}: {{ $confirmation->recordedBy?->profile?->name ?? $confirmation->recordedBy?->name }}</p>
                            </li>
                        @empty
                            <li class="py-3 text-small text-secondary-medium">{{ __('recruitment.no_confirmations') }}</li>
                        @endforelse
                    </ul>
                </section>
            @endif
        </div>

        {{-- Side rail: the facts people look up, kept beside the history while scrolling. --}}
        <aside class="space-y-4 xl:sticky xl:top-24">
            <section class="{{ $card }} p-5" aria-labelledby="facts-title">
                <h2 id="facts-title" class="{{ $eyebrow }}">{{ __('recruitment.details') }}</h2>
                <dl class="mt-3 divide-y divide-neutral-100 text-small">
                    @foreach ([
                        'type' => e(\App\Enums\RecruitmentRequestType::getLocalizedName($request->request_type)['value']),
                        'mode' => e(\App\Enums\RecruitmentRequestMode::getLocalizedName($request->mode)['value']),
                        'city' => e($request->scopeCity?->name ?? '—'),
                        'position' => e($request->targetPosition?->title ?? '—'),
                        'created_at' => $date($request->created_at),
                        'submitted_at' => $date($request->submitted_at),
                    ] as $label => $factValue)
                        <div class="flex items-baseline justify-between gap-4 py-2.5">
                            <dt class="shrink-0 text-secondary-medium">{{ __('recruitment.'.$label) }}</dt>
                            <dd class="min-w-0 text-end font-medium break-words text-neutral-900" dir="auto">{!! $factValue !!}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-3 flex items-center gap-3 border-t border-neutral-100 pt-4">
                    @php $reqInitials = collect(preg_split('/\s+/u', trim((string) $requesterName), -1, PREG_SPLIT_NO_EMPTY) ?: [])->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?'; @endphp
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-full bg-neutral-900 text-caption font-semibold text-white">{{ $reqInitials }}</span>
                    <div class="min-w-0">
                        <p class="text-caption text-secondary-medium">{{ __('recruitment.requester') }}</p>
                        <p class="truncate font-semibold text-neutral-900">{{ $requesterName ?? '—' }}</p>
                    </div>
                </div>
            </section>

            @if ($previousRejections->isNotEmpty())
                {{-- D61: context for the reader, never a reason to hide the request. --}}
                <section role="note" class="{{ $card }} p-5" aria-labelledby="rejections-title">
                    <h2 id="rejections-title" class="flex items-center gap-2 font-semibold text-neutral-900">
                        <flux:icon.exclamation-triangle variant="mini" class="size-4 text-secondary-medium" aria-hidden="true" />
                        {{ __('recruitment.previous_rejections') }}
                        <span class="tabular-nums text-secondary-medium">({{ $previousRejections->count() }})</span>
                    </h2>
                    <p class="mt-1 text-small text-secondary-medium">{{ __('recruitment.previous_rejections_help') }}</p>
                    <ul class="mt-3 space-y-2">
                        @foreach ($previousRejections as $rejected)
                            @php $decision = $rejected->events->first(); $when = $decision?->created_at ?? $rejected->updated_at; @endphp
                            <li class="rounded-control bg-neutral-50 p-3 text-small" wire:key="rejection-{{ $rejected->id }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-semibold text-neutral-900 tabular-nums" dir="auto">{{ $rejected->request_number }}</span>
                                    <time class="text-caption text-secondary-medium tabular-nums" datetime="{{ $when?->toIso8601String() }}">{{ $when?->translatedFormat('j M Y') }}</time>
                                </div>
                                <p class="mt-0.5 text-caption text-secondary-medium">
                                    {{ $rejected->requester?->profile?->name ?? $rejected->requester?->name ?? '-' }}
                                    @if ($decision?->actor) &middot; {{ __('recruitment.rejected_by') }}: {{ $decision->actor->profile?->name ?? $decision->actor->name }} @endif
                                </p>
                                @if (filled($decision?->comment))
                                    <p class="mt-1.5 whitespace-pre-wrap break-words text-secondary-dark" dir="auto">{{ $decision->comment }}</p>
                                @else
                                    <p class="mt-1.5 text-secondary-medium">{{ __('recruitment.no_reason_recorded') }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($request->versions->count() <= 1)
                <p class="px-1 text-caption text-secondary-medium">
                    @if ($request->versions->isEmpty()){{ __('recruitment.no_versions') }}@else{{ __('recruitment.version_no', ['no' => $request->versions->first()->version_no]) }} · {!! $date($request->versions->first()->submitted_at) !!}@endif
                </p>
            @endif
        </aside>
    </div>

    <x-recruitment.confirmation-modal scope="request-details" />
</div>
