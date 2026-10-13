@php
    use App\Enums\RecruitmentJobOfferStatus as Status;
    use App\Services\RecruitmentJobOfferService;
    use App\Support\Recruitment\OfferTerms;
    use Illuminate\Support\Facades\Lang;

    $current = $offer->currentVersion;
    $application = $offer->application;
    $personName = fn ($user) => $user?->profile?->name ?? $user?->email ?? '—';
@endphp

<x-slot:title>{{ __('recruitment-applicant.offers.document_title') }}</x-slot:title>

<div class="space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicant.offers.document_title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-applicant.offers.title'), 'route' => 'recruitment.offers.index'], ['label' => __('recruitment-applicant.offers.document_title')]]" />

    @error('offer')
        <p role="alert" class="rounded-control border border-primary bg-primary/5 p-3 text-sm text-primary">{{ $message }}</p>
    @enderror

    {{-- The offer document: the current version's terms --}}
    <section class="rounded-surface border border-secondary-soft bg-white p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wide text-secondary-medium">{{ __('recruitment-applicant.offers.document_title') }} &middot; v{{ $current?->version_no }}</p>
                <h1 class="mt-1 text-h3 font-semibold">{{ $application?->applicant?->full_name }}</h1>
                <p class="mt-1 text-sm text-secondary-medium">
                    {{ $offer->position?->title }}
                    @if ($canSeeApplicant)
                        <span>&middot; <a href="{{ route('recruitment.applicants.show', ['applicantId' => $application->applicant_id]) }}" wire:navigate class="text-primary hover:underline">{{ __('recruitment-applicant.interviews.open_applicant') }}</a></span>
                    @endif
                </p>
            </div>
            <span class="rounded-control px-3 py-1 text-sm font-semibold {{ Status::classes($offer->status) }}">{{ __('recruitment-applicant.offers.statuses.'.$offer->status) }}</span>
        </div>

        @if ($current)
            @include('livewire.recruitment.offers.partials.terms', ['version' => $current, 'canSeeSalary' => auth()->user()->can('viewSalary', [$offer, $current])])
        @endif

        <dl class="mt-6 grid grid-cols-1 gap-3 border-t border-secondary-soft pt-4 text-sm md:grid-cols-3">
            <div><dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.created_by') }}</dt><dd>{{ $personName($offer->creator) }} &middot; {{ $offer->created_at?->format('Y-m-d') }}</dd></div>
            @if ($offer->sent_at)
                <div><dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.sent_at') }}</dt><dd>{{ $offer->sent_at->format('Y-m-d H:i') }}</dd></div>
            @endif
            @if ($offer->expires_at && in_array($offer->status, [Status::Sent, Status::Expired], true))
                <div><dt class="text-secondary-medium">{{ __('recruitment-applicant.offers.expires_at') }}</dt><dd>{{ $offer->expires_at->format('Y-m-d') }}</dd></div>
            @endif
        </dl>

        {{-- How it ended --}}
        @if (in_array($offer->status, [Status::Accepted, Status::Rejected], true))
            <div class="mt-4 rounded-control bg-secondary-soft/30 p-4 text-sm">
                {{ __('recruitment-applicant.offers.response_on', ['response' => __('recruitment-applicant.offers.statuses.'.$offer->status), 'date' => ($offer->accepted_at ?? $offer->rejected_at)?->format('Y-m-d H:i'), 'by' => $personName($offer->responseRecordedBy)]) }}
                @if ($canManage && $offer->response_note)
                    <p class="mt-1 whitespace-pre-line text-secondary-medium">{{ $offer->response_note }}</p>
                @endif
                @if ($offer->status === Status::Accepted && $conversion)
                    {{-- Task 10: the hire itself --}}
                    <div class="mt-3 space-y-2 border-t border-secondary-soft pt-3">
                        <p class="font-medium {{ $conversion->isConverted() ? 'text-primary' : ($conversion->status === \App\Models\RecruitmentHiringConversion::STATUS_FAILED ? 'text-primary' : 'text-secondary-dark') }}">
                            {{ __('recruitment-applicant.conversions.statuses.'.$conversion->status) }}
                        </p>
                        @if ($conversion->isConverted())
                            <p>{{ __('recruitment-applicant.conversions.converted_by', ['name' => $personName($conversion->convertedBy), 'date' => $conversion->converted_at?->format('Y-m-d H:i')]) }}</p>
                            <div class="flex flex-wrap gap-3">
                                @if ($conversion->employee?->username)
                                    <a href="{{ route('employees.show', $conversion->employee->username) }}" class="font-medium text-primary hover:underline">{{ __('recruitment-applicant.conversions.open_employee') }}: {{ $conversion->employee->name }}</a>
                                @endif
                                @if ($conversion->contract_id)
                                    <a href="{{ route('contracts.show', $conversion->contract_id) }}" class="font-medium text-primary hover:underline">{{ __('recruitment-applicant.conversions.open_contract') }} #{{ $conversion->contract_id }}</a>
                                @endif
                            </div>
                            @if ($campaignsStillOpen !== [])
                                <p class="text-secondary-medium">{{ __('recruitment-applicant.conversions.campaigns_still_open', ['ids' => implode(', ', array_map(fn ($id) => '#'.$id, $campaignsStillOpen))]) }}</p>
                            @endif
                        @else
                            @if ($conversion->status === \App\Models\RecruitmentHiringConversion::STATUS_FAILED && $conversion->failure_reason)
                                <p>{{ __('recruitment-applicant.conversions.last_failure', ['count' => $conversion->attempt_count, 'reason' => __('recruitment-applicant.conversions.errors.'.$conversion->failure_reason)]) }}</p>
                            @endif
                            @if ($canConvert)
                                <a href="{{ route('recruitment.offers.convert', ['offerId' => $offer->id]) }}" wire:navigate class="inline-block rounded-control bg-primary px-3 py-1.5 text-sm font-semibold text-white">{{ __('recruitment-applicant.conversions.open') }}</a>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        @elseif ($offer->status === Status::Cancelled)
            @php $reasonKey = 'recruitment-applicant.offers.cancellation_reasons.'.$offer->cancellation_reason; @endphp
            <div class="mt-4 rounded-control bg-secondary-soft/30 p-4 text-sm">
                {{ __('recruitment-applicant.offers.cancelled_on', ['date' => $offer->cancelled_at?->format('Y-m-d H:i'), 'by' => $offer->cancelledBy ? $personName($offer->cancelledBy) : __('recruitment-applicant.interviews.system')]) }}
                — {{ Lang::has($reasonKey) ? __($reasonKey) : $offer->cancellation_reason }}
                @if ($canManage && $offer->cancellation_note)
                    <p class="mt-1 whitespace-pre-line text-secondary-medium">{{ $offer->cancellation_note }}</p>
                @endif
            </div>
        @elseif ($offer->status === Status::Expired)
            <p class="mt-4 rounded-control bg-secondary-soft/30 p-4 text-sm">{{ __('recruitment-applicant.offers.expired_on', ['date' => $offer->expired_at?->format('Y-m-d H:i')]) }}</p>
        @endif

        {{-- Rule reminder: an offer never touches the vacancy by itself --}}
        @if ($offer->vacancy)
            <p class="mt-4 text-xs text-secondary-medium">
                {{ trans_choice('recruitment-applicant.offers.seats_left', $seatsLeft, ['count' => $seatsLeft]) }}
                &middot; {{ __('recruitment-applicant.offers.vacancy_untouched') }}
            </p>
        @endif

        {{-- HR actions --}}
        @if ($canManage)
            <div class="mt-6 flex flex-wrap gap-2 border-t border-secondary-soft pt-4">
                @if ($offer->isEditable())
                    <a href="{{ route('recruitment.offers.edit', ['offerId' => $offer->id]) }}" wire:navigate class="rounded-control border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary/5">{{ __('recruitment-applicant.offers.actions.edit') }}</a>
                    <button type="button" wire:click="beginSend" class="rounded-control bg-primary px-3 py-1.5 text-sm font-semibold text-white">{{ __('recruitment-applicant.offers.actions.send') }}</button>
                @endif
                @if ($offer->status === Status::Sent)
                    <button type="button" wire:click="beginResponse('{{ Status::Accepted }}')" class="rounded-control border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary/5">{{ __('recruitment-applicant.offers.actions.accepted') }}</button>
                    <button type="button" wire:click="beginResponse('{{ Status::Rejected }}')" class="rounded-control border border-secondary-light px-3 py-1.5 text-sm hover:bg-secondary-soft/30">{{ __('recruitment-applicant.offers.actions.rejected') }}</button>
                @endif
                @if ($canRevise)
                    <a href="{{ route('recruitment.offers.revise', ['offerId' => $offer->id]) }}" wire:navigate class="rounded-control border border-secondary-light px-3 py-1.5 text-sm hover:bg-secondary-soft/30">{{ __('recruitment-applicant.offers.actions.revise') }}</a>
                @endif
                @if (Status::canMove($offer->status, Status::Cancelled))
                    <button type="button" wire:click="beginCancel" class="rounded-control border border-secondary-light px-3 py-1.5 text-sm text-primary hover:bg-primary/5">{{ __('recruitment-applicant.offers.actions.cancel') }}</button>
                @endif
            </div>

            {{-- The three actions open one popup, like the Leaves decision popup (<x-modal.dialog>). --}}
            @php
                $modalTitle = match ($panel) {
                    'send' => __('recruitment-applicant.offers.send_title'),
                    'response' => __('recruitment-applicant.offers.response_title.'.($response ?: Status::Accepted)),
                    'cancel' => __('recruitment-applicant.offers.cancel_title'),
                    default => '',
                };
                [$action, $actionLabel] = match ($panel) {
                    'send' => ['send', __('recruitment-applicant.offers.actions.send')],
                    'response' => ['recordResponse', __('recruitment-applicant.offers.confirm')],
                    default => ['cancelOffer', __('recruitment-applicant.offers.actions.cancel')],
                };
            @endphp
            <x-modal.dialog id="offerActionModal" max-width="xl" :title="$modalTitle" wire:model.defer="panelOpen" wire:ignore.self>
                @if ($panel !== null)
                    <div class="space-y-4">
                        @error('offer')
                            <p role="alert" class="rounded-control border border-primary bg-primary/5 p-3 text-sm text-primary">{{ $message }}</p>
                        @enderror

                        @if ($panel === 'send')
                            <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.offers.send_hint') }}</p>
                            <div class="max-w-xs">
                                <x-forms.input-label for="expiresOn" required :value="__('recruitment-applicant.offers.expires_at')" />
                                <x-forms.input id="expiresOn" type="date" model="expiresOn" required />
                                <x-forms.input-error field="expiresOn" input-id="expiresOn" />
                            </div>
                            <x-forms.input-error field="startDate" input-id="expiresOn" />
                        @elseif ($panel === 'response')
                            @if ($response === Status::Accepted)
                                <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.offers.accept_hint') }}</p>
                            @endif
                            <div>
                                <x-forms.input-label for="responseNote" :value="__('recruitment-applicant.offers.response_note')" />
                                <x-forms.textarea id="responseNote" wire:model="responseNote" rows="2" />
                            </div>
                            <x-forms.input-error field="response" input-id="responseNote" />
                        @else
                            <p class="text-sm text-secondary-medium">{{ __('recruitment-applicant.offers.cancel_hint') }}</p>
                            <div>
                                <x-forms.input-label for="cancelReason" required :value="__('recruitment-applicant.interviews.cancel_reason')" />
                                <select id="cancelReason" wire:model.live="cancelReason" class="w-full rounded-control border border-secondary-light bg-white px-3 py-2 text-body">
                                    <option value="">{{ __('recruitment.choose') }}</option>
                                    @foreach ($cancellationReasons as $code)
                                        <option value="{{ $code }}">{{ __('recruitment-applicant.offers.cancellation_reasons.'.$code) }}</option>
                                    @endforeach
                                </select>
                                <x-forms.input-error field="cancelReason" input-id="cancelReason" />
                            </div>
                            <div>
                                <x-forms.input-label for="cancelNote" :required="$cancelReason === RecruitmentJobOfferService::REASON_OTHER" :value="__('recruitment-applicant.interviews.internal_note')" />
                                <x-forms.textarea id="cancelNote" wire:model="cancelNote" rows="2" />
                                <x-forms.input-error field="cancelNote" input-id="cancelNote" />
                            </div>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <x-primary-button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled" wire:target="{{ $action }}" class="!w-auto">{{ $actionLabel }}</x-primary-button>
                            <x-primary-button type="button" wire:click="closePanel" class="!bg-secondary-light !w-auto">{{ __('recruitment-applicant.cancel') }}</x-primary-button>
                        </div>
                    </div>
                @endif
            </x-modal.dialog>
        @endif
    </section>

    {{-- History: every version exactly as it was offered --}}
    <section class="rounded-surface border border-secondary-soft bg-white p-6">
        <h2 class="text-h4 font-semibold">{{ __('recruitment-applicant.offers.history') }}</h2>
        <ol class="mt-3 space-y-4">
            @foreach ($offer->versions as $version)
                <li class="border-s-2 border-secondary-soft ps-3 text-sm" wire:key="version-{{ $version->id }}">
                    <p class="font-medium">
                        v{{ $version->version_no }} &middot; {{ __('recruitment-applicant.offers.version_statuses.'.$version->status) }}
                        <span class="font-normal text-secondary-medium">
                            &middot; {{ $personName($version->creator) }} &middot; {{ $version->created_at?->format('Y-m-d H:i') }}
                            @if ($version->sent_at) &middot; {{ __('recruitment-applicant.offers.sent_at') }} {{ $version->sent_at->format('Y-m-d H:i') }} @endif
                        </span>
                    </p>
                    @if ($version->revision_reason && $canManage)
                        <p class="mt-1 text-secondary-medium">{{ __('recruitment-applicant.offers.revision_reason') }}: {{ $version->revision_reason }}</p>
                    @endif
                    @include('livewire.recruitment.offers.partials.terms', ['version' => $version, 'compact' => true, 'canSeeSalary' => auth()->user()->can('viewSalary', [$offer, $version])])
                </li>
            @endforeach
        </ol>
    </section>
</div>
